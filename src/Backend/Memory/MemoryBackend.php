<?php
/**
 * An in-memory backend.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Memory;

use SolrEngine\Contracts\Capabilities;
use SolrEngine\Contracts\Document;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\FacetBucket;
use SolrEngine\Contracts\FacetResult;
use SolrEngine\Contracts\IndexBackend;
use SolrEngine\Contracts\IndexStatus;
use SolrEngine\Contracts\LifecycleBackend;
use SolrEngine\Contracts\Result;
use SolrEngine\Contracts\SchemaBackend;
use SolrEngine\Contracts\SchemaContract;
use SolrEngine\Contracts\SchemaPlan;
use SolrEngine\Contracts\SearchBackend;
use SolrEngine\Contracts\SearchHit;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Contracts\Selector;
use SolrEngine\Contracts\Suggestion;
use SolrEngine\Contracts\Suggestions;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Contracts\WriteResult;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FilterRegistry;

/**
 * The second adapter, and the reason there is one.
 *
 * Phase 9's stated outcome is that a second adapter proves the abstraction. An
 * adapter for a service that does not exist proves nothing — it cannot be run,
 * so it cannot be wrong. This one runs, and it is held to every contract the
 * Solr adapter is, by the same conformance suite.
 *
 * What it proves, precisely: **every question the WordPress layer asks can be
 * answered from the domain model alone.** Nothing here parses Solr syntax,
 * because nothing here is given any — it receives a `SearchQuery`, reads its
 * terms, filters, facets, sort and scope as structured values, and answers.
 * If a future change to the WordPress layer cannot be satisfied without Solr
 * vocabulary, this class stops compiling, and that is the alarm.
 *
 * It also earns its place twice over:
 *
 * - It reports `is_manageable()` as false, which is the **managed backend** case
 *   §14.4 describes — "the Cloud adapter simply reports managed and the UI
 *   adapts". Before this existed, that method had no implementor that returned
 *   false and no caller that honoured it, so the path was untested prose.
 * - It lets the WordPress side be developed and tested with no Solr running,
 *   which is the difference between a contributor needing Docker and not.
 *
 * What it is not: a search engine. Matching is substring matching over the text
 * fields, ranking counts matches, and both are stated plainly rather than dressed
 * up. It holds documents in a PHP array for one request. Selecting it to serve a
 * real site would index everything and find nothing on the next request, which
 * is why it is registered only behind a constant.
 *
 * @since 0.10.0
 */
final class MemoryBackend implements SearchBackend, IndexBackend, SchemaBackend, LifecycleBackend {

	/**
	 * Identifier this backend registers under.
	 *
	 * @var string
	 */
	public const ID = 'memory';

	/**
	 * Documents held, keyed by document identifier.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $documents = array();

	/**
	 * Which filters may be applied, and to which fields.
	 *
	 * @var FilterRegistry
	 */
	private $filters;

	/**
	 * Which facets may be counted.
	 *
	 * @var FacetRegistry
	 */
	private $facets;

	/**
	 * Fields that hold searchable text.
	 *
	 * @var string[]
	 */
	private $text_fields;

