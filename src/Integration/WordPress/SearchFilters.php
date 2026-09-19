<?php
/**
 * The filter panel, as a block and as a shortcode.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WordPress;

use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FacetResult;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Search\FacetLabeller;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FacetSelection;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\SearchService;
use SolrEngine\Support\Config;

/**
 * Renders the registered facets as a filter panel.
 *
 * One renderer behind two front doors — a block for block themes, a shortcode
 * for classic ones and for page builders — because they are the same feature
 * and two implementations of it would drift.
 *
 * **No JavaScript, deliberately.** The panel is a GET form of checkboxes with a
 * submit button. That makes every filtered view a real URL: bookmarkable,
 * shareable, crawlable, and reachable with the back button, with no state
 * hidden in a script. It also means the panel works in a page cache, in a
 * no-script browser and in a feed reader. An interactive version can be added
 * later without changing the URLs this produces, because the URLs are the API.
 *
 * The counts come from a facets-only request rather than from the main search
 * query. It costs one extra round trip on pages that show the panel, and
 * nothing at all on the pages that do not — which is most of them, on most
 * sites. The alternative, counting facets on every search in case something
 * later renders a panel, taxes the common case to save the rare one.
 *
 * @since 0.6.0
 */
final class SearchFilters {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	public const BLOCK = 'solrengine/search-filters';

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	public const SHORTCODE = 'solrengine_filters';

	/**
	 * Editor script handle, as block.json names it.
	 *
	 * @var string
	 */
	private const EDITOR_HANDLE = 'solrengine-filters-block';

	/**
	 * Stylesheet handle, as block.json names it.
	 *
	 * @var string
	 */
	private const STYLE_HANDLE = 'solrengine-filters';

	/**
	 * Search service.
	 *
	 * @var SearchService
	 */
	private $search;

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Registered facets.
	 *
	 * @var FacetRegistry
	 */
	private $facets;

	/**
	 * Which filters may be applied.
	 *
	 * @var FilterRegistry
	 */
	private $filters;

	/**
	 * Interprets selected facet values.
	 *
	 * @var FacetSelection
	 */
	private $selection;

	/**
	 * Labels facet buckets.
	 *
	 * @var FacetLabeller
	 */
	private $labeller;

	/**
	 * Site identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Counts for this request, so a page with two panels costs one query.
	 *
	 * @var array<string,FacetResult>|null
	 */
	private $counted = null;

	/**
	 * Constructor.
	 *
	 * @param SearchService  $search  Search service.
	 * @param Config         $config  Configuration.
	 * @param FacetRegistry  $facets  Registered facets.
	 * @param FilterRegistry $filters Registered filters.
	 * @param int            $site_id Site identifier.
	 */
	public function __construct(
		SearchService $search,
		Config $config,
		FacetRegistry $facets,
		FilterRegistry $filters,
		int $site_id
	) {
		$this->search    = $search;
		$this->config    = $config;
		$this->facets    = $facets;
		$this->filters   = $filters;
		$this->selection = new FacetSelection( $facets );
		$this->labeller  = new FacetLabeller( $facets );
		$this->site_id   = $site_id;
	}

