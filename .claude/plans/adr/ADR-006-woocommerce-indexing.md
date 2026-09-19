# ADR-006 — WooCommerce indexing strategy

**Status:** Proposed · **Date:** 2026-09-19

## Context

A target store has 40,000 products and 300,000 variations. Shoppers filter by brand, attribute,
price and availability, and expect counts that reflect products rather than variations.

WooCommerce complicates every field: prices depend on tax configuration, customer location,
customer role and coupons; visibility has four catalogue states plus post status plus password
protection plus a store-wide out-of-stock setting; and variable products aggregate their price,
stock and attributes from children.

Researched facts: High-Performance Order Storage is the default for orders, and WooCommerce 10.7
disabled sync-on-read by default, so code writing directly to `wp_postmeta` now fails silently.
WooCommerce exposes `woocommerce_product_collection_build_query` for custom query generation in the
Product Collection block, and its Product Filters block is built on the Interactivity API, with all
new blocks to follow.

The current `ProductIndexer` builds an enriched document and then discards it, and the mapper reads
`$product->ID`, `->name` and `->price`, which are not public properties of `WC_Product`. The
integration indexes nothing useful and raises a `TypeError` on every product save.

## Decision

**Parent-centric documents with denormalised variation data, read exclusively through WooCommerce
CRUD, integrated through WooCommerce's own query hooks.**

1. Index the parent product as the searchable, filterable document. Denormalise from its
   variations: `se_wc_price_min_f` / `se_wc_price_max_f`, `se_wc_on_sale_b`,
   `se_wc_stock_status_s` (in stock if any variation is), `se_wc_variation_skus_ss`, and the union
   of attribute values per attribute taxonomy.
2. Read every value through `wc_get_product()` and the CRUD getters. Never read `_price`, `_sku` or
   `_stock_status` from post meta.
3. Index a **bucketed** availability level by default, not exact stock quantity.
4. Apply visibility rules server-side and unconditionally on every product query.
5. Integrate through `woocommerce_product_collection_build_query` for the Product Collection block
   and `woocommerce_product_query` for classic shop loops. Do not rebuild WooCommerce's filter UI
   in v1.

## Alternatives considered

**Variation-level documents.** Rejected as the default. 300,000 documents instead of 40,000, facet
counts that inflate because one product appears many times, and results that show the same product
repeatedly.

**Both parent and variation documents in one collection.** Rejected as the default. Facet counts
double-count unless every single query filters by object type, and one missed filter produces
quietly wrong numbers. Available later behind a setting, with `se_object_type_s` filtering.

**Solr nested child documents (block join).** Deferred. Correct in principle and genuinely better
for "products having a variation matching all of X and Y", but updates require reindexing the whole
block and facet counts need domain changes. Revisit if flat denormalisation proves insufficient.

**Reading post meta directly for indexing speed.** Rejected. It is faster today and empty after the
next storage migration — the exact failure HPOS has already produced for order data.

**Indexing exact stock quantity by default.** Rejected. Commercially sensitive, and the
highest-churn field in the catalogue: every order rewrites it, so indexing it means reindexing a
product on every purchase, on precisely the stores least able to absorb the load.

**Building a SolrEngine filter UI to replace WooCommerce's blocks.** Rejected for v1. The
Interactivity API surface is actively evolving through 2026, and a parallel UI would be a permanent
maintenance liability against blocks merchants and theme authors already expect.

## Consequences

**Positive.** Index size stays proportional to products. Facet counts mean what shoppers think they
mean. Filtering "16 GB" and "in stock" returns products that have such a variation, matching the
behaviour WooCommerce's own attribute lookup table was built to deliver. CRUD reads survive storage
migrations. Integration through Woo's hooks means no theme changes.

**Negative.** Variation-level results are unavailable in v1. Indexing through CRUD is slower than
raw meta reads, mitigated by batch cache priming. Indexed prices are base prices, so filter
boundaries can differ from displayed prices for customers in other tax jurisdictions — a documented
limitation, not a hidden one.

**Neutral.** Denormalisation means a variation change reindexes the parent. Fan-out is coalesced
through the dirty set from ADR-004.

## Why

The shopper's mental model is products, not variations, and the index should match it. Every
alternative that indexes variations directly produces counts that are defensible to an engineer and
wrong to a merchant. The CRUD-only rule is not style: it is the difference between an integration
that survives WooCommerce's storage roadmap and one that silently empties.
