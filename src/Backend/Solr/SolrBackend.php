<?php
/**
 * Apache Solr backend.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

use SolrEngine\Contracts\Capabilities;
use SolrEngine\Contracts\Document;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\IndexBackend;
use SolrEngine\Contracts\IndexStatus;
use SolrEngine\Contracts\LifecycleBackend;
use SolrEngine\Contracts\Result;
use SolrEngine\Contracts\SchemaBackend;
use SolrEngine\Contracts\SchemaContract;
use SolrEngine\Contracts\SchemaPlan;
use SolrEngine\Contracts\SearchBackend;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\Suggestions;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Contracts\Selector;
use SolrEngine\Contracts\WriteResult;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\SuggestionParser;
use SolrEngine\Search\SuggestTranslator;
use SolrEngine\Search\ResponseParser;
use SolrEngine\Schema\SchemaExporter;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Schema\SchemaStateFactory;

/**
 * Talks to a customer-managed Apache Solr.
 *
 * Implements all three backend contracts. They stay separate as interfaces
 * because a future backend may well not provide all three — a managed service
 * owns its own schema and would report schema management as unavailable — but
 * for Solr one class covers them without artificial splitting.
 *
 * Nothing here throws. Every method returns a Result, so an unreachable Solr
 * degrades rather than breaking WordPress.
 *
 * @since 0.1.0
 */
final class SolrBackend implements SearchBackend, IndexBackend, SchemaBackend, LifecycleBackend {

	/**
	 * Transport.
	 *
	 * @var HttpTransport
	 */
	private $http;

	/**
	 * Schema planner.
	 *
	 * @var SchemaPlanner
	 */
	private $planner;

	/**
	 * Schema exporter.
	 *
	 * @var SchemaExporter
	 */
	private $exporter;

	/**
	 * Memoised capability report.
	 *
	 * @var Capabilities|null
	 */
	private $capabilities = null;

	/**
	 * Memoised rebuild strategy.
	 *
	 * @var string
	 */
	private $rebuild_style = '';

	/**
	 * Memoised live schema.
	 *
	 * @var Result|null
	 */
	private $schema = null;

	/**
	 * How long Solr may wait before making writes visible, in milliseconds.
	 *
	 * @var int
	 */
	private $commit_within_ms;

	/**
	 * Builds Solr parameters from a structured query.
	 *
	 * @var QueryTranslator
	 */
	private $translator;

	/**
	 * Reads Solr responses.
	 *
	 * @var ResponseParser
	 */
	private $parser;

	/**
	 * Builds autocomplete requests.
	 *
	 * @var SuggestTranslator
	 */
	private $suggester;

