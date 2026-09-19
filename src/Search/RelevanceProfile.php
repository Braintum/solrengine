<?php
/**
 * How results are ranked.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Documents\DocumentFields;

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
	 * @return self
	 */
	public static function from_config( array $config ): self {
		$boosts = array();

		foreach ( (array) ( $config['field_boosts'] ?? array() ) as $field => $boost ) {
			if ( is_numeric( $boost ) && (float) $boost > 0 ) {
				$boosts[ (string) $field ] = (float) $boost;
			}
		}

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