	/**
	 * Register the block's script and style.
	 *
	 * Static, and separate from everything else here, because it is the only
	 * part that has to run on every request that might open the editor. The
	 * renderer itself is built only when a panel is actually rendered: it needs
	 * a search backend, and constructing one costs an endpoint validation with
	 * a DNS lookup in it — not something to pay on every admin page for a block
	 * nobody placed.
	 *
	 * Registered even when the build is missing, because an unregistered style
	 * handle named in block.json produces a notice on every editor load, and a
	 * source checkout without a build is a developer's problem rather than
	 * something to shout about.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		$asset_file = SOLRENGINE_PLUGIN_DIR . 'assets/build/filters.asset.php';
		$asset      = is_readable( $asset_file ) ? (array) require $asset_file : array();
		$version    = (string) ( $asset['version'] ?? SOLRENGINE_VERSION );

		if ( array() !== $asset ) {
			wp_register_script(
				self::EDITOR_HANDLE,
				SOLRENGINE_PLUGIN_URL . 'assets/build/filters.js',
				(array) ( $asset['dependencies'] ?? array() ),
				$version,
				true
			);
		}

		wp_register_style(
			self::STYLE_HANDLE,
			SOLRENGINE_PLUGIN_URL . 'assets/build/filters.css',
			array(),
			$version
		);
	}

	/**
	 * Render the block.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( array $attributes ): string {
		$keys = array();

		foreach ( (array) ( $attributes['facets'] ?? array() ) as $key ) {
			$keys[] = sanitize_key( (string) $key );
		}

		return $this->render(
			$keys,
			(string) ( $attributes['heading'] ?? '' ),
			false === ( $attributes['showCounts'] ?? true ) ? false : true,
			(string) ( $attributes['className'] ?? '' )
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'facets'  => '',
				'heading' => '',
				'counts'  => 'yes',
				'class'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$keys = array();

		foreach ( explode( ',', (string) $atts['facets'] ) as $key ) {
			$key = sanitize_key( trim( $key ) );

			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return $this->render(
			$keys,
			(string) $atts['heading'],
			! in_array( strtolower( (string) $atts['counts'] ), array( 'no', 'false', '0' ), true ),
			(string) $atts['class']
		);
	}

	/**
	 * Render the panel.
	 *
	 * @param string[] $keys        Facet keys to show, empty for all.
	 * @param string   $heading     Optional heading.
	 * @param bool     $show_counts Whether to show counts.
	 * @param string   $class_name  Extra class names.
	 * @return string
	 */
	private function render( array $keys, string $heading, bool $show_counts, string $class_name ): string {
		if ( ! $this->config->get_bool( 'search.enabled', false ) ) {
			// Filters narrow a Solr result set. With search replacement off,
			// WordPress answers the query and the panel would be a set of
			// controls that change the URL and nothing else.
			return '';
		}

		$facets = $this->counts( $keys );

		if ( array() === $facets ) {
			return '';
		}

		$selected = $this->normalised_selection();
		$classes  = trim( 'solrengine-filters ' . $class_name );

		// Enqueued here rather than on wp_enqueue_scripts, so a page without a
		// filter panel loads no stylesheet for one. WordPress prints a late
		// enqueue in the footer.
		wp_enqueue_style( self::STYLE_HANDLE );

		ob_start();
		?>
		<form class="<?php echo esc_attr( $classes ); ?>" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="solrengine-filters__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>

			<?php
			// The search text travels with the form, or submitting the filters
			// would silently drop the query they are filtering.
			?>
			<input type="hidden" name="s" value="<?php echo esc_attr( $this->terms() ); ?>" />

			<?php
			// Selections this panel does not render are carried through as
			// hidden inputs. A page with a category panel and a separate
			// content-type panel would otherwise have each one silently
			// discard the other's selection on submit.
			foreach ( $selected as $key => $values ) :
				if ( isset( $facets[ $key ] ) ) {
					continue;
				}

				foreach ( $values as $value ) :
					?>
					<input
						type="hidden"
						name="<?php echo esc_attr( FacetUrls::PARAM . '[' . $key . '][]' ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
					/>
					<?php
				endforeach;
			endforeach;
			?>

			<?php foreach ( $facets as $facet ) : ?>
				<?php $this->render_facet( $facet, $selected, $show_counts ); ?>
			<?php endforeach; ?>

			<p class="solrengine-filters__actions">
				<button type="submit" class="solrengine-filters__apply">
					<?php esc_html_e( 'Apply filters', 'solrengine' ); ?>
				</button>

				<?php if ( array() !== $selected ) : ?>
					<a class="solrengine-filters__clear" href="<?php echo esc_url( FacetUrls::clear_url() ); ?>">
						<?php esc_html_e( 'Clear all', 'solrengine' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render one facet.
	 *
	 * @param FacetResult            $facet       The facet.
	 * @param array<string,string[]> $selected    Current selection.
	 * @param bool                   $show_counts Whether to show counts.
	 * @return void
	 */
	private function render_facet( FacetResult $facet, array $selected, bool $show_counts ): void {
		$chosen = $selected[ $facet->key() ] ?? array();

		if ( $facet->is_empty() && array() === $chosen ) {
			return;
		}
		?>
		<fieldset class="solrengine-filters__facet solrengine-filters__facet--<?php echo esc_attr( $facet->type() ); ?>">
			<legend class="solrengine-filters__legend"><?php echo esc_html( $facet->label() ); ?></legend>

			<?php if ( FacetDefinition::HIERARCHY === $facet->type() && '' !== $facet->parent() ) : ?>
				<p class="solrengine-filters__up">
					<a href="<?php echo esc_url( FacetUrls::toggle_url( $facet->key(), $facet->parent(), $selected ) ); ?>">
						<?php esc_html_e( 'Back up a level', 'solrengine' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<ul class="solrengine-filters__values">
				<?php foreach ( $facet->buckets() as $bucket ) : ?>
					<?php $id = 'se-f-' . sanitize_html_class( $facet->key() . '-' . $bucket->value() ); ?>
					<li class="solrengine-filters__value">
						<input
							type="checkbox"
							id="<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( FacetUrls::PARAM . '[' . $facet->key() . '][]' ); ?>"
							value="<?php echo esc_attr( $bucket->value() ); ?>"
							<?php checked( $bucket->is_selected() ); ?>
						/>
						<label for="<?php echo esc_attr( $id ); ?>">
							<?php echo esc_html( $bucket->label() ); ?>
							<?php if ( $show_counts ) : ?>
								<span class="solrengine-filters__count"><?php echo esc_html( number_format_i18n( $bucket->count() ) ); ?></span>
							<?php endif; ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
		</fieldset>
		<?php
	}

	/**
	 * The selection, expressed the way the buckets express it.
	 *
	 * A URL may carry `se_f[category][]=34`, because a term identifier is what
	 * every other part of WordPress calls a category and an integration has
	 * every right to link that way. The buckets are keyed by path, so without
	 * this the panel would fail to tick the box a visitor had just ticked, and
	 * the link that removes a selection would add a second one instead.
	 *
	 * @return array<string,string[]>
	 */
	private function normalised_selection(): array {
		$out = array();

		foreach ( FacetUrls::selected() as $key => $values ) {
			$normalised = $this->facets->has( $key )
				? $this->selection->values_for( $key, $values )
				: $values;

			$clean = array();

			foreach ( $normalised as $value ) {
				if ( is_scalar( $value ) ) {
					$clean[] = (string) $value;
				}
			}

			if ( array() !== $clean ) {
				$out[ $key ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * Count the facets for the current request.
	 *
	 * @param string[] $keys Facet keys to count, empty for all.
	 * @return array<string,FacetResult>
	 */
	private function counts( array $keys ): array {
		if ( null !== $this->counted ) {
			// A page may hold the panel twice — a sidebar and a mobile drawer
			// are the same markup rendered in two places — and it should not
			// cost two searches.
			return $this->filtered( $this->counted, $keys );
		}

		$this->counted = array();

		$wanted = array();

		foreach ( array() !== $keys ? $keys : $this->facets->keys() as $key ) {
			if ( $this->facets->has( (string) $key ) ) {
				$wanted[] = (string) $key;
			}
		}

		if ( array() === $wanted ) {
			return array();
		}

		$query = $this->selection
			->apply( $this->query(), $wanted )
			->without_documents();

		$result = $this->search->search( $query );

		if ( $result->is_failure() ) {
			// The same rule as everywhere else: a backend that is not answering
			// removes a feature, it does not break the page. The results
			// themselves have already fallen back to WordPress by this point,
			// and a filter panel over unfiltered results would be a lie.
			return array();
		}

		$results = $result->value();

		if ( ! $results instanceof SearchResults ) {
			return array();
		}

		$this->counted = $this->labeller->label( $results->facets() );

		return $this->filtered( $this->counted, $keys );
	}

	/**
	 * Narrow counted facets to the ones a particular panel asked for.
	 *
	 * @param array<string,FacetResult> $facets Counted facets.
	 * @param string[]                  $keys   Requested keys, empty for all.
	 * @return array<string,FacetResult>
	 */
	private function filtered( array $facets, array $keys ): array {
		if ( array() === $keys ) {
			return $facets;
		}

		$out = array();

		foreach ( $keys as $key ) {
			if ( isset( $facets[ $key ] ) ) {
				$out[ $key ] = $facets[ $key ];
			}
		}

		return $out;
	}

	/**
	 * The query the panel is counting against.
	 *
	 * Rebuilt from the request rather than taken from the main query, so the
	 * panel is correct on a page that is not a search results page at all — a
	 * landing page with a filter panel and a results block below it, for
	 * instance.
	 *
	 * @return SearchQuery
	 */
	private function query(): SearchQuery {
		$query = new SearchQuery( $this->terms(), $this->site_id );

		$post_types = array_map( 'strval', $this->config->get_array( 'index.post_types', array( 'post', 'page' ) ) );

		if ( array() !== $post_types ) {
			$query = $query->with_post_types( $post_types );
		}

		foreach ( FacetUrls::selected() as $key => $values ) {
			if ( ! $this->filters->has( $key ) ) {
				continue;
			}

			$values = $this->selection->values_for( $key, $values );

			if ( array() !== $values ) {
				$query = $query->with_filter( $key, $values );
			}
		}

		return $query;
	}

	/**
	 * The search text this request carries.
	 *
	 * Read from the request first and from the main query second. The panel is
	 * meant to be correct on a page that is not a search results page — a
	 * landing page with filters above a results block — and there the main
	 * query knows nothing about the search. The fallback covers the opposite
	 * case: a pretty permalink such as /search/laptops/, where the term is in
	 * the path rather than in a query parameter.
	 *
	 * @return string
	 */
	private function terms(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading a public parameter from a public URL; nothing changes state.
		$raw = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $raw ) {
			return $raw;
		}

		$terms = get_search_query( false );

		return is_string( $terms ) ? $terms : '';
	}
}
