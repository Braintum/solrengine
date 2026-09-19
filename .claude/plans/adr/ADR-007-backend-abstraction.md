# ADR-007 — Backend abstraction for future SaaS

**Status:** Proposed · **Date:** 2026-09-19

## Context

SolrEngine ships against customer-managed Solr and may later offer a managed service. The two must
share as much code as is reasonable, without building the hosted service now. The current code has
the right instinct — four interfaces exist — but they leak: `SolrClientInterface` exposes
`search(string $q, array $params)`, which is Solr's wire format, so every caller above it is
already coupled to Solr.

## Decision

**Three narrow contracts, split by responsibility, expressed entirely in domain types. No Solr
syntax above the adapter.**

```
SearchBackend    search(SearchQuery): SearchResults
                 suggest(SuggestQuery): Suggestions
                 capabilities(): Capabilities

IndexBackend     writeBatch(Document[]): WriteResult
                 delete(DocumentRef[]): WriteResult
                 deleteBySelector(Selector): WriteResult
                 status(): IndexStatus

SchemaBackend    describe(): SchemaState
                 plan(SchemaContract): SchemaPlan
                 apply(SchemaPlan): Result        // Cloud reports "managed"
```

`SearchQuery` carries terms, typed filters, facet requests, sort, pagination, language and site
scope as structured values. `SearchResults` carries references, total, facet results and timing.
Neither knows Solr exists.

Adapters register through a `BackendRegistry`, which is public API from v1.0.

## Alternatives considered

**One monolithic `SearchEngineInterface`.** Rejected. Search and indexing have different
consumers, different failure semantics and different lifecycles. Splitting them lets a site index
to one backend while searching another during a migration, and lets the indexing path be tested
with no search path in existence.

**Keeping the current `SolrClientInterface` shape.** Rejected. A contract whose method signature is
`search(string $q, array $params)` has not abstracted Solr; it has renamed it. Any second adapter
would have to emulate Solr's query syntax, which is worse than having no abstraction.

**Building the Cloud adapter now to prove the seam.** Rejected. Principle 12. The seam is proven by
the domain layer being unit-testable with no Solr loaded, which costs nothing and is verified in
CI.

**No abstraction, add one when SaaS arrives.** Rejected. Retrofitting an abstraction through an
indexing pipeline, a query builder, a facet layer and an admin UI is an order of magnitude more
expensive than designing it in, and the retrofit lands exactly when engineering attention is on
launching a new product.

## Consequences

**Positive.** A second backend is a new directory, not a rewrite. The domain layer is unit-testable
with no WordPress and no Solr, which is what makes the fast test layer in §23 possible. The Cloud
API's request body becomes the serialised domain model, so the plugin and the service cannot drift.
Add-ons bind to stable contracts.

**Negative.** One layer of indirection on every call, and a genuine discipline cost: it is always
tempting to pass "just one" Solr parameter through. That temptation is why the constraint is
recorded here rather than assumed — and why no extension hook exposes a raw backend request.

**Neutral.** Freezing the contracts at v1.0 constrains later evolution. That is the intended
trade: stability is what makes them useful to add-ons.

## Why

The seam is the most valuable structural asset in the product and it is nearly free to build now.
If the Cloud API ever needs a field the domain model lacks, that is a signal the abstraction is
leaking and should be fixed in the domain rather than patched in the adapter — a rule that keeps
the boundary honest over years rather than months.
