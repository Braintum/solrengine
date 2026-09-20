<?php
/**
 * How results are ranked.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Documents\DocumentFields;
use SolrEngine\Language\LanguageTag;

/**
 * The knobs that decide which results come first.
 *
 * Sensible defaults, adjustable by a site owner, and deliberately not a
 * pass-through to every edismax parameter. Exposing the full surface would
 * make a bad configuration easy and a good one no likelier; the plan's
 * Principle 5 is that ordinary users should not meet raw Solr.
 *
 * Immutable.
 *
 * @since 0.4.0
 */
final class RelevanceProfile {

	/**
	 * Field boosts keyed by field name.
	 *
	 * @var array<string,float>
	 */
	private $field_boosts;

	/**
	 * Phrase boosts keyed by field name.
	 *
	 * @var array<string,float>
	 */
	private $phrase_boosts;

	/**
	 * Minimum-match expression for a normal search.
	 *
	 * @var string
	 */
	private $minimum_match;

	/**
	 * Credit given to matches in fields other than the best-scoring one.
	 *
	 * @var float
	 */
	private $tie;

	/**
	 * Whether newer content is nudged upward.
	 *
	 * @var bool
	 */
	private $recency_boost;

	/**
	 * Constructor.
	 *
	 * @param array<string,float> $field_boosts  Field boosts.
	 * @param array<string,float> $phrase_boosts Phrase boosts.
	 * @param string              $minimum_match Minimum-match expression.
	 * @param float               $tie           Tie breaker.
	 * @param bool                $recency_boost Whether to favour recent content.
	 */
	public function __construct(
		array $field_boosts = array(),
		array $phrase_boosts = array(),
		string $minimum_match = '2<75%',
		float $tie = 0.1,
		bool $recency_boost = false
	) {
		$this->field_boosts  = array() !== $field_boosts ? $field_boosts : self::default_field_boosts();
		$this->phrase_boosts = array() !== $phrase_boosts ? $phrase_boosts : self::default_phrase_boosts();
		$this->minimum_match = $minimum_match;
		$this->tie           = $tie;
		$this->recency_boost = $recency_boost;
	}

	/**
	 * Build from stored configuration.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $config Stored relevance settings.
	 * @param array<string,float> $extra  Fields an active integration contributes.
	 * @return self
	 */
	public static function from_config( array $config, array $extra = array() ): self {
		$declared = array();

		foreach ( (array) ( $config['field_boosts'] ?? array() ) as $field => $boost ) {
			if ( is_numeric( $boost ) ) {
				// Zero is kept for now, not discarded. It is how the settings
				// screen says "stop searching this field", and a field dropped
				// here would be indistinguishable from one the site never
				// mentioned — which is exactly the field an integration is
				// allowed to supply a default for.
				$declared[ (string) $field ] = (float) $boost;
			}
		}

		$boosts = array() !== $declared ? $declared : self::default_field_boosts();

		foreach ( $extra as $field => $boost ) {
			// Integration defaults fill gaps; they never overrule a decision.
			// Until 0.8.0 these were merged over the top, so a store that turned
			// the SKU weighting down got it silently put back on every request.
			if ( ! array_key_exists( (string) $field, $boosts ) ) {
				$boosts[ (string) $field ] = (float) $boost;
			}
		}

		$boosts = array_filter(
			$boosts,
			static function ( $boost ): bool {
				return (float) $boost > 0;
			}
		);

		return new self(
			$boosts,
			array(),
			isset( $config['minimum_match'] ) ? (string) $config['minimum_match'] : '2<75%',
			isset( $config['tie'] ) ? (float) $config['tie'] : 0.1,
			! empty( $config['recency_boost'] )
		);
	}

