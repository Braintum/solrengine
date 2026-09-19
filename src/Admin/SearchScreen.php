<?php
/**
 * Relevance tuning.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Admin;

use SolrEngine\Integration\WooCommerce\WooCommerce;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\RelevanceProfile;
use SolrEngine\Search\RelevanceSettings;
use SolrEngine\Support\Config;

/**
 * Where a site owner decides what "most relevant" means.
 *
 * Six numbers and two switches, not a pass-through to edismax. The full
 * parameter surface would make a bad configuration easy to reach and a good one
 * no likelier to be found, and the plan's Principle 5 is that ordinary users
 * should not meet raw Solr.
 *
 * What it does show is the expression it builds. A field weighting means nothing
 * as a set of numbers in boxes, and the "qf" line underneath them is the thing an
 * integrator will search for when they want to understand or override it.
 *
 * Analyser configuration — stemming, stopwords, synonyms — is deliberately absent.
 * Changing analysis means uploading and reloading a configset rather than calling
 * an API, and it invalidates every document already indexed. It belongs in the
 * shipped configset, where it is a file an administrator can read.
 *
 * @since 0.8.0
 */
final class SearchScreen extends Screen {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'solrengine-search';

	/**
	 * Form action.
	 *
	 * @var string
	 */
	public const ACTION = 'solrengine_save_relevance';

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
	 * Constructor.
	 *
	 * @param Config        $config Configuration.
	 * @param FacetRegistry $facets Registered facets.
	 */
	public function __construct( Config $config, FacetRegistry $facets ) {
		$this->config = $config;
		$this->facets = $facets;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Search', 'solrengine' );
	}

	/**
	 * Save the submitted profile.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->require_manage();
		check_admin_referer( self::ACTION );

		// Read here, beside the nonce check that makes reading it legitimate,
		// and narrowed to scalars before it goes anywhere near validation.
		$settings = RelevanceSettings::from_input( self::submitted( (array) wp_unslash( $_POST ) ) );

		$this->config->set( 'search.relevance', $settings->to_config() );

		if ( ! $settings->is_clean() ) {
			$this->back( 'partial', implode( ' ', $settings->problems() ) );
		}

		$this->back( 'saved' );
	}

	/**
	 * Reset the profile to the shipped defaults.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function handle_reset(): void {
		$this->require_manage();
		check_admin_referer( self::ACTION . '_reset' );

		// An empty stored profile means "use the defaults", which is a different
		// thing from a stored profile that happens to hold the default numbers:
		// the first follows the defaults when they change in a later release,
		// and the second is frozen at whatever they were today.
		$this->config->set( 'search.relevance', array() );

		$this->back( 'reset' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->can_manage() ) {
			return;
		}

		$stored  = $this->config->get_array( 'search.relevance', array() );
		$profile = RelevanceProfile::from_config(
			$stored,
			WooCommerce::is_active() ? \SolrEngine\Integration\WooCommerce\ProductFields::relevance_boosts() : array()
		);

		$boosts = $profile->field_boosts();
		$fields = RelevanceSettings::tunable_fields( WooCommerce::is_active() );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search', 'solrengine' ); ?></h1>

			<?php
			$this->render_notice(
				array(
					'saved'   => array( 'success', __( 'Relevance settings saved. They apply to the next search; no reindexing is needed.', 'solrengine' ) ),
					'partial' => array( 'warning', __( 'Saved, with corrections:', 'solrengine' ) ),
					'reset'   => array( 'success', __( 'Relevance settings are back to the shipped defaults.', 'solrengine' ) ),
				)
			);
			?>

			<?php $this->open_form( self::ACTION ); ?>

			<h2><?php esc_html_e( 'Field weighting', 'solrengine' ); ?></h2>

			<p>
				<?php esc_html_e( 'How much a match in each place counts. A title match at 5 outranks a content match at 1, so a page whose title is the search term comes first. Set a field to 0 to stop searching it entirely.', 'solrengine' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $field => $label ) : ?>
					<tr>
						<th scope="row">
							<label for="solrengine-boost-<?php echo esc_attr( $field ); ?>">
								<?php echo esc_html( $label ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								id="solrengine-boost-<?php echo esc_attr( $field ); ?>"
								name="field_boosts[<?php echo esc_attr( $field ); ?>]"
								class="small-text"
								step="0.1"
								min="0"
								max="<?php echo esc_attr( (string) RelevanceSettings::MAX_BOOST ); ?>"
								value="<?php echo esc_attr( (string) ( $boosts[ $field ] ?? 0 ) ); ?>"
							/>
							<code><?php echo esc_html( $field ); ?></code>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<p class="description">
				<?php esc_html_e( 'The weighting this produces:', 'solrengine' ); ?>
				<br/><code><?php echo esc_html( $profile->query_fields() ); ?></code>
			</p>

			<h2><?php esc_html_e( 'Matching', 'solrengine' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="solrengine-mm"><?php esc_html_e( 'Words that must match', 'solrengine' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="solrengine-mm"
							name="minimum_match"
							class="regular-text code"
							value="<?php echo esc_attr( isset( $stored['minimum_match'] ) ? (string) $stored['minimum_match'] : '2<75%' ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'The default, 2<75%, means a search of one or two words must match all of them, and a longer search must match three quarters. Lowering it returns more results and less relevant ones.', 'solrengine' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="solrengine-tie"><?php esc_html_e( 'Credit for secondary matches', 'solrengine' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="solrengine-tie"
							name="tie"
							class="small-text"
							step="0.05"
							min="0"
							max="1"
							value="<?php echo esc_attr( (string) $profile->tie() ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Between 0 and 1. At 0 only the best-matching field counts towards a result\'s score. At 0.1, matches elsewhere in the same document add a little.', 'solrengine' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Newer content', 'solrengine' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="recency_boost"
								value="1"
								<?php checked( ! empty( $stored['recency_boost'] ) ); ?>
							/>
							<?php esc_html_e( 'Nudge recent content upwards', 'solrengine' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Worth having on a news site and wrong on a documentation site, where the best answer is often the oldest page. It breaks ties rather than overriding relevance.', 'solrengine' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save relevance settings', 'solrengine' ) ); ?>

			</form>

			<?php $this->render_fallback_section(); ?>

			<?php $this->render_filters_section(); ?>

			<h2><?php esc_html_e( 'Start again', 'solrengine' ); ?></h2>

			<?php $this->open_form( self::ACTION . '_reset' ); ?>
			<?php submit_button( __( 'Restore the defaults', 'solrengine' ), 'secondary', 'submit', true ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Explain what happens when a search finds nothing, or Solr is down.
	 *
	 * Read-only, and on this screen rather than buried in documentation, because
	 * it is the behaviour an administrator is most likely to mistake for a bug.
	 *
	 * @return void
	 */
	private function render_fallback_section(): void {
		?>
		<h2><?php esc_html_e( 'When there are no results', 'solrengine' ); ?></h2>

		<ol style="max-width:48em">
			<li><?php esc_html_e( 'The search runs with the weighting above.', 'solrengine' ); ?></li>
			<li><?php esc_html_e( 'If nothing matched, it runs again allowing for misspellings. This happens only on a search that found nothing, because making every search tolerant of typos makes every search less precise and slower.', 'solrengine' ); ?></li>
			<li><?php esc_html_e( 'If nothing still matched, it runs once more accepting any single word from the search.', 'solrengine' ); ?></li>
			<li><?php esc_html_e( 'If Solr cannot be reached at all, WordPress answers the search from the database. Visitors see results rather than an error, and the site keeps working.', 'solrengine' ); ?></li>
		</ol>
		<?php
	}

