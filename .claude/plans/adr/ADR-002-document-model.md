# ADR-002 — Index document model and ID scheme

**Status:** Proposed · **Date:** 2026-09-19

## Context

The current mapper produces documents with seven fields and a document `id` equal to the bare
WordPress post ID. That ID collides between post types on a shared collection, between sites on a
multisite or shared index, and with any future object type such as terms or users. The documents
also omit an excerpt that the front-end template tries to render, and emit dates in ISO 8601 with a
numeric offset where Solr's date fields require UTC with a trailing `Z`.

The plugin must eventually index posts, pages, custom post types, taxonomy terms, users,
WooCommerce products and arbitrary plugin metadata — on customer-controlled Solr installations that
may host other applications in the same collection.

## Decision

**A namespaced, search-oriented document model with composite identifiers.**

```
id = "se:{site_id}:{object_type}:{object_id}"      e.g. "se:1:post:4211"
```

All fields carry an `se_` prefix and a type suffix matching a dynamic field pattern:
`se_title_txt`, `se_meta_price_f`, `se_tax_category_ss`, `se_wc_stock_status_s`. Every document
carries `se_site_id_i`, `se_object_type_s`, `se_schema_version_i` and `se_indexed_dt`. The full
field list is in §10.1 of the plan.

The model is designed from what is searched, filtered, sorted, faceted or displayed — not from the
WordPress database schema.

## Alternatives considered

**Bare object ID.** The current approach. Rejected for the three collision axes above. Recovering
from an ID collision in production means a full rebuild.

**A UUID per document.** Rejected. It makes updates require a lookup, makes reindexing
non-idempotent, and makes deleting a site's documents an enumeration problem rather than a query.

**Unprefixed field names** (`title`, `content`, `price`). Rejected. SolrEngine cannot assume it owns
the collection. Without a namespace, its schema requirements can collide with an existing
application's fields, its Schema API operations become unsafe, and identifying its own fields for
migration or cleanup becomes guesswork.

**Mirroring the WordPress schema.** Rejected. `post_content_filtered`, `ping_status`, `to_ping`,
`post_mime_type` and their neighbours have no search value and cost index size and indexing time.

**Nested child documents for variations and meta.** Deferred. Solr supports block joins, but they
complicate updates — the whole block must be reindexed together — and facet counts require domain
changes to stay correct. Revisit if flat denormalisation proves insufficient (see ADR-006).

## Consequences

**Positive.** Collisions are impossible by construction. A site's documents are deletable by query
rather than by enumeration. The document model works unchanged whether the deployment uses one
collection per site or a shared collection, which is what makes §10.2 a deployment choice rather
than an architectural one. `se_schema_version_i` enables targeted partial reindexing after a
contract change. The namespace makes coexistence with other applications safe.

**Negative.** Field names are more verbose than a Solr engineer would write by hand, and slightly
more index overhead per document from the prefix. Both are negligible against what they buy.

**Neutral.** Third-party code adding fields through `solrengine_document` must follow the naming
convention. This is enforced by validation and documented, and it is the same discipline that makes
dynamic fields work at all.

## Why

The ID scheme is the highest-leverage, lowest-cost decision in the plan: it costs a string
concatenation now and prevents three classes of unrecoverable data corruption later. The `se_`
namespace is what makes ADR-003's hybrid schema strategy safe — without it, "only touch fields we
own" is not an expressible rule.
