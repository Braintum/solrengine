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

---

## Addendum, 2026-09-20 — Phase 6 built it, and one researched fact was wrong

**Status:** the decision held in full. Parent-centric documents, CRUD-only
reads, bucketed stock and server-side visibility all survived contact with a
real store. One integration point named in the Context section does not exist.

### `woocommerce_product_collection_build_query` is not a filter

The Context above records it as the place "for developers to implement custom
query generation". In WooCommerce 11.1 there is no such filter anywhere in the
plugin. What exists is a registry of *named collections*: an extension can
register a handler for a collection it owns, which is a different thing and no
use for redirecting somebody else's query.

The Product Collection block builds its query through WordPress's own
`query_loop_block_query_vars` filter and then runs an ordinary `WP_Query`. So
the block is integrated the same way everything else is — by marking the query
variables and substituting results in `posts_pre_query` — and the integration
rests on a core WordPress filter rather than on a WooCommerce internal. That is
a better place to stand: verified by rendering a real Product Collection block
against a real catalogue, and it is the same mechanism the classic shop loop
and site search already use.

`woocommerce_product_query` does exist, as an action, and is used as planned.

### Refusal as a design rule

The decision said to integrate through WooCommerce's hooks. Building it
surfaced the harder question: what to do with a query carrying a constraint the
index cannot express — a rating filter, a hand-picked product list, a meta
query, an ordering from an extension.

The rule adopted is **answer exactly or not at all**. Anything not fully
understood goes back to WordPress, which answers it from the database. A shop
page that looks filtered and is not costs a merchant money, and there is no
honest halfway position: a partly applied filter is a wrong page served fast.

### Layered navigation combines with AND

WooCommerce's layered navigation defaults to `and` — two chosen values of one
attribute mean a product having both — via
`woocommerce_layered_nav_default_query_type`. A disjunction, which is what
every other filter in this plugin builds, would have returned products the
shopper had just excluded.

The filter registry therefore gained a combining mode, and `SearchQuery` now
carries one per filter. The default stays "or", which is what ticking two
category boxes means; "and" is what a shop asks for, and the store's own
setting is read rather than assumed.

### Prices: single-valued, and why

A variable product priced from 50 to 500 is indexed with a minimum and a
maximum, and the price facet counts and filters on the **minimum**. WooCommerce
itself matches such a product against any band overlapping its range, because
its `_price` meta has one row per variation.

Matching the overlap would need a multi-valued price field, which the schema
contract does not have — and, more importantly, it would break the property
Phase 5 established: a facet count must equal the number of results clicking it
produces. An overlap filter with a single-valued facet field disagrees with
itself. The single-valued form keeps count and page in agreement, and the
limitation is documented rather than discovered.

A multi-valued price field is the obvious contract-version-2 change if merchants
ask for overlap semantics.