	/**
	 * What a visitor can filter by.
	 *
	 * Read-only in this release. Editing facet definitions means editing a
	 * contract that is still moving, and a half-built editor over it would be
	 * worse than a list an integrator can read field names out of.
	 *
	 * @return void
	 */
	private function render_filters_section(): void {
		$facets = $this->facets->available();

		?>
		<h2><?php esc_html_e( 'Filters', 'solrengine' ); ?></h2>

		<?php if ( array() === $facets ) : ?>
			<p><?php esc_html_e( 'No filters are registered yet. Registering a public taxonomy, or adding one in code, makes it available here.', 'solrengine' ); ?></p>
		<?php else : ?>
			<p>
				<?php esc_html_e( 'Visitors can narrow search results by the following. Add the "Search filters" block to your search results template, or use the shortcode below in a classic theme.', 'solrengine' ); ?>
			</p>

			<ul class="ul-disc">
				<?php foreach ( $facets as $key => $label ) : ?>
					<li>
						<strong><?php echo esc_html( (string) $label ); ?></strong>
						<code><?php echo esc_html( (string) $key ); ?></code>
					</li>
				<?php endforeach; ?>
			</ul>

			<p><code>[solrengine_filters]</code></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * The submitted values, sanitised as scalars before validation.
	 *
	 * Sanitising and validating are kept apart on purpose. This makes the input
	 * safe to look at; RelevanceSettings decides whether it means anything.
	 *
	 * Takes the request rather than reading it, so that the one place a
	 * superglobal is touched is the handler that has just verified the nonce.
	 *
	 * @param array<string,mixed> $request Unslashed request values.
	 * @return array<string,mixed>
	 */
	private static function submitted( array $request ): array {
		$boosts = array();

		foreach ( (array) ( $request['field_boosts'] ?? array() ) as $field => $value ) {
			$boosts[ sanitize_text_field( (string) $field ) ] = sanitize_text_field( (string) $value );
		}

		return array(
			'field_boosts'  => $boosts,
			'minimum_match' => sanitize_text_field( (string) ( $request['minimum_match'] ?? '' ) ),
			'tie'           => sanitize_text_field( (string) ( $request['tie'] ?? '' ) ),
			'recency_boost' => ! empty( $request['recency_boost'] ),
		);
	}
}
