<?php
/**
 * Answers the search popup.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\Result;
use SolrEngine\Contracts\SearchHit;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Integration\WooCommerce\ProductFields;

/**
 * What the search popup shows for some text and a content-type tab.
 *
 * Two answers, one per kind of tab:
 *
 * - **All** is an overview: one section per content type with a match, in the
 *   site's order, each holding that type's best few results. It takes one
 *   counts-only query and then one small query per section, because a single
 *   relevance-ordered list lets a large catalogue push every page and post
 *   out of sight.
 * - **A type tab** is a page of that type's results, for the popup to scroll
 *   through. `has_more` says whether another page exists; nothing deeper than
 *   {@see self::MAX_RESULTS} is served, which is far more than anyone scrolls
 *   and keeps a crafted request from asking Solr for a deep page.
 *
 * The one class both of the popup's surfaces call: the REST route
 * {@see \SolrEngine\Rest\InstantResultsController} and the `instant.php` fast
 * path {@see \SolrEngine\Rest\InstantEndpoint}. They differ in how much of
 * WordPress is loaded around this call and in nothing else, so the browser
 * cannot tell which one answered. That is also why the response shape lives
 * here rather than in either surface: a second copy of it is a copy that
 * drifts.
 *
 * It adds no search logic. The query is an ordinary {@see SearchQuery}, so the
 * site scope, the visibility filter and the relevance profile come from
 * {@see QueryTranslator} exactly as they do for a results page. The tab counts
 * are the registered `post_type` facet, and the selected tab is the registered
 * `post_type` filter. Because a facet's own filter is tagged and excluded from
 * its counts, choosing "Products" narrows the results without zeroing every
 * other tab.
 *
 * Pure: a service in, arrays out. Unit-tested with no WordPress loaded.
 *
 * @since 0.11.0
 */
final class InstantResults {

	/**
	 * The facet and filter key the tabs are built on.
	 *
	 * @var string
	 */
	public const TAB_KEY = 'post_type';

	/**
	 * Longest text accepted, in characters.
	 *
	 * Generous for a search box and far below what the search endpoint allows:
	 * nobody types a paragraph into a popup, and the text is sent on every
	 * pause in typing.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 200;

	/**
	 * Results per page on a type tab, unless configured otherwise.
	 *
	 * Divisible by two, three and four, so a full page fills the last row of
	 * the card grid at any of the widths it is laid out at.
	 *
	 * @var int
	 */
	public const PER_PAGE = 24;

	/**
	 * Most results one page may hold.
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 48;

	/**
	 * Results per section on the All tab, unless configured otherwise: one row
	 * of cards.
	 *
	 * @var int
	 */
	public const SECTION_SIZE = 4;

	/**
	 * Most results one section may hold.
	 *
	 * @var int
	 */
	public const MAX_SECTION_SIZE = 12;

	/**
	 * Deepest result a type tab serves.
	 *
	 * @var int
	 */
	public const MAX_RESULTS = 1000;

	/**
	 * Longest excerpt returned, in characters.
	 *
	 * A card shows two lines. Sending the whole excerpt for each of eight
	 * results, on every pause in typing, is bytes nobody will see.
	 *
	 * @var int
	 */
	private const EXCERPT_LENGTH = 180;

	/**
	 * The search service.
	 *
	 * @var SearchService
	 */
	private $search;

	/**
	 * Post types a visitor may search, in display order.
	 *
	 * @var string[]
	 */
	private $post_types;

	/**
	 * Results per page on a type tab.
	 *
	 * @var int
	 */
	private $per_page;

	/**
	 * Results per section on the All tab.
	 *
	 * @var int
	 */
	private $section_size;

	/**
	 * Constructor.
	 *
	 * @param SearchService $search       The search service.
	 * @param string[]      $post_types   Indexed post types; the only tabs offered.
	 * @param int           $per_page     Results per page on a type tab.
	 * @param int           $section_size Results per section on the All tab.
	 */
	public function __construct( SearchService $search, array $post_types, int $per_page = self::PER_PAGE, int $section_size = self::SECTION_SIZE ) {
		$this->search       = $search;
		$this->post_types   = array_values( array_unique( array_filter( array_map( 'strval', $post_types ) ) ) );
		$this->per_page     = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$this->section_size = max( 1, min( self::MAX_SECTION_SIZE, $section_size ) );
	}

