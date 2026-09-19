<?php
/**
 * Availability, expressed as a bucket rather than a number.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

/**
 * Turns a stock status and quantity into one of three words.
 *
 * Exact stock quantity is deliberately not indexed by default (ADR-006). It is
 * the highest-churn field in a catalogue — every order rewrites it — so
 * indexing it means reindexing a product on every purchase, on exactly the
 * stores least able to absorb that load. It is also commercially sensitive:
 * a public facet counting "3 left" publishes a merchant's inventory to their
 * competitors.
 *
 * A bucket keeps what a shopper actually acts on ("nearly gone") and drops what
 * only the merchant needs.
 *
 * @since 0.7.0
 */
final class StockLevel {

	/**
	 * Nothing available.
	 *
	 * @var string
	 */
	public const OUT = 'out';

	/**
	 * Available, but not much.
	 *
	 * @var string
	 */
	public const LOW = 'low';

	/**
	 * Available.
	 *
	 * @var string
	 */
	public const IN = 'in';

	/**
	 * On order, arriving later.
	 *
	 * @var string
	 */
	public const BACKORDER = 'backorder';

	/**
	 * Bucket an availability.
	 *
	 * A product not managing stock has no quantity at all, which is not the
	 * same as zero: it means "always available" to WooCommerce, and it must not
	 * be bucketed as out of stock.
	 *
	 * @since 0.7.0
	 *
	 * @param string   $status    WooCommerce stock status.
	 * @param int|null $quantity  Managed quantity, or null when unmanaged.
	 * @param int      $low_stock Threshold below which stock counts as low.
	 * @return string
	 */
	public static function bucket( string $status, ?int $quantity, int $low_stock = 2 ): string {
		if ( 'outofstock' === $status ) {
			return self::OUT;
		}

		if ( 'onbackorder' === $status ) {
			return self::BACKORDER;
		}

		if ( null === $quantity ) {
			return self::IN;
		}

		if ( $quantity <= 0 ) {
			// In stock according to the status, nothing left according to the
			// count. WooCommerce allows this combination — backorders, or a
			// status not yet synced — and the honest answer is "out".
			return self::OUT;
		}

		return $quantity <= max( 1, $low_stock ) ? self::LOW : self::IN;
	}

	/**
	 * Every bucket, in the order a filter should list them.
	 *
	 * @since 0.7.0
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::IN, self::LOW, self::BACKORDER, self::OUT );
	}
}
