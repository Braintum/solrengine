<?php
/**
 * Validation for the relevance tuning screen.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Documents\DocumentFields;
use SolrEngine\Integration\WooCommerce\ProductFields;

/**
 * Turns what an administrator typed into a relevance profile, or says why not.
 *
 * Separate from RelevanceProfile because the two have opposite jobs. The profile
 * is trusted input rendered into Solr parameters; this is untrusted input being
 * decided about. Mixing them would mean either validating on every query or
 * trusting the form, and the second is how a mistyped mm expression turns into
 * an HTTP 400 on every search a site serves.
 *
 * Pure. No WordPress, no Solr, no configuration store — which is what lets every
 * rejection rule below be tested directly.
 *
 * @since 0.8.0
 */
final class RelevanceSettings {

	/**
	 * Largest boost accepted.
	 *
	 * Not a Solr limit. Beyond roughly this, one field's score dominates every
	 * other so completely that the rest of the profile stops mattering, and an
	 * administrator who types 1000 has almost always misunderstood what the
	 * number does.
	 *
	 * @var float
	 */
	public const MAX_BOOST = 100.0;

	/**
	 * Normalised field boosts.
	 *
	 * @var array<string,float>
	 */
	private $field_boosts;

	/**
	 * Normalised minimum-match expression.
	 *
	 * @var string
	 */
	private $minimum_match;

	/**
	 * Normalised tie breaker.
	 *
	 * @var float
	 */
	private $tie;

	/**
	 * Whether recency boosting is on.
	 *
	 * @var bool
	 */
	private $recency_boost;

	/**
	 * What was rejected, as messages for an administrator.
	 *
	 * @var string[]
	 */
	private $problems;

	/**
	 * Constructor.
	 *
	 * @param array<string,float> $field_boosts  Field boosts.
	 * @param string              $minimum_match Minimum-match expression.
	 * @param float               $tie           Tie breaker.
	 * @param bool                $recency_boost Whether to favour recent content.
	 * @param string[]            $problems      Rejection messages.
	 */
	private function __construct(
		array $field_boosts,
		string $minimum_match,
		float $tie,
		bool $recency_boost,
		array $problems
	) {
		$this->field_boosts  = $field_boosts;
		$this->minimum_match = $minimum_match;
		$this->tie           = $tie;
		$this->recency_boost = $recency_boost;
		$this->problems      = $problems;
	}

	/**
	 * Build from submitted form values.
	 *
	 * Every rejected value falls back to the default for that setting rather
	 * than aborting the save. A site owner adjusting four numbers and mistyping
	 * one should not lose the other three, and the message says which one was
	 * ignored.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string,mixed> $input Raw submitted values.
	 * @return self
	 */
	public static function from_input( array $input ): self {
		$problems = array();
		$boosts   = array();
		$allowed  = self::tunable_fields();

		foreach ( (array) ( $input['field_boosts'] ?? array() ) as $field => $raw ) {
			$field = (string) $field;

			if ( ! isset( $allowed[ $field ] ) ) {
				// Silently ignored rather than reported. A name that is not on
				// the list did not come from the form, so there is nobody to
				// explain it to, and the alternative is letting a hand-made
				// request put an arbitrary field name into every query.
				continue;
			}

			if ( ! is_numeric( $raw ) ) {
				$problems[] = sprintf(
					/* translators: %s: field label. */
					__( 'The boost for "%s" was not a number and has been left unchanged.', 'solrengine' ),
					$allowed[ $field ]
				);

				continue;
			}

			$value = (float) $raw;

			if ( $value < 0 ) {
				$problems[] = sprintf(
					/* translators: %s: field label. */
					__( 'The boost for "%s" cannot be negative. Use 0 to stop searching that field.', 'solrengine' ),
					$allowed[ $field ]
				);

				continue;
			}

			if ( $value > self::MAX_BOOST ) {
				$problems[] = sprintf(
					/* translators: 1: field label, 2: largest accepted boost. */
					__( 'The boost for "%1$s" was reduced to %2$s, which is already far higher than every other field.', 'solrengine' ),
					$allowed[ $field ],
					self::MAX_BOOST
				);

				$value = self::MAX_BOOST;
			}

			// Zero is meaningful, not empty: it removes the field from the
			// search entirely. RelevanceProfile drops non-positive boosts, so
			// recording it is the same as recording the removal.
			if ( $value > 0 ) {
				$boosts[ $field ] = round( $value, 2 );
			}
		}

		if ( array() === $boosts ) {
			// Every field set to zero searches nothing and returns nothing for
			// every visitor. Refused rather than saved, because the screen that
			// would let you undo it is not the one visitors are using.
			$problems[] = __( 'At least one field must be searchable. The previous field weights have been kept.', 'solrengine' );
		}

		$mm = trim( (string) ( $input['minimum_match'] ?? '' ) );

		if ( '' !== $mm && ! self::is_valid_minimum_match( $mm ) ) {
			$problems[] = sprintf(
				/* translators: %s: the value that was rejected. */
				__( '"%s" is not a valid minimum-match expression, so the default has been kept. Use a number, a percentage, or pairs like "2<75%%".', 'solrengine' ),
				$mm
			);

			$mm = '';
		}

		$tie = $input['tie'] ?? null;

		if ( null !== $tie && '' !== $tie && ! is_numeric( $tie ) ) {
			$problems[] = __( 'The tie breaker was not a number and has been left unchanged.', 'solrengine' );

			$tie = null;
		}

		if ( is_numeric( $tie ) && ( (float) $tie < 0 || (float) $tie > 1 ) ) {
			$problems[] = __( 'The tie breaker must be between 0 and 1. The default has been kept.', 'solrengine' );

			$tie = null;
		}

		return new self(
			$boosts,
			'' === $mm ? '2<75%' : $mm,
			is_numeric( $tie ) ? round( (float) $tie, 2 ) : 0.1,
			! empty( $input['recency_boost'] ),
			$problems
		);
	}