	/**
	 * Answer the popup.
	 *
	 * @since 0.11.0
	 *
	 * @param string $text      What the visitor typed.
	 * @param string $post_type Selected tab, or '' for all.
	 * @param int    $site_id   Site to search within.
	 * @param int    $page      Page of a type tab, one-based. Ignored on All.
	 * @return Result Success carrying the response body, or the search
	 *                service's failure unchanged.
	 */
	public function answer( string $text, string $post_type, int $site_id, int $page = 1 ): Result {
		$text = $this->normalise( $text );
		$tab  = $this->tab( $post_type );
		$page = '' === $tab ? 1 : $this->page( $page );

		if ( mb_strlen( $text ) < SuggestQuery::MIN_LENGTH ) {
			// Not an error, and not worth a request to Solr: one character
			// matches most of an index and tells the visitor nothing.
			return Result::success( $this->empty_payload( $text, $tab, $page ) );
		}

		return '' === $tab
			? $this->overview( $text, $site_id )
			: $this->tab_page( $text, $tab, $page, $site_id );
	}

	/**
	 * The query the popup runs.
	 *
	 * Public so the tests can assert its shape: the tab is a filter on the
	 * registered key, never a restriction of the post-type list, because only
	 * the filter is tagged and so only the filter leaves the other tabs'
	 * counts intact.
	 *
	 * @since 0.11.0
	 *
	 * @param string   $text     Normalised text.
	 * @param string   $tab      A valid tab, or ''.
	 * @param int      $site_id  Site to search within.
	 * @param int      $page     Page, one-based.
	 * @param int|null $per_page Results per page; a type tab's page size when null.
	 * @return SearchQuery
	 */
	public function query( string $text, string $tab, int $site_id, int $page = 1, ?int $per_page = null ): SearchQuery {
		$query = ( new SearchQuery( $text, $site_id ) )
			->with_pagination( $page, $per_page ?? $this->per_page )
			->with_facet( self::TAB_KEY );

		if ( array() !== $this->post_types ) {
			$query = $query->with_post_types( $this->post_types );
		}

		if ( '' !== $tab ) {
			$query = $query->with_filter( self::TAB_KEY, array( $tab ) );
		}

		return $query;
	}

	/**
	 * The tab a request names, if it is one this site offers.
	 *
	 * An unknown tab reads as "all" rather than as an error. The popup only
	 * sends tabs it was given, so an unknown one is a post type that stopped
	 * being indexed since the page loaded, and showing everything is a better
	 * answer to that than an empty panel.
	 *
	 * @since 0.11.0
	 *
	 * @param string $post_type Requested tab.
	 * @return string
	 */
	public function tab( string $post_type ): string {
		return in_array( $post_type, $this->post_types, true ) ? $post_type : '';
	}

	/**
	 * The page a request names, within what a type tab serves.
	 *
	 * Clamped rather than refused: the popup only asks for a page `has_more`
	 * promised, so a page past the limit is a crafted request, and the cheapest
	 * honest answer to it is the last page there is.
	 *
	 * @since 0.11.0
	 *
	 * @param int $page Requested page.
	 * @return int
	 */
	public function page( int $page ): int {
		return max( 1, min( (int) ceil( self::MAX_RESULTS / $this->per_page ), $page ) );
	}

	/**
	 * The All tab: a section per content type with a match.
	 *
	 * @param string $text    Normalised text.
	 * @param int    $site_id Site to search within.
	 * @return Result
	 */
	private function overview( string $text, int $site_id ): Result {
		$found = $this->run( $this->query( $text, '', $site_id )->without_documents() );

		if ( $found->is_failure() ) {
			return $found;
		}

		$results  = $found->value();
		$counts   = $this->counts( $results );
		$took     = $results->took_ms();
		$sections = array();

		foreach ( $counts as $type => $count ) {
			$section = $this->run( $this->query( $text, $type, $site_id, 1, $this->section_size ) );

			if ( $section->is_failure() ) {
				// The whole answer fails rather than one section going missing:
				// a panel without its products would claim there were none.
				return $section;
			}

			$took      += $section->value()->took_ms();
			$sections[] = array(
				'post_type' => $type,
				// The overview's count, not the section query's own total, so
				// the heading always agrees with the tab above it.
				'total'     => $count,
				'results'   => $this->hits( $section->value() ),
			);
		}

		return Result::success(
			$this->body(
				$text,
				'',
				array_sum( $counts ),
				$counts,
				array(
					'page'     => 1,
					'per_page' => $this->section_size,
					'has_more' => false,
					'results'  => array(),
					'sections' => $sections,
					'took_ms'  => $took,
					'strategy' => $results->strategy(),
				)
			)
		);
	}

