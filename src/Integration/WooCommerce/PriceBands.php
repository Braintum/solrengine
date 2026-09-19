<?php
/**
 * Choosing the bands a price facet offers.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

/**
 * Derives price bands from what a catalogue actually costs.
 *
 * A fixed band width cannot work across stores: 50 is a sensible step for
 * t-shirts and useless for tractors. The bands are therefore derived from the
 * most expensive product in the catalogue, rounded to a number a human would
 * have picked — 100, not 97.36 — because a filter labelled "97.36 to 194.72"
 * reads as a bug even when the arithmetic is perfect.
 *
 * Pure, so the rounding can be argued with in a unit test rather than in a
 * support ticket.
 *
 * @since 0.7.0
 */
final class PriceBands {

	/**
	 * Steps a band width is rounded to, within each power of ten.
	 *
	 * @var float[]
	 */
	private const STEPS = array( 1.0, 2.0, 2.5, 5.0, 10.0 );

	/**
	 * Band boundaries covering a catalogue.
	 *
	 * @since 0.7.0
	 *
	 * @param float $max    Highest price in the catalogue.
	 * @param int   $bands  Roughly how many bands to aim for.
	 * @return array{start:float,end:float,gap:float} A zero gap means "no usable bands".
	 */
	public static function for_max( float $max, int $bands = 8 ): array {
		$none = array(
			'start' => 0.0,
			'end'   => 0.0,
			'gap'   => 0.0,
		);

		if ( $max <= 0.0 || $bands < 1 ) {
			// A catalogue with no priced products gets no price facet at all,
			// rather than one band covering nothing.
			return $none;
		}

		$gap = self::round_up( $max / $bands );

		if ( $gap <= 0.0 ) {
			return $none;
		}

		$end = ceil( $max / $gap ) * $gap;

		if ( $end <= $max ) {
			// The most expensive product must fall inside a band. A band's
			// upper bound is exclusive, so a catalogue whose maximum lands
			// exactly on a boundary — 120.00 with bands of 20 — would
			// otherwise drop the one product shoppers are most curious about.
			$end += $gap;
		}

		return array(
			'start' => 0.0,
			'end'   => $end,
			'gap'   => $gap,
		);
	}

	/**
	 * Round a width up to the next number a person would have chosen.
	 *
	 * @param float $value Raw width.
	 * @return float
	 */
	private static function round_up( float $value ): float {
		if ( $value <= 0.0 ) {
			return 0.0;
		}

		$magnitude = (float) pow( 10, (int) floor( log10( $value ) ) );
		$scaled    = $value / $magnitude;

		foreach ( self::STEPS as $step ) {
			if ( $scaled <= $step ) {
				$gap = $step * $magnitude;

				// Sub-unit bands are not useful in a shop: nobody filters by
				// ten-cent steps, and the band list would be enormous.
				return max( 1.0, $gap );
			}
		}

		return max( 1.0, 10.0 * $magnitude );
	}
}