	/**
	 * Constructor.
	 *
	 * Note what is not here: no transport, no endpoint, no credentials, no query
	 * translator. The two registries are the whole of what a backend needs from
	 * the layer above, which is the measurement this class exists to take.
	 *
	 * @param FilterRegistry|null $filters Registered filters.
	 * @param FacetRegistry|null  $facets  Registered facets.
	 */
	public function __construct( ?FilterRegistry $filters = null, ?FacetRegistry $facets = null ) {
		$this->filters     = $filters ?? new FilterRegistry();
		$this->facets      = $facets ?? new FacetRegistry();
		$this->text_fields = array(
			DocumentFields::TITLE,
			DocumentFields::EXCERPT,
			DocumentFields::CONTENT,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function ping(): Result {
		return Result::success();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Capabilities
	 */
	public function capabilities(): Capabilities {
		return new Capabilities(
			self::ID,
			// No engine version, because there is no separate engine. Reported
			// empty rather than borrowing the plugin's own version, which would
			// answer a question nobody asked with a number that means something
			// else.
			'',
			'managed',
			array(
				// Facets and suggestions are answered here, in PHP. The schema
				// is not manageable and never will be: a managed backend owns
				// its own, which is the case this backend exists to represent.
				'json_facets'     => true,
				'json_request'    => false,
				'schema_api'      => false,
				'aliases'         => false,
				'collections_api' => false,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SearchQuery $query What to search for.
	 * @return Result
	 */
	public function search( SearchQuery $query ): Result {
		$matched = array();

		foreach ( $this->documents as $document ) {
			if ( ! $this->is_visible_to( $document, $query ) ) {
				continue;
			}

			$score = $this->score_of( $document, $query );

			if ( null === $score ) {
				continue;
			}

			$matched[] = array(
				'document' => $document,
				'score'    => $score,
			);
		}

		$total = count( $matched );

		usort(
			$matched,
			function ( array $a, array $b ) use ( $query ): int {
				return $this->compare( $a, $b, $query->sort() );
			}
		);

		$facets = $this->count_facets( $matched, $query );
		$page   = $query->wants_documents()
			? array_slice( $matched, $query->offset(), $query->per_page() )
			: array();

		$hits = array();

		foreach ( $page as $entry ) {
			$document = $entry['document'];

			$hits[] = new SearchHit(
				$this->ref_of( $document ),
				(float) $entry['score'],
				$document
			);
		}

		$results = new SearchResults( $hits, $total, $query, 0, 'standard' );

		return Result::success( array() === $facets ? $results : $results->with_facets( $facets ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SuggestQuery $query What has been typed.
	 * @return Result
	 */
	public function suggest( SuggestQuery $query ): Result {
		if ( ! $query->is_long_enough() ) {
			return Result::success( new Suggestions( array(), $query->prefix() ) );
		}

		$prefix = $this->fold( $query->prefix() );
		$items  = array();

		foreach ( $this->documents as $document ) {
			if ( (int) ( $document[ DocumentFields::SITE ] ?? 0 ) !== $query->site_id() ) {
				continue;
			}

			if ( 'public' !== (string) ( $document[ DocumentFields::VISIBILITY ] ?? '' ) ) {
				continue;
			}

			$title = (string) ( $document[ DocumentFields::TITLE ] ?? '' );

			if ( '' === $title || false === strpos( $this->fold( $title ), $prefix ) ) {
				continue;
			}

			$items[] = new Suggestion(
				$title,
				(string) ( $document[ DocumentFields::URL ] ?? '' ),
				$this->ref_of( $document ),
				(string) ( $document[ DocumentFields::POST_TYPE ] ?? '' )
			);

			if ( count( $items ) >= $query->limit() ) {
				break;
			}
		}

		return Result::success( new Suggestions( $items, $query->prefix() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function status(): Result {
		return Result::success(
			new IndexStatus(
				true,
				count( $this->documents ),
				// Whatever contract this build declares. A managed backend is by
				// definition never behind: it accepts what the document model
				// produces, so the version it satisfies is the one being written.
				(int) ( defined( 'SOLRENGINE_SCHEMA_CONTRACT_VERSION' ) ? SOLRENGINE_SCHEMA_CONTRACT_VERSION : 0 ),
				array( 'collection' => 'memory' )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Document[] $documents Documents to write.
	 * @return Result
	 */
	public function write_batch( array $documents ): Result {
		$written = 0;

		foreach ( $documents as $document ) {
			// Keyed on the document identifier, so a write is an upsert and
			// reindexing is idempotent. The contract requires that of every
			// implementation, and a second one getting it wrong is how the
			// requirement would be discovered to be untested.
			$this->documents[ $document->id() ] = $document->to_payload();

			++$written;
		}

		return Result::success( new WriteResult( $written ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function commit(): Result {
		// Writes are visible the moment they are made, so there is nothing to
		// do. Reported as success rather than as unsupported: the caller asked
		// for the documents to be visible, and they are.
		return Result::success();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DocumentRef[] $refs References to remove.
	 * @return Result
	 */
	public function delete( array $refs ): Result {
		$removed = 0;

		foreach ( $refs as $ref ) {
			// Deleting something absent is a success, not a failure: the
			// desired end state is "absent", and deletion is called on trash,
			// on status transitions and during reconciliation.
			if ( isset( $this->documents[ $ref->document_id() ] ) ) {
				unset( $this->documents[ $ref->document_id() ] );

				++$removed;
			}
		}

		return Result::success( new WriteResult( $removed ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Selector $selector Which documents to remove.
	 * @return Result
	 */
	public function delete_by_selector( Selector $selector ): Result {
		if ( $selector->is_unconstrained() ) {
			return Result::failure(
				'unconstrained_selector',
				'Refusing to delete every document. Constrain the selector.'
			);
		}

		$removed = 0;

		foreach ( $this->documents as $id => $document ) {
			if ( ! $this->matches_selector( $document, $selector ) ) {
				continue;
			}

			unset( $this->documents[ $id ] );

			++$removed;
		}

		return Result::success( new WriteResult( $removed ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_manageable(): bool {
		// The managed case. A backend that owns its own schema reports this, and
		// the admin screens are expected to say so rather than offering a plan
		// they cannot apply.
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function applied_version(): Result {
		return Result::success(
			(int) ( defined( 'SOLRENGINE_SCHEMA_CONTRACT_VERSION' ) ? SOLRENGINE_SCHEMA_CONTRACT_VERSION : 0 )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function describe(): Result {
		return Result::failure(
			'managed',
			'This backend manages its own schema. There is nothing to inspect from here.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SchemaContract $contract Contract to satisfy.
	 * @return Result
	 */
	public function plan( SchemaContract $contract ): Result {
		// An empty plan, not a failure. The contract is already satisfied,
		// because a managed backend accepts whatever the document model
		// produces — that is what being managed means.
		return Result::success( new SchemaPlan( $contract->version() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SchemaPlan $plan Plan to apply.
	 * @return Result
	 */
	public function apply( SchemaPlan $plan ): Result {
		unset( $plan );

		return Result::failure(
			'managed',
			'This backend manages its own schema and does not accept changes from here.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SchemaContract $contract Contract to render.
	 * @return string
	 */
	public function export( SchemaContract $contract ): string {
		unset( $contract );

		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function rebuild_style(): string {
		return self::STYLE_NONE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function can_create_indexes(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function list_indexes(): Result {
		return Result::success( array( 'memory' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Name for the new index.
	 * @return Result
	 */
	public function create_index( string $name ): Result {
		unset( $name );

		return Result::failure( 'unsupported', 'This backend has one index and does not create others.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Configured index name.
	 * @return Result
	 */
	public function resolve_index( string $name ): Result {
		return Result::success( $name );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name   Live name.
	 * @param string $target Index to point at.
	 * @return Result
	 */
	public function point_index( string $name, string $target ): Result {
		unset( $name, $target );

		return Result::failure( 'unsupported', 'This backend has one index.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Index to delete.
	 * @return Result
	 */
	public function drop_index( string $name ): Result {
		unset( $name );

		return Result::failure( 'unsupported', 'This backend has one index.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Index to count.
	 * @return Result
	 */
	public function count_documents( string $name ): Result {
		unset( $name );

		return Result::success( count( $this->documents ) );
	}

	/**
	 * Whether a document may be shown for a query at all.
	 *
	 * The security filters, applied here rather than assumed. The Solr adapter
	 * gets these as filter queries the translator builds; this one has to build
	 * them itself, which is the point — if an invariant could not be expressed
	 * from the domain model, it would be unenforceable in any backend but Solr.
	 *
	 * @param array<string,mixed> $document Stored document.
	 * @param SearchQuery         $query    Query being answered.
	 * @return bool
	 */
	private function is_visible_to( array $document, SearchQuery $query ): bool {
		if ( (int) ( $document[ DocumentFields::SITE ] ?? 0 ) !== $query->site_id() ) {
			return false;
		}

		if ( 'public' !== (string) ( $document[ DocumentFields::VISIBILITY ] ?? '' ) ) {
			return false;
		}

		$post_types = $query->post_types();

		if ( array() !== $post_types && ! in_array( (string) ( $document[ DocumentFields::POST_TYPE ] ?? '' ), $post_types, true ) ) {
			return false;
		}

		if ( ! $this->matches_language( $document, $query->language() ) ) {
			return false;
		}

		foreach ( $query->filters() as $key => $values ) {
			if ( ! $this->matches_filter( $document, (string) $key, (array) $values, $query->filter_mode( (string) $key ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a document satisfies a language scope.
	 *
	 * Tolerant in the same way the Solr clause is: a document carrying no
	 * language matches every scope. A document indexed before languages existed
	 * must not vanish from results the day a multilingual plugin is activated.
	 *
	 * @param array<string,mixed> $document Stored document.
	 * @param string              $language Requested language, or empty.
	 * @return bool
	 */
	private function matches_language( array $document, string $language ): bool {
		if ( '' === $language ) {
			return true;
		}

		$of_document = (string) ( $document[ DocumentFields::LANG ] ?? '' );

		return '' === $of_document || $of_document === $language;
	}

	/**
	 * Whether a document satisfies one registered filter.
	 *
	 * @param array<string,mixed> $document Stored document.
	 * @param string              $key      Filter key.
	 * @param array<int,mixed>    $values   Values to match.
	 * @param string              $mode     "or" for any value, "and" for all.
	 * @return bool
	 */
	private function matches_filter( array $document, string $key, array $values, string $mode ): bool {
		$definition = $this->filters->definition_of( $key );

		if ( null === $definition || array() === $values ) {
			// An unregistered key applies nothing, which is what the Solr
			// adapter does with one too. Rejection of unknown keys happens
			// earlier, where there is a request to fail.
			return true;
		}

		$held = $document[ $definition['field'] ] ?? null;
		$held = array_map( 'strval', is_array( $held ) ? $held : array( $held ) );

		$matches = 0;

		foreach ( $values as $value ) {
			if ( in_array( (string) $value, $held, true ) ) {
				++$matches;
			}
		}

		$satisfied = 'and' === $mode ? count( $values ) === $matches : $matches > 0;

		// An excluding filter removes matches rather than requiring them, and
		// therefore also keeps every document that does not carry the field.
		return empty( $definition['exclude'] ) ? $satisfied : ! $satisfied;
	}

	/**
	 * Score a document against a query, or null when it does not match.
	 *
	 * Substring counting, stated plainly. It is not relevance and does not
	 * pretend to be; it is enough to order results deterministically so that a
	 * test asserting "the title match comes first" means something.
	 *
	 * @param array<string,mixed> $document Stored document.
	 * @param SearchQuery         $query    Query being answered.
	 * @return float|null
	 */
	private function score_of( array $document, SearchQuery $query ): ?float {
		if ( ! $query->has_terms() ) {
			return 1.0;
		}

		$needle = $this->fold( $query->terms() );
		$score  = 0.0;
		$weight = count( $this->text_fields );

		foreach ( $this->text_fields as $field ) {
			$value = $document[ $field ] ?? '';

			if ( is_scalar( $value ) && false !== strpos( $this->fold( (string) $value ), $needle ) ) {
				// Earlier fields weigh more, so a title match outranks a content
				// match, which is the one ranking property callers rely on.
				$score += $weight;
			}

			--$weight;
		}

		return $score > 0.0 ? $score : null;
	}

	/**
	 * Order two matches.
	 *
	 * @param array{document:array<string,mixed>,score:float} $a    First match.
	 * @param array{document:array<string,mixed>,score:float} $b    Second match.
	 * @param string                                          $sort Sort key.
	 * @return int
	 */
	private function compare( array $a, array $b, string $sort ): int {
		switch ( $sort ) {
			case 'date_desc':
				return strcmp(
					(string) ( $b['document'][ DocumentFields::DATE ] ?? '' ),
					(string) ( $a['document'][ DocumentFields::DATE ] ?? '' )
				);

			case 'date_asc':
				return strcmp(
					(string) ( $a['document'][ DocumentFields::DATE ] ?? '' ),
					(string) ( $b['document'][ DocumentFields::DATE ] ?? '' )
				);

			case 'title_asc':
				return strcmp(
					(string) ( $a['document'][ DocumentFields::TITLE_SORT ] ?? '' ),
					(string) ( $b['document'][ DocumentFields::TITLE_SORT ] ?? '' )
				);

			case 'title_desc':
				return strcmp(
					(string) ( $b['document'][ DocumentFields::TITLE_SORT ] ?? '' ),
					(string) ( $a['document'][ DocumentFields::TITLE_SORT ] ?? '' )
				);

			default:
				return $b['score'] <=> $a['score'];
		}
	}

	/**
	 * Count the facets a query asked for.
	 *
	 * Counted over everything that matched, before pagination — the same
	 * property Solr's faceting has, and the one that makes a count mean "results
	 * you would get" rather than "results on this page".
	 *
	 * @param array<int,array{document:array<string,mixed>,score:float}> $matched Matches.
	 * @param SearchQuery                                                $query   Query.
	 * @return FacetResult[]
	 */
	private function count_facets( array $matched, SearchQuery $query ): array {
		$results = array();

		foreach ( array_keys( $query->facets() ) as $key ) {
			$definition = $this->facets->get( (string) $key );

			if ( null === $definition ) {
				continue;
			}

			$counts = array();

			foreach ( $matched as $entry ) {
				$held = $entry['document'][ $definition->field() ] ?? null;

				foreach ( is_array( $held ) ? $held : array( $held ) as $value ) {
					if ( null === $value || '' === $value ) {
						continue;
					}

					$value            = (string) $value;
					$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
				}
			}

			arsort( $counts );

			$buckets = array();

			foreach ( array_slice( $counts, 0, $definition->limit(), true ) as $value => $count ) {
				$buckets[] = new FacetBucket( (string) $value, (int) $count );
			}

			$results[] = new FacetResult(
				(string) $key,
				$definition->label(),
				$definition->type(),
				$buckets
			);
		}

		return $results;
	}

	/**
	 * Whether a document matches a selector.
	 *
	 * @param array<string,mixed> $document Stored document.
	 * @param Selector            $selector Selector.
	 * @return bool
	 */
	private function matches_selector( array $document, Selector $selector ): bool {
		if ( null !== $selector->site_id() && (int) ( $document[ DocumentFields::SITE ] ?? 0 ) !== $selector->site_id() ) {
			return false;
		}

		if ( '' !== $selector->object_type() && (string) ( $document[ DocumentFields::OBJECT_TYPE ] ?? '' ) !== $selector->object_type() ) {
			return false;
		}

		if ( '' !== $selector->post_type() && (string) ( $document[ DocumentFields::POST_TYPE ] ?? '' ) !== $selector->post_type() ) {
			return false;
		}

		if ( $selector->older_than_schema() > 0
			&& (int) ( $document[ DocumentFields::SCHEMA_VERSION ] ?? 0 ) >= $selector->older_than_schema() ) {
			return false;
		}

		return true;
	}

	/**
	 * The reference a stored document was written under.
	 *
	 * @param array<string,mixed> $document Stored document.
	 * @return DocumentRef
	 */
	private function ref_of( array $document ): DocumentRef {
		return new DocumentRef(
			(int) ( $document[ DocumentFields::SITE ] ?? 0 ),
			(string) ( $document[ DocumentFields::OBJECT_TYPE ] ?? 'post' ),
			(int) ( $document[ DocumentFields::OBJECT_ID ] ?? 0 )
		);
	}

	/**
	 * Lower-case a string for comparison.
	 *
	 * @param string $value Value to fold.
	 * @return string
	 */
	private function fold( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}
}