	/**
	 * A type tab: one page of that type's results.
	 *
	 * @param string $text    Normalised text.
	 * @param string $tab     A valid tab.
	 * @param int    $page    Page, already clamped.
	 * @param int    $site_id Site to search within.
	 * @return Result
	 */
	private function tab_page( string $text, string $tab, int $page, int $site_id ): Result {
		$found = $this->run( $this->query( $text, $tab, $site_id, $page ) );

		if ( $found->is_failure() ) {
			return $found;
		}

		$results = $found->value();
		$total   = $results->total();

		return Result::success(
			$this->body(
				$text,
				$tab,
				$total,
				$this->counts( $results ),
				array(
					'page'     => $page,
					'per_page' => $this->per_page,
					'has_more' => $page * $this->per_page < min( $total, self::MAX_RESULTS ),
					'results'  => $this->hits( $results ),
					'sections' => array(),
					'took_ms'  => $results->took_ms(),
					'strategy' => $results->strategy(),
				)
			)
		);
	}

	/**
	 * Run a query through the search service.
	 *
	 * @param SearchQuery $query Query.
	 * @return Result Success carrying SearchResults, or a failure.
	 */
	private function run( SearchQuery $query ): Result {
		$result = $this->search->search( $query );

		if ( $result->is_failure() ) {
			return $result;
		}

		if ( ! $result->value() instanceof SearchResults ) {
			return Result::failure( 'bad_results', 'The search service returned something other than results.' );
		}

		return $result;
	}

	/**
	 * The response body. Its one definition, for both tabs and both surfaces.
	 *
	 * @param string              $text   The text searched for.
	 * @param string              $tab    The selected tab, or ''.
	 * @param int                 $total  Matches in the selected tab.
	 * @param array<string,int>   $counts Matches per type, in the site's order.
	 * @param array<string,mixed> $rest   page, per_page, has_more, results,
	 *                                    sections, took_ms, strategy.
	 * @return array<string,mixed>
	 */
	private function body( string $text, string $tab, int $total, array $counts, array $rest ): array {
		return array(
			'query'     => $text,
			'post_type' => $tab,
			'total'     => $total,
			'all'       => array_sum( $counts ),
			// An object even when empty, so JSON says {} and a client never
			// has to accept both {} and [] for the same key.
			'counts'    => (object) $counts,
			'page'      => $rest['page'],
			'per_page'  => $rest['per_page'],
			'has_more'  => $rest['has_more'],
			'results'   => $rest['results'],
			'sections'  => $rest['sections'],
			'took_ms'   => $rest['took_ms'],
			'strategy'  => $rest['strategy'],
		);
	}

	/**
	 * The body for text too short to search.
	 *
	 * @param string $text The text.
	 * @param string $tab  The selected tab.
	 * @param int    $page The page asked for.
	 * @return array<string,mixed>
	 */
	private function empty_payload( string $text, string $tab, int $page ): array {
		return $this->body(
			$text,
			$tab,
			0,
			array(),
			array(
				'page'     => $page,
				'per_page' => '' === $tab ? $this->section_size : $this->per_page,
				'has_more' => false,
				'results'  => array(),
				'sections' => array(),
				'took_ms'  => 0,
				'strategy' => 'standard',
			)
		);
	}

	/**
	 * Render a result set's hits.
	 *
	 * @param SearchResults $results Results.
	 * @return array<int,array<string,mixed>>
	 */
	private function hits( SearchResults $results ): array {
		$items = array();

		foreach ( $results->hits() as $hit ) {
			$items[] = $this->hit( $hit );
		}

		return $items;
	}

	/**
	 * Matches per tab, in the site's order.
	 *
	 * Only indexed types, so a bucket for a type the site no longer offers
	 * cannot surface as a tab the popup does not know how to label.
	 *
	 * @param SearchResults $results Results.
	 * @return array<string,int>
	 */
	private function counts( SearchResults $results ): array {
		$facet = $results->facet( self::TAB_KEY );

		if ( null === $facet ) {
			return array();
		}

		$found = array();

		foreach ( $facet->buckets() as $bucket ) {
			$found[ $bucket->value() ] = $bucket->count();
		}

		$counts = array();

		foreach ( $this->post_types as $type ) {
			if ( isset( $found[ $type ] ) && $found[ $type ] > 0 ) {
				$counts[ $type ] = $found[ $type ];
			}
		}

		return $counts;
	}