	/**
	 * Constructor.
	 *
	 * @param HttpTransport     $http             Transport.
	 * @param SchemaPlanner     $planner          Schema planner.
	 * @param SchemaExporter    $exporter         Schema exporter.
	 * @param int               $commit_within_ms Commit window in milliseconds.
	 * @param QueryTranslator   $translator       Query translator.
	 * @param ResponseParser    $parser           Response parser.
	 * @param SuggestTranslator $suggester        Autocomplete translator.
	 */
	public function __construct(
		HttpTransport $http,
		SchemaPlanner $planner,
		SchemaExporter $exporter,
		int $commit_within_ms = 60000,
		?QueryTranslator $translator = null,
		?ResponseParser $parser = null,
		?SuggestTranslator $suggester = null
	) {
		$this->http             = $http;
		$this->planner          = $planner;
		$this->exporter         = $exporter;
		$this->commit_within_ms = max( 0, $commit_within_ms );
		$this->translator       = $translator ?? new QueryTranslator(
			new \SolrEngine\Search\RelevanceProfile(),
			\SolrEngine\Search\FilterRegistry::defaults()
		);
		$this->parser           = $parser ?? new ResponseParser();
		$this->suggester        = $suggester ?? new SuggestTranslator();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'solr';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function ping(): Result {
		$result = $this->http->get( '/admin/ping' );

		if ( $result->is_failure() ) {
			return $result;
		}

		$body = $result->value( array() );

		if ( isset( $body['status'] ) && 'OK' !== $body['status'] ) {
			return Result::failure( 'not_ok', 'Solr reported the collection as unhealthy.' );
		}

		return Result::success();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Detected once per request and memoised. The prototype issued an admin
	 * API call on every request that touched the client, which is a round trip
	 * per page load.
	 *
	 * @return Capabilities
	 */
	public function capabilities(): Capabilities {
		if ( null !== $this->capabilities ) {
			return $this->capabilities;
		}

		$version = '';
		$mode    = 'unknown';

		$system = $this->http->get_admin( '/admin/info/system' );

		if ( $system->is_ok() ) {
			$body    = $system->value( array() );
			$version = (string) ( $body['lucene']['solr-spec-version'] ?? '' );
			$mode    = ! empty( $body['mode'] ) ? (string) $body['mode'] : 'user-managed';
		}

		$schema = $this->describe();
		$major  = (int) $version;

		$this->capabilities = new Capabilities(
			'solr',
			$version,
			$mode,
			array(
				// The JSON Facet API and the JSON Request API have been
				// present throughout the supported range (9.10+, 10.x), so
				// they are gated on the version being known rather than probed.
				'json_facets'     => $major >= 9,
				'json_request'    => $major >= 9,
				'schema_api'      => $schema->is_ok() && $schema->value()->is_mutable(),
				// Aliases are a SolrCloud feature. In user-managed mode a
				// zero-downtime reindex switches SolrEngine's own configured
				// core instead, which avoids CoreAdmin SWAP entirely.
				'aliases'         => 'solrcloud' === strtolower( $mode ),
				'collections_api' => 'solrcloud' === strtolower( $mode ),
			)
		);

		return $this->capabilities;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SearchQuery $query What to search for.
	 * @return Result
	 */
	public function search( SearchQuery $query ): Result {
		$result = $this->http->get( '/select', $this->translator->translate( $query, 'standard' ) );

		if ( $result->is_failure() ) {
			return $result;
		}

		$results = $this->parser->parse( (array) $result->value( array() ), $query );

		if ( ! $results->is_empty() || ! $query->has_terms() ) {
			return Result::success( $results );
		}

		// Nothing matched. Retry with fuzziness rather than making every query
		// fuzzy: always-on fuzziness costs precision for every visitor in order
		// to rescue the few queries that need it, and it is slower.
		foreach ( array( 'fuzzy', 'loosened' ) as $strategy ) {
			$retry = $this->http->get( '/select', $this->translator->translate( $query, $strategy ) );

			if ( $retry->is_failure() ) {
				break;
			}

			$widened = $this->parser->parse( (array) $retry->value( array() ), $query );

			if ( ! $widened->is_empty() ) {
				// The strategy is reported so a theme can say "no exact
				// matches, showing similar results" rather than pretending
				// this is what was asked for.
				return Result::success( $widened->with_strategy( $strategy ) );
			}
		}

		return Result::success( $results );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SuggestQuery $query What has been typed.
	 * @return Result
	 */
	public function suggest( SuggestQuery $query ): Result {
		if ( ! $query->is_long_enough() ) {
			// Answered without a request. One character matches most of the
			// index, tells the visitor nothing, and costs a round trip on every
			// keystroke.
			return Result::success( new Suggestions( array(), $query->prefix() ) );
		}

		$result = $this->http->get( '/select', $this->suggester->translate( $query ) );

		if ( $result->is_failure() ) {
			return $result;
		}

		// Parsed by the shared reader rather than here, so the shape of a
		// suggestion is defined in one place. See {@see SuggestionParser}.
		return Result::success(
			( new SuggestionParser() )->parse(
				(array) $result->value( array() ),
				$query->site_id(),
				$query->prefix()
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function status(): Result {
		$result = $this->http->get(
			'/select',
			array(
				'q'    => '*:*',
				'rows' => 0,
			)
		);

		if ( $result->is_failure() ) {
			return $result;
		}

		$body  = $result->value( array() );
		$found = (int) ( $body['response']['numFound'] ?? 0 );

		$version = $this->applied_version();

		return Result::success(
			new IndexStatus(
				true,
				$found,
				$version->is_ok() ? (int) $version->value( 0 ) : 0,
				array( 'collection' => $this->http->endpoint()->collection() )
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
		if ( array() === $documents ) {
			return Result::success( new WriteResult( 0 ) );
		}

		$payloads = array();

		foreach ( $documents as $document ) {
			$payloads[] = $document->to_payload();
		}

		$result = $this->http->post(
			'/update',
			$payloads,
			// Never commit=true. A hard commit per batch is what made the
			// prototype slow enough to be unusable; commitWithin lets Solr
			// decide when to make the work visible.
			array( 'commitWithin' => $this->commit_within_ms )
		);

		if ( $result->is_failure() ) {
			return $result;
		}

		$body = $result->value( array() );

		return Result::success(
			new WriteResult(
				count( $payloads ),
				array(),
				isset( $body['responseHeader']['QTime'] ) ? (int) $body['responseHeader']['QTime'] : null
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function commit(): Result {
		// openSearcher is left to Solr's default, which is true: the point of
		// this call is that the documents become searchable, so a commit that
		// did not open a searcher would satisfy the letter of the request and
		// none of its purpose. softCommit is not used either — a soft commit
		// makes documents visible without making them durable, and the caller is
		// about to count them and decide whether to switch traffic over.
		return $this->http->post( '/update', array( 'commit' => new \stdClass() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Selector $selector Which documents to remove.
	 * @return Result
	 */
	public function delete_by_selector( Selector $selector ): Result {
		if ( $selector->is_unconstrained() ) {
			// Deleting everything must be an explicit decision, never something
			// reachable by forgetting to set a field on a selector.
			return Result::failure(
				'unconstrained_selector',
				'Refusing to delete every document. Constrain the selector.'
			);
		}

		$clauses = array();

		if ( null !== $selector->site_id() ) {
			$clauses[] = 'se_i_site:' . (int) $selector->site_id();
		}

		if ( '' !== $selector->object_type() ) {
			$clauses[] = 'se_s_object_type:' . $this->escape_term( $selector->object_type() );
		}

		if ( '' !== $selector->post_type() ) {
			$clauses[] = 'se_s_post_type:' . $this->escape_term( $selector->post_type() );
		}

		if ( $selector->older_than_schema() > 0 ) {
			$clauses[] = 'se_i_schema_version:[* TO ' . ( (int) $selector->older_than_schema() - 1 ) . ']';
		}

		$result = $this->http->post(
			'/update',
			array( 'delete' => array( 'query' => implode( ' AND ', $clauses ) ) ),
			array( 'commitWithin' => 5000 )
		);

		return $result->is_failure() ? $result : Result::success( new WriteResult( 0 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DocumentRef[] $refs References to remove.
	 * @return Result
	 */
	public function delete( array $refs ): Result {
		if ( array() === $refs ) {
			return Result::success( new WriteResult( 0 ) );
		}

		$ids = array();

		foreach ( $refs as $ref ) {
			$ids[] = $ref->document_id();
		}

		$result = $this->http->post(
			'/update',
			array( 'delete' => $ids ),
			// Removal from results should feel prompt, but a hard commit per
			// call is the prototype's mistake. commitWithin lets Solr batch.
			array( 'commitWithin' => 5000 )
		);

		if ( $result->is_failure() ) {
			return $result;
		}

		$body = $result->value( array() );

		return Result::success(
			new WriteResult( count( $ids ), array(), isset( $body['responseHeader']['QTime'] ) ? (int) $body['responseHeader']['QTime'] : null )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_manageable(): bool {
		$state = $this->describe();

		return $state->is_ok() && $state->value()->is_mutable();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function applied_version(): Result {
		$state = $this->describe();

		if ( $state->is_failure() ) {
			return $state;
		}

		// The schema itself does not record a version, so the presence of the
		// fields a contract requires is what identifies it. Phase 1 only has
		// version 1, so this is a presence check; later contracts will compare
		// against each version's field set in turn.
		$owned = $state->value()->owned_field_names();

		return Result::success( array() === $owned ? 0 : 1 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function describe(): Result {
		if ( null !== $this->schema ) {
			return $this->schema;
		}

		// Memoised for the request. The schema screen asks for the plan, the
		// applied version and the fingerprint, each of which needs the live
		// schema, and reading it costs two round trips — one for the schema and
		// one for the config overlay that says whether it is mutable. Nothing
		// but apply() can change it mid-request, and apply() forgets this.
		$result = $this->http->get( '/schema' );

		if ( $result->is_failure() ) {
			return $result;
		}

		$this->schema = Result::success(
			( new SchemaStateFactory() )->from_response(
				(array) $result->value( array() ),
				$this->is_schema_mutable()
			)
		);

		return $this->schema;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SchemaContract $contract Contract to satisfy.
	 * @return Result
	 */
	public function plan( SchemaContract $contract ): Result {
		$state = $this->describe();

		if ( $state->is_failure() ) {
			return $state;
		}

		return Result::success( $this->planner->plan( $contract, $state->value() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SchemaPlan $plan Plan to apply.
	 * @return Result
	 */
	public function apply( SchemaPlan $plan ): Result {
		if ( ! $plan->is_additive() ) {
			// Defence in depth. The planner only emits additive commands, but
			// this is the last gate before a customer's schema is modified, so
			// it re-checks rather than trusting its caller.
			return Result::failure(
				'not_additive',
				'The plan contains a command SolrEngine is not permitted to issue.'
			);
		}

		if ( ! $plan->is_applicable() ) {
			return Result::failure(
				'not_applicable',
				array() === $plan->blockers()
					? 'There is nothing to apply.'
					: implode( ' ', $plan->blockers() )
			);
		}

		$applied = 0;

		// The memoised schema is about to stop being true.
		$this->schema = null;

		foreach ( $plan->operations() as $operation ) {
			// One command per request. A single batched request would be
			// faster and would leave a half-applied schema with no record of
			// where it stopped; this way the count is always accurate.
			$result = $this->http->post( '/schema', array( $operation->command() => $operation->payload() ) );

			if ( $result->is_failure() ) {
				return Result::failure(
					'apply_failed',
					sprintf(
						'Stopped after %d of %d changes. %s failed: %s',
						$applied,
						count( $plan->operations() ),
						$operation->description(),
						$result->message()
					),
					array(
						'applied'   => $applied,
						'operation' => $operation->command(),
					)
				);
			}

			++$applied;
		}

		return Result::success( $applied );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SchemaContract $contract Contract to render.
	 * @return string
	 */
	public function export( SchemaContract $contract ): string {
		return $this->exporter->to_xml( $contract );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Reported from what the server actually has, not from its mode. SolrCloud
	 * is a necessary condition for an alias swap but not a sufficient one: Solr
	 * refuses CREATEALIAS when the name is held by a real collection, which is
	 * the case on every site that has not deliberately set an alias up. Claiming
	 * the alias strategy there would produce a rebuild that succeeds all the way
	 * to the switch and then fails at it, with a freshly built index nobody is
	 * reading.
	 *
	 * @return string
	 */
	public function rebuild_style(): string {
		if ( '' !== $this->rebuild_style ) {
			return $this->rebuild_style;
		}

		$configured = $this->http->endpoint()->collection();

		if ( '' === $configured ) {
			$this->rebuild_style = self::STYLE_NONE;

			return $this->rebuild_style;
		}

		if ( ! $this->capabilities()->supports( 'aliases' ) ) {
			// A user-managed server. Switching the core SolrEngine is
			// configured with is the strategy the plan chooses here, in
			// preference to a CoreAdmin SWAP whose safety varies by mode.
			$this->rebuild_style = self::STYLE_POINTER;

			return $this->rebuild_style;
		}

		$resolved = $this->resolve_index( $configured );

		$this->rebuild_style = $resolved->is_ok() && (string) $resolved->value( '' ) !== $configured
			? self::STYLE_ALIAS
			: self::STYLE_POINTER;

		return $this->rebuild_style;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Result
	 */
	public function list_indexes(): Result {
		if ( $this->capabilities()->supports( 'collections_api' ) ) {
			$result = $this->http->get_admin( '/admin/collections', array( 'action' => 'LIST' ) );

			if ( $result->is_failure() ) {
				return $result;
			}

			$body = (array) $result->value( array() );

			return Result::success( array_values( array_map( 'strval', (array) ( $body['collections'] ?? array() ) ) ) );
		}

		$result = $this->http->get_admin(
			'/admin/cores',
			array(
				'action'    => 'STATUS',
				// Without this, Solr reads and reports index statistics for
				// every core, which on a large index is a slow answer to the
				// question "what cores are there".
				'indexInfo' => false,
			)
		);

		if ( $result->is_failure() ) {
			return $result;
		}

		$body = (array) $result->value( array() );

		return Result::success( array_values( array_map( 'strval', array_keys( (array) ( $body['status'] ?? array() ) ) ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only in SolrCloud mode. Creating a core on a user-managed Solr means
	 * writing a configuration directory on the server's filesystem, which is not
	 * something a WordPress plugin should be reaching through HTTP to do.
	 *
	 * @return bool
	 */
	public function can_create_indexes(): bool {
		return $this->capabilities()->supports( 'collections_api' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Name for the new collection.
	 * @return Result
	 */
	public function create_index( string $name ): Result {
		if ( ! $this->can_create_indexes() ) {
			return Result::failure(
				'unsupported',
				'This Solr is not running in SolrCloud mode, so SolrEngine cannot create a core for you. Create one on the server and name it here.'
			);
		}

		if ( ! CollectionName::is_valid( $name ) ) {
			return Result::failure( 'invalid_collection', 'That is not a usable collection name.' );
		}

		$live = $this->collection_shape();

		if ( $live->is_failure() ) {
			return $live;
		}

		$shape = (array) $live->value( array() );

		if ( '' === (string) $shape['config'] ) {
			// Without the live collection's configset the replacement would be
			// built on Solr's default one: same fields, different analysis, and
			// therefore different relevance the moment it went live. Refused
			// rather than guessed.
			return Result::failure(
				'unknown_config',
				'SolrEngine could not read which configset your collection uses, and will not create a replacement on a different one.'
			);
		}

		return $this->http->get_admin(
			'/admin/collections',
			array(
				'action'                => 'CREATE',
				'name'                  => $name,
				'collection.configName' => (string) $shape['config'],
				'numShards'             => max( 1, (int) $shape['shards'] ),
				'replicationFactor'     => max( 1, (int) $shape['replicas'] ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Configured collection or alias name.
	 * @return Result
	 */
	public function resolve_index( string $name ): Result {
		if ( ! CollectionName::is_valid( $name ) ) {
			return Result::failure( 'invalid_collection', 'That is not a usable collection name.' );
		}

		if ( ! $this->capabilities()->supports( 'collections_api' ) ) {
			// A core name resolves to itself. There are no aliases outside
			// SolrCloud, so there is nothing to look up.
			return Result::success( $name );
		}

		$result = $this->http->get_admin( '/admin/collections', array( 'action' => 'CLUSTERSTATUS' ) );

		if ( $result->is_failure() ) {
			return $result;
		}

		$body    = (array) $result->value( array() );
		$aliases = (array) ( $body['cluster']['aliases'] ?? array() );

		if ( ! isset( $aliases[ $name ] ) ) {
			return Result::success( $name );
		}

		// An alias may list several collections. SolrEngine only ever creates
		// single-target aliases, so the first is the one it put there; a
		// multi-target alias somebody else made is reported as its first
		// member rather than pretended to be understood.
		$targets = array_filter( array_map( 'trim', explode( ',', (string) $aliases[ $name ] ) ) );

		return Result::success( array() === $targets ? $name : (string) reset( $targets ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name   Alias name.
	 * @param string $target Collection the alias should resolve to.
	 * @return Result
	 */
	public function point_index( string $name, string $target ): Result {
		if ( ! CollectionName::is_valid( $name ) || ! CollectionName::is_valid( $target ) ) {
			return Result::failure( 'invalid_collection', 'That is not a usable collection name.' );
		}

		if ( ! $this->capabilities()->supports( 'aliases' ) ) {
			return Result::failure(
				'unsupported',
				'Aliases are a SolrCloud feature. On this server SolrEngine switches the collection it is configured with instead.'
			);
		}

		return $this->http->get_admin(
			'/admin/collections',
			array(
				'action'      => 'CREATEALIAS',
				'name'        => $name,
				'collections' => $target,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Collection to delete.
	 * @return Result
	 */
	public function drop_index( string $name ): Result {
		if ( ! CollectionName::is_valid( $name ) ) {
			return Result::failure( 'invalid_collection', 'That is not a usable collection name.' );
		}

		if ( $name === $this->http->endpoint()->collection() ) {
			// The last line of defence. Everything above this refuses to offer
			// the live index for deletion, and this refuses to do it anyway.
			return Result::failure( 'is_live', 'That is the collection SolrEngine is using. It will not delete it.' );
		}

		if ( ! $this->capabilities()->supports( 'collections_api' ) ) {
			return Result::failure(
				'unsupported',
				'SolrEngine does not delete cores on a user-managed Solr. Remove it on the server when you no longer need it.'
			);
		}

		return $this->http->get_admin(
			'/admin/collections',
			array(
				'action' => 'DELETE',
				'name'   => $name,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Collection to count.
	 * @return Result
	 */
	public function count_documents( string $name ): Result {
		$result = $this->http->get_in(
			$name,
			'/select',
			array(
				'q'    => '*:*',
				'rows' => 0,
			)
		);

		if ( $result->is_failure() ) {
			return $result;
		}

		$body = (array) $result->value( array() );

		return Result::success( (int) ( $body['response']['numFound'] ?? 0 ) );
	}

	/**
	 * The live collection's configset, shard count and replication factor.
	 *
	 * @return Result Success carrying config, shards and replicas.
	 */
	private function collection_shape(): Result {
		$collection = $this->http->endpoint()->collection();

		$result = $this->http->get_admin(
			'/admin/collections',
			array(
				'action'     => 'CLUSTERSTATUS',
				'collection' => $collection,
			)
		);

		if ( $result->is_failure() ) {
			return $result;
		}

		$body = (array) $result->value( array() );

		// CLUSTERSTATUS resolves an alias, so the entry is keyed by the real
		// collection name rather than by the name that was asked for.
		$collections = (array) ( $body['cluster']['collections'] ?? array() );
		$live        = (array) ( $collections[ $collection ] ?? reset( $collections ) );

		$shards = (array) ( $live['shards'] ?? array() );
		$first  = is_array( reset( $shards ) ) ? (array) reset( $shards ) : array();

		return Result::success(
			array(
				'config'   => (string) ( $live['configName'] ?? '' ),
				'shards'   => max( 1, count( $shards ) ),
				// replicationFactor is absent on collections created before it
				// became a stored property, so the replicas actually present on
				// one shard are the fallback.
				'replicas' => (int) ( $live['replicationFactor'] ?? count( (array) ( $first['replicas'] ?? array() ) ) ),
			)
		);
	}

	/**
	 * Whether the Schema API will accept changes.
	 *
	 * Determined by asking rather than by assuming. A schema is mutable only
	 * with ManagedIndexSchemaFactory, and in Solr 10 administrative access also
	 * requires authorisation, so credentials that can read may still not write.
	 *
	 * @return bool
	 */
	private function is_schema_mutable(): bool {
		$result = $this->http->get( '/config/overlay' );

		if ( $result->is_failure() ) {
			return false;
		}

		$body = $result->value( array() );

		if ( isset( $body['overlay']['schemaFactory']['class'] ) ) {
			return false !== stripos( (string) $body['overlay']['schemaFactory']['class'], 'Managed' );
		}

		// No overlay entry means the configset's own schemaFactory applies,
		// which in modern Solr defaults to managed. Reported optimistically;
		// a rejected apply() surfaces the truth with a clear message.
		return true;
	}

	/**
	 * Escape a value so it is treated as a literal term.
	 *
	 * Values reaching here are internal rather than user input, but escaping
	 * them anyway means a post type containing a reserved character cannot
	 * turn a delete-by-query into something wider than intended.
	 *
	 * @param string $value Value to escape.
	 * @return string
	 */
	private function escape_term( string $value ): string {
		$specials = array(
			'\\',
			'+',
			'-',
			'&&',
			'||',
			'!',
			'(',
			')',
			'{',
			'}',
			'[',
			']',
			'^',
			'"',
			'~',
			'*',
			'?',
			':',
			'/',
			' ',
		);

		$escaped = $value;

		foreach ( $specials as $special ) {
			$escaped = str_replace( $special, '\\' . $special, $escaped );
		}

		return $escaped;
	}
}
