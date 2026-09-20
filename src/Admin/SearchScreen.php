<?php
/**
 * Relevance tuning.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Admin;

use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Contracts\SchemaPlan;
use SolrEngine\Integration\WooCommerce\WooCommerce;
use SolrEngine\Language\Languages;
use SolrEngine\Language\LanguageTag;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\LanguageFields;
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
	 * The site's languages.
	 *
	 * @var Languages|null
	 */
	private $languages;

	/**
	 * Backend registry, for reading and changing the schema.
	 *
	 * @var BackendRegistry|null
	 */
	private $backends;

	/**
	 * Contract registry.
	 *
	 * @var ContractRegistry|null
	 */
	private $contracts;

	/**
	 * Constructor.
	 *
	 * @param Config                $config    Configuration.
	 * @param FacetRegistry         $facets    Registered facets.
	 * @param Languages|null        $languages The site's languages.
	 * @param BackendRegistry|null  $backends  Backend registry.
	 * @param ContractRegistry|null $contracts Contract registry.
	 */
	public function __construct(
		Config $config,
		FacetRegistry $facets,
		?Languages $languages = null,
		?BackendRegistry $backends = null,
		?ContractRegistry $contracts = null
	) {
		$this->config    = $config;
		$this->facets    = $facets;
		$this->languages = $languages;
		$this->backends  = $backends;
		$this->contracts = $contracts;
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
		$request = (array) wp_unslash( $_POST );

		$settings = RelevanceSettings::from_input( self::submitted( $request ) );

		$this->config->set( 'search.relevance', $settings->to_config() );

		// Only written when the form offered it. A monolingual site's form has no
		// such checkbox, and reading its absence as "switch scoping off" would
		// silently change a setting nobody was shown.
		if ( null !== $this->languages && $this->languages->is_multilingual() ) {
			$this->config->set( 'search.language_scope', ! empty( $request['language_scope'] ) );
		}

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
	 * Enable or disable per-language analysis.
	 *
	 * Deliberately one action rather than a checkbox on the main form. Turning a
	 * language on changes the Solr schema, and a schema change is not something to
	 * bundle into a save that also adjusts a boost: it can fail, it needs its own
	 * message when it does, and the setting must not be stored unless it worked.
	 *
	 * The order matters and is the whole reason this is a separate handler. The
	 * field is added to the schema *first*, and the language is recorded only if
	 * that succeeded — because the document builder trusts this setting, and a
	 * language recorded without its field makes Solr reject every document the
	 * site writes.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function handle_languages(): void {
		$this->require_manage();
		check_admin_referer( self::ACTION . '_languages' );

		$request = (array) wp_unslash( $_POST );
		$wanted  = array();

		foreach ( (array) ( $request['languages'] ?? array() ) as $subtag ) {
			$primary = LanguageTag::primary( sanitize_text_field( (string) $subtag ) );

			if ( '' !== $primary ) {
				$wanted[ $primary ] = $primary;
			}
		}

		$wanted = array_values( $wanted );

		if ( array() === $wanted ) {
			// Switching everything off needs no schema change. The patterns stay
			// in the schema — SolrEngine never removes a field — and documents
			// simply stop being written into them.
			$this->config->set( 'index.analysed_languages', array() );

			$this->back( 'languages_off' );
		}

		$applied = $this->apply_language_fields( $wanted );

		if ( '' !== $applied ) {
			$this->back( 'languages_failed', $applied );
		}

		$this->config->set( 'index.analysed_languages', $wanted );

		$this->back( 'languages_on' );
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
					'saved'            => array( 'success', __( 'Relevance settings saved. They apply to the next search; no reindexing is needed.', 'solrengine' ) ),
					'partial'          => array( 'warning', __( 'Saved, with corrections:', 'solrengine' ) ),
					'reset'            => array( 'success', __( 'Relevance settings are back to the shipped defaults.', 'solrengine' ) ),
					'languages_on'     => array( 'success', __( 'Language analysis is on. Run a full index so existing content is analysed too.', 'solrengine' ) ),
					'languages_off'    => array( 'success', __( 'Language analysis is off. Searches use the language-neutral fields.', 'solrengine' ) ),
					'languages_failed' => array( 'error', __( 'Language analysis could not be switched on.', 'solrengine' ) ),
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

				<?php if ( null !== $this->languages && $this->languages->is_multilingual() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Languages', 'solrengine' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="language_scope"
									value="1"
									<?php checked( $this->config->get_bool( 'search.language_scope', true ) ); ?>
								/>
								<?php esc_html_e( 'Only show results in the language the visitor is reading', 'solrengine' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'On by default. A visitor searching from the German site expects German results, and handing them the English translation of the same page as a second hit is worse than not finding it. Switch it off on a site where most pages exist in one language only.', 'solrengine' ); ?>
							</p>
						</td>
					</tr>
				<?php endif; ?>

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

			<?php $this->render_language_section(); ?>

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
	 * Render the per-language analysis section.
	 *
	 * Absent on a monolingual site. Analysing English content as English when
	 * that is the only language there is buys nothing over the neutral analyser
	 * that is already doing it, and a section offering one checkbox nobody should
	 * tick is worse than no section.
	 *
	 * @return void
	 */
	private function render_language_section(): void {
		if ( null === $this->languages || ! $this->languages->is_multilingual() ) {
			return;
		}

		$provider = $this->languages->provider();

		if ( null === $provider ) {
			return;
		}

		$enabled   = array_map( 'strval', $this->config->get_array( 'index.analysed_languages', array() ) );
		$available = $this->analysable();

		?>
		<h2><?php esc_html_e( 'Languages', 'solrengine' ); ?></h2>

		<p>
			<?php
			printf(
				/* translators: %s: name of the plugin providing languages. */
				esc_html__( 'Languages are read from %s.', 'solrengine' ),
				esc_html( $provider->label() )
			);
			?>
			<?php esc_html_e( 'Searches are answered in the language the visitor is reading, and content in the other languages is left out of them.', 'solrengine' ); ?>
		</p>

		<h3><?php esc_html_e( 'Language-aware analysis', 'solrengine' ); ?></h3>

		<p>
			<?php esc_html_e( 'Text can be indexed a second time through an analyser that knows the language, so a German search for "Häuser" finds "Haus" and an English search for "running" finds "run". Without it, a search matches only the words as they are written.', 'solrengine' ); ?>
		</p>

		<?php if ( array() === $available ) : ?>
			<p>
				<?php esc_html_e( 'Your Solr does not define an analyser for any of this site\'s languages, and SolrEngine will not create one: an analyser is a much larger commitment than a field, and a wrong one is not recoverable by adding another. Apply the configset SolrEngine ships, which defines two dozen of them, and this list will fill in. Content is indexed and searchable either way.', 'solrengine' ); ?>
			</p>

			<?php return; ?>
		<?php endif; ?>

		<?php $this->open_form( self::ACTION . '_languages' ); ?>

		<?php foreach ( $available as $subtag => $label ) : ?>
			<p style="margin:.2em 0">
				<label>
					<input
						type="checkbox"
						name="languages[]"
						value="<?php echo esc_attr( (string) $subtag ); ?>"
						<?php checked( in_array( (string) $subtag, $enabled, true ) ); ?>
					/>
					<?php echo esc_html( $label ); ?>
					<code><?php echo esc_html( LanguageTag::field_type( (string) $subtag ) ); ?></code>
				</label>
			</p>
		<?php endforeach; ?>

		<p class="description">
			<?php esc_html_e( 'Switching one on adds a field to your Solr schema. Existing content is not re-analysed by a schema change, so run a full index afterwards.', 'solrengine' ); ?>
		</p>

		<?php submit_button( __( 'Save language analysis', 'solrengine' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * The site's languages whose analyser this Solr actually has.
	 *
	 * @return array<string,string> Label keyed by primary subtag.
	 */
	private function analysable(): array {
		if ( null === $this->languages || null === $this->backends ) {
			return array();
		}

		$schema = $this->backends->schema();

		if ( null === $schema ) {
			return array();
		}

		$state = $schema->describe();

		if ( $state->is_failure() ) {
			return array();
		}

		$supported = LanguageFields::available( $state->value(), $this->languages->primary_subtags() );
		$labels    = array();

		foreach ( $this->languages->all() as $tag => $label ) {
			$primary = LanguageTag::primary( (string) $tag );

			if ( '' === $primary || ! in_array( $primary, $supported, true ) ) {
				continue;
			}

			// Keyed by subtag, so pt-BR and pt-PT collapse onto one Portuguese
			// entry: they share an analyser, and offering the choice twice would
			// imply they could be switched on separately.
			$labels[ $primary ] = isset( $labels[ $primary ] )
				? $labels[ $primary ] . ', ' . (string) $label
				: (string) $label;
		}

		return $labels;
	}

	/**
	 * Add the analysed fields for a set of subtags to the schema.
	 *
	 * @param string[] $subtags Primary subtags.
	 * @return string An error message, or an empty string on success.
	 */
	private function apply_language_fields( array $subtags ): string {
		if ( null === $this->backends || null === $this->contracts ) {
			return __( 'Solr is not reachable.', 'solrengine' );
		}

		$schema = $this->backends->schema();

		if ( null === $schema ) {
			return __( 'Solr is not reachable.', 'solrengine' );
		}

		// A contract built for the languages being asked for, not the ones
		// already configured. The registry this screen was handed carries the
		// current set, and planning against that would produce an empty plan and
		// report success without adding anything.
		$planned = $schema->plan( ( new ContractRegistry( $subtags ) )->current() );

		if ( $planned->is_failure() ) {
			return $planned->message();
		}

		$plan = $planned->value();

		if ( ! $plan instanceof SchemaPlan ) {
			return __( 'The schema could not be read.', 'solrengine' );
		}

		if ( $plan->is_empty() ) {
			// Already there, from a previous attempt or from the shipped
			// configset. Nothing to do and nothing wrong.
			return '';
		}

		if ( array() !== $plan->blockers() ) {
			return implode( ' ', $plan->blockers() );
		}

		$applied = $schema->apply( $plan );

		return $applied->is_failure() ? $applied->message() : '';
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