	/**
	 * Render one result.
	 *
	 * Only fields from the returnable allowlist in {@see QueryTranslator}, for
	 * the reason that list exists: a field can be searchable without being
	 * something an administrator meant to publish.
	 *
	 * @param SearchHit $hit The hit.
	 * @return array<string,mixed>
	 */
	private function hit( SearchHit $hit ): array {
		$item = array(
			'id'        => $hit->object_id(),
			'type'      => $this->text( $hit, DocumentFields::OBJECT_TYPE, 'post' ),
			'post_type' => $this->text( $hit, DocumentFields::POST_TYPE ),
			'title'     => $this->text( $hit, DocumentFields::TITLE ),
			'excerpt'   => $this->excerpt( $this->text( $hit, DocumentFields::EXCERPT ) ),
			'url'       => $this->text( $hit, DocumentFields::URL ),
			'thumbnail' => $this->text( $hit, DocumentFields::THUMBNAIL ),
			'date'      => $this->text( $hit, DocumentFields::DATE ),
			'author'    => $this->text( $hit, DocumentFields::AUTHOR_NAME ),
		);

		$price = $this->price( $hit );

		if ( null !== $price ) {
			$item['price'] = $price;
		}

		return $item;
	}

	/**
	 * A product's indexed prices, shaped for display.
	 *
	 * These are base prices (see docs/woocommerce.md), and the popup formats
	 * them in the browser because the fast path has no WooCommerce to ask. The
	 * decisions about what a price *means* are made here, once, so that every
	 * template reads the same answer:
	 *
	 * - `regular` is present only when it is above `current` — that is, when
	 *   there is something to strike through.
	 * - `min` and `max` are present only when they differ — that is, when the
	 *   product has a range to show.
	 *
	 * @param SearchHit $hit The hit.
	 * @return array<string,float>|null Null when the document has no price.
	 */
	private function price( SearchHit $hit ): ?array {
		$current = $this->number( $hit, ProductFields::PRICE );

		if ( null === $current ) {
			return null;
		}

		$price = array( 'current' => $current );

		$regular = $this->number( $hit, ProductFields::REGULAR_PRICE );

		if ( null !== $regular && $regular > $current ) {
			$price['regular'] = $regular;
		}

		$min = $this->number( $hit, ProductFields::PRICE_MIN );
		$max = $this->number( $hit, ProductFields::PRICE_MAX );

		if ( null !== $min && null !== $max && $max > $min ) {
			$price['min'] = $min;
			$price['max'] = $max;
		}

		$taxed = $this->number( $hit, ProductFields::PRICE_TAXED );

		if ( null !== $taxed ) {
			$price['current_incl_tax'] = $taxed;
		}

		return $price;
	}

	/**
	 * A field as a single string.
	 *
	 * @param SearchHit $hit      The hit.
	 * @param string    $field    Field name.
	 * @param string    $fallback Value when absent.
	 * @return string
	 */
	private function text( SearchHit $hit, string $field, string $fallback = '' ): string {
		$value = $hit->field( $field, $fallback );

		if ( is_array( $value ) ) {
			// A stored field that turned out multi-valued. The first value is
			// the one a card can show; "Array" is not.
			$value = reset( $value );
		}

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * A field as a finite number.
	 *
	 * @param SearchHit $hit   The hit.
	 * @param string    $field Field name.
	 * @return float|null
	 */
	private function number( SearchHit $hit, string $field ): ?float {
		$value = $hit->field( $field );

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		return is_finite( $number ) && $number >= 0 ? round( $number, 4 ) : null;
	}

	/**
	 * Shorten an excerpt for a card.
	 *
	 * @param string $excerpt Indexed excerpt, already stripped of markup.
	 * @return string
	 */
	private function excerpt( string $excerpt ): string {
		$excerpt = trim( (string) preg_replace( '/\s+/u', ' ', $excerpt ) );

		if ( mb_strlen( $excerpt ) <= self::EXCERPT_LENGTH ) {
			return $excerpt;
		}

		$cut   = mb_substr( $excerpt, 0, self::EXCERPT_LENGTH );
		$space = mb_strrpos( $cut, ' ' );

		// Break at a word where there is one reasonably near the end, so a
		// card does not end mid-word.
		if ( false !== $space && $space > self::EXCERPT_LENGTH * 0.6 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \t,.;:" ) . '…';
	}

	/**
	 * Collapse whitespace and cap length.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function normalise( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > self::MAX_LENGTH ? trim( mb_substr( $text, 0, self::MAX_LENGTH ) ) : $text;
	}
}