	/**
	 * The fields an administrator may weight, with their labels.
	 *
	 * A deliberately short list. Every searchable field could be exposed, but
	 * the plan's Principle 5 is that ordinary users should not meet raw Solr,
	 * and a screen offering forty field names weights none of them well.
	 *
	 * Product fields are on the list even when WooCommerce is inactive, so that
	 * a store's tuning survives deactivating it for an afternoon. They cost
	 * nothing on a site without products: both resolve through dynamic field
	 * patterns the contract already defines, so Solr accepts them in qf whether
	 * or not a document has ever carried one.
	 *
	 * @since 0.8.0
	 *
	 * @param bool $with_products Whether to include the WooCommerce fields.
	 * @return array<string,string> Label keyed by field name.
	 */
	public static function tunable_fields( bool $with_products = true ): array {
		$fields = array(
			DocumentFields::TITLE   => __( 'Title', 'solrengine' ),
			DocumentFields::EXCERPT => __( 'Excerpt', 'solrengine' ),
			DocumentFields::CONTENT => __( 'Content', 'solrengine' ),
			'se_taxterms'           => __( 'Categories and tags', 'solrengine' ),
		);

		if ( $with_products ) {
			$fields[ ProductFields::SKU_TEXT ]       = __( 'Product SKU', 'solrengine' );
			$fields[ ProductFields::ATTRIBUTE_TEXT ] = __( 'Product attributes', 'solrengine' );
		}

		return $fields;
	}

	/**
	 * Whether a minimum-match expression is one Solr will accept.
	 *
	 * Worth validating rather than passing through. An invalid mm is not a
	 * degraded search: Solr rejects the whole request, so every search on the
	 * site fails until somebody edits the setting back. The grammar is a bare
	 * specification, or one or more "terms<specification" pairs.
	 *
	 * @since 0.8.0
	 *
	 * @param string $expression Expression to check.
	 * @return bool
	 */
	public static function is_valid_minimum_match( string $expression ): bool {
		$expression = trim( $expression );

		if ( '' === $expression ) {
			return false;
		}

		$terms = preg_split( '/\s+/', $expression );

		if ( ! is_array( $terms ) || array() === $terms ) {
			return false;
		}

		foreach ( $terms as $term ) {
			if ( false === strpos( (string) $term, '<' ) ) {
				// A bare specification is legal, but only on its own. "75% 2"
				// is not a Solr expression, and accepting it would store
				// something that fails at query time rather than at save time.
				if ( count( $terms ) > 1 ) {
					return false;
				}

				return self::is_match_specification( (string) $term );
			}

			$parts = explode( '<', (string) $term );

			if ( 2 !== count( $parts ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $parts[0] ) ) {
				return false;
			}

			if ( ! self::is_match_specification( $parts[1] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The normalised values, in the shape RelevanceProfile reads.
	 *
	 * @since 0.8.0
	 *
	 * @return array<string,mixed>
	 */
	public function to_config(): array {
		$config = array(
			'minimum_match' => $this->minimum_match,
			'tie'           => $this->tie,
			'recency_boost' => $this->recency_boost,
		);

		if ( array() !== $this->field_boosts ) {
			// Omitted rather than stored empty when every field was refused.
			// RelevanceProfile reads an absent key as "use the defaults", which
			// is the right outcome; an empty array would mean the same thing
			// but by accident rather than by decision.
			$config['field_boosts'] = $this->field_boosts;
		}

		return $config;
	}

	/**
	 * What was rejected.
	 *
	 * @since 0.8.0
	 *
	 * @return string[]
	 */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * Whether everything submitted was accepted as given.
	 *
	 * @since 0.8.0
	 *
	 * @return bool
	 */
	public function is_clean(): bool {
		return array() === $this->problems;
	}

	/**
	 * Whether one part of an mm expression is a valid specification.
	 *
	 * @param string $spec Specification, such as "2", "-1", "75%" or "-25%".
	 * @return bool
	 */
	private static function is_match_specification( string $spec ): bool {
		if ( 1 === preg_match( '/^-?[0-9]+$/', $spec ) ) {
			return true;
		}

		if ( 1 !== preg_match( '/^(-?[0-9]{1,3})%$/', $spec, $matches ) ) {
			return false;
		}

		return abs( (int) $matches[1] ) <= 100;
	}
}
