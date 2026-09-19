# ADR-008 — Facet and filter API

**Status:** Proposed · **Date:** 2026-09-19

## Context

Faceted search is a major planned feature and the core of the WooCommerce filtering value
proposition. Solr offers two faceting APIs: the classic `facet.*` parameters and the JSON Facet
API. Separately, the public search endpoint must never become an unrestricted Solr proxy.

Researched facts: the JSON Facet API supports terms, range, query and heatmap facets, statistics
functions, arbitrarily nested sub-facets, and domain manipulation. Multi-select faceting uses
tagged filters excluded from a facet's domain:

```json
"filter": "{!tag=brand}se_tax_brand_ids:42",
"facet": { "brand": { "type": "terms", "field": "se_tax_brand_ss",
                      "domain": { "excludeTags": "brand" } } }
```

Facet fields should have `docValues` for performance, and SolrCloud accuracy across shards is
controlled by `refine`, `overrequest` and `overrefine`.

## Decision

**The JSON Facet API exclusively. Facets and filters are declared server-side; the public API
accepts only registry keys and typed values.**

1. A `FacetRegistry` holds facet definitions — key, label, Solr field, type, limit, sort, and the
   value type for filtering. Definitions come from admin configuration and the
   `solrengine_facet_definitions` filter.
2. A client requests `facets=brand,price`. It never sends facet JSON.
3. A client sends `filters[brand][]=42`. It never sends `fq`.
4. Each facet's own filter is tagged, and that facet's domain excludes its own tag, so multi-select
   counts stay meaningful.
5. Hierarchical taxonomy facets use a materialised ancestor path field (`se_tax_cat_path_ss`) with
   prefix faceting.
6. The dynamic field naming conventions guarantee `docValues` on every facetable field.

## Alternatives considered

**Classic `facet.*` parameters.** Rejected. It offers nothing the JSON API lacks, cannot nest, has
no statistics, and produces a flat response that must be reassembled into the structure the REST
payload needs anyway.

**Supporting both.** Rejected. Two translation layers, two test surfaces, one audience.

**Accepting facet JSON or `fq` from clients.** Rejected unconditionally. It is the shortest path to
making the search endpoint an unrestricted Solr proxy: raw filter access exposes unindexed-but-
stored fields, permits expensive queries, enables enumeration of non-public documents, and makes
every guarantee in the security model unenforceable.

**Computing facets in PHP from result sets.** Rejected. Correct only for the current page, so
counts would be wrong by construction.

## Consequences

**Positive.** One API, actively developed, covering terms, ranges, statistics, nesting and domains.
Multi-select works through a documented mechanism rather than a workaround. The registry gives the
admin UI, the REST schema and the WooCommerce integration a single source of truth for what is
facetable. The server-declared constraint is what lets the public endpoint be public at all.

**Negative.** Every facet a developer wants must be registered, which is one more step than passing
Solr JSON. That step is the security boundary, so it is a feature. Facet definitions must be kept
in sync with the field map, enforced by validation.

**Neutral.** SolrCloud multi-shard accuracy requires `refine` on facets where exactness matters,
at some query cost. Decided per facet definition rather than globally.

## Why

The JSON Facet API is the right technical choice on its merits. The server-declared registry is the
more important half of this decision: it is the single constraint that prevents the search endpoint
from becoming a Solr proxy, and it has to be designed into the facet layer from the start, because
retrofitting an allowlist onto an API that already accepts raw filters means breaking every client
that adopted the loose form.