	/**
	 * The qf expression.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function query_fields(): string {
		return $this->weighted( $this->field_boosts );
	}

	/**
	 * The qf additions for a language's analysed fields.
	 *
	 * The analysed fields mirror the weights of the neutral fields they duplicate,
	 * which means a document matching in its own language scores roughly twice as
	 * much as one matching only neutrally. That is the intended outcome, not an
	 * accident of adding two numbers: a stemmed match in the language the
	 * document is actually written in is a better signal than a literal match on
	 * a token that happened to be spelled the same way.
	 *
	 * @since 0.9.0
	 *
	 * @param string $subtag Primary language subtag, for example "de".
	 * @return string Field list, or an empty string when there is nothing to add.
	 */
	public function analysed_query_fields( string $subtag ): string {
		$prefix = LanguageTag::field_prefix( $subtag );

		if ( '' === $prefix ) {
			return '';
		}

		$map = array(
			DocumentFields::TITLE   => 'title',
			DocumentFields::EXCERPT => 'excerpt',
			DocumentFields::CONTENT => 'content',
		);

		$boosts = array();

		foreach ( $map as $neutral => $part ) {
			if ( ! isset( $this->field_boosts[ $neutral ] ) ) {
				// A field the site has switched off stays off in every language.
				// Re-adding it here would make a setting that looks like "do not
				// search the content" mean "do not search the content, except in
				// German".
				continue;
			}

			$boosts[ DocumentFields::analysed( $subtag, $part ) ] = (float) $this->field_boosts[ $neutral ];
		}

		return $this->weighted( $boosts );
	}

	/**
	 * The pf expression.
	 *
	 * Phrase boosting is what makes a search for two words rank documents
	 * containing them together above documents containing them apart.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function phrase_fields(): string {
		return $this->weighted( $this->phrase_boosts );
	}

	/**
	 * The mm expression for a strategy.
	 *
	 * The loosened strategy drops to one required term. It is the last resort
	 * before reporting no results at all.
	 *
	 * @since 0.4.0
	 *
	 * @param string $strategy Retrieval strategy.
	 * @return string
	 */
	public function minimum_match( string $strategy ): string {
		return 'loosened' === $strategy ? '1' : $this->minimum_match;
	}

	/**
	 * The tie breaker.
	 *
	 * @since 0.4.0
	 *
	 * @return float
	 */
	public function tie(): float {
		return $this->tie;
	}

	/**
	 * The boost function, or an empty string when recency is off.
	 *
	 * NOW is rounded to the hour deliberately. An unrounded NOW makes every
	 * query unique, so Solr's caches never hit and the boost costs far more
	 * than it is worth.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function boost_function(): string {
		if ( ! $this->recency_boost ) {
			return '';
		}

		return sprintf( 'recip(ms(NOW/HOUR,%s),3.16e-11,1,1)', DocumentFields::DATE );
	}

	/**
	 * Fields eligible for highlighting.
	 *
	 * @since 0.4.0
	 *
	 * @return string[]
	 */
	public function highlight_fields(): array {
		return array( DocumentFields::TITLE, DocumentFields::EXCERPT, DocumentFields::CONTENT );
	}

	/**
	 * Field boosts, for the settings screen.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string,float>
	 */
	public function field_boosts(): array {
		return $this->field_boosts;
	}

	/**
	 * Render a boost map as a Solr field list.
	 *
	 * @param array<string,float> $boosts Boosts by field.
	 * @return string
	 */
	private function weighted( array $boosts ): string {
		$parts = array();

		foreach ( $boosts as $field => $boost ) {
			$parts[] = 1.0 === (float) $boost ? $field : $field . '^' . rtrim( rtrim( number_format( (float) $boost, 2, '.', '' ), '0' ), '.' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Default field weighting.
	 *
	 * Note se_taxterms rather than a wildcard. Solr's qf takes explicit field
	 * names, so the contract aggregates every taxonomy's terms into one field
	 * precisely so it can be weighted with a single entry.
	 *
	 * @return array<string,float>
	 */
	private static function default_field_boosts(): array {
		return array(
			DocumentFields::TITLE   => 5.0,
			'se_taxterms'           => 1.5,
			DocumentFields::EXCERPT => 2.0,
			DocumentFields::CONTENT => 1.0,
		);
	}

	/**
	 * Default phrase weighting.
	 *
	 * @return array<string,float>
	 */
	private static function default_phrase_boosts(): array {
		return array(
			DocumentFields::TITLE   => 10.0,
			DocumentFields::CONTENT => 2.0,
		);
	}
}
