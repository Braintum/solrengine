<?php
/**
 * The fields a product contributes to its document.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

use SolrEngine\Documents\DocumentFields;

/**
 * Names, and the rules for deriving them.
 *
 * Kept beside the WooCommerce integration rather than in DocumentFields,
 * because nothing in core should have to know what a variation is. Every name
 * still follows the plugin-wide convention — `se_`, then the type marker, then
 * the name — so each one lands in a dynamic field that already exists. Phase 6
 * therefore adds no schema change and no reindex for sites that are not selling
 * anything.
 *
 * The plan's section 15.2 wrote these as `se_wc_price_min_f`, which predates
 * the Phase 1 addendum to ADR-002: Solr accepts only a leading or trailing
 * wildcard, so the type marker has to come first. The names below are the
 * corrected form of the same list.
 *
 * @since 0.7.0
 */
final class ProductFields {

	// Identity.
	public const SKU           = 'se_s_wc_sku';
	public const SKU_TEXT      = 'se_txt_wc_sku';
	public const VARIATION_SKU = 'se_ss_wc_variation_skus';
	public const TYPE          = 'se_s_wc_type';
	public const VARIATIONS    = 'se_i_wc_variations';

	// Price. Every one of these is a base price: see docs/woocommerce.md.
	public const PRICE         = 'se_f_wc_price';
	public const PRICE_MIN     = 'se_f_wc_price_min';
	public const PRICE_MAX     = 'se_f_wc_price_max';
	public const REGULAR_PRICE = 'se_f_wc_regular_price';
	public const SALE_PRICE    = 'se_f_wc_sale_price';
	public const PRICE_TAXED   = 'se_f_wc_price_incl_tax';
	public const ON_SALE       = 'se_b_wc_on_sale';
	public const SALE_FROM     = 'se_dt_wc_sale_from';
	public const SALE_TO       = 'se_dt_wc_sale_to';

	// Stock.
	public const STOCK_STATUS = 'se_s_wc_stock_status';
	public const AVAILABILITY = 'se_s_wc_availability';
	public const BACKORDERS   = 'se_b_wc_backorders';
	public const STOCK        = 'se_i_wc_stock';

	/**
	 * Contexts this product is kept out of. See CatalogContext.
	 *
	 * Written as an exclusion rather than as a permission, and that is the
	 * whole reason it works. A query filters with a negative clause, which
	 * matches every document that does not carry the field — blog posts,
	 * pages, and products with nothing to hide. A positive "may appear in"
	 * field would have to be written onto every document of every type, and
	 * the day a site activated WooCommerce its entire blog would drop out of
	 * search until someone reindexed it.
	 *
	 * @var string
	 */
	public const EXCLUDED = 'se_ss_wc_excluded_from';

	// Commerce signals.
	public const FEATURED = 'se_b_wc_featured';
	public const RATING   = 'se_f_wc_rating';
	public const REVIEWS  = 'se_i_wc_reviews';
	public const SALES    = 'se_i_wc_sales';

	/**
	 * Attribute values as searchable text, across every attribute.
	 *
	 * One field rather than one per attribute, because a shopper typing
	 * "16 GB" does not say which attribute they mean, and `qf` takes explicit
	 * field names — a field per attribute would mean a query-field list that
	 * grows with the merchant's catalogue.
	 *
	 * @var string
	 */
	public const ATTRIBUTE_TEXT = 'se_txts_wc_attrs';

	/**
	 * Field holding one attribute's term identifiers.
	 *
	 * Identifiers rather than names, for the same reason taxonomies use them:
	 * a merchant renaming "Blue" to "Navy" should not invalidate every stored
	 * filter URL. Labels are resolved for display at render time.
	 *
	 * @since 0.7.0
	 *
	 * @param string $taxonomy Attribute taxonomy, such as "pa_color".
	 * @return string
	 */
	public static function attribute( string $taxonomy ): string {
		return 'se_is_wc_attr_' . DocumentFields::sanitise( $taxonomy );
	}

	/**
	 * Extra query-field boosts a store wants.
	 *
	 * Merged into the relevance profile when WooCommerce is active. A SKU is
	 * weighted heavily: somebody typing one knows exactly what they want, and
	 * a partial match elsewhere should never outrank it.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string,float>
	 */
	public static function relevance_boosts(): array {
		return array(
			self::SKU_TEXT       => 8.0,
			self::ATTRIBUTE_TEXT => 1.5,
		);
	}
}
