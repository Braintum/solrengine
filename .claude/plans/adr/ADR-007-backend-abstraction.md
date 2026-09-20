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

## Addendum, 2026-09-20 — Phase 9 tested the seam, and found one leak

**Status:** the decision held. Splitting `SearchBackend` from `IndexBackend`
turned out to be load-bearing for a reason this ADR only mentions in passing, and
one contract was one adapter wide.

### The leak: `FilterRegistry` produced Solr syntax

This ADR's own rule is that a gap in the abstraction "is a signal the abstraction
is leaking and should be fixed in the domain rather than patched in the adapter".
Writing a second adapter found one immediately.

`FilterRegistry` does two separable things: it decides that the public key
`category` means the field `se_ss_taxpath_category` holding strings, and it
renders that into `se_ss_taxpath_category:(...)`. The first is a domain fact
every backend needs; the second is Solr's dialect. Only the second was exposed —
`clause_for()` returned a Solr clause and there was no way to ask what a key
meant — so a backend that does not speak Solr could not resolve a filter at all.

Fixed in the domain, per the rule: `definition_of()` exposes the mapping, and
`clause_for()` keeps the rendering for the adapter that wants it. No adapter was
patched.

This is the kind of leak that is invisible with one implementation. It had been
there since Phase 5 and nothing could have detected it except trying to write
something that was not Solr.

### The second adapter is real, because an imaginary one proves nothing

§21.4 is explicit that the Cloud implementation is deliberately undecided, and
§22.4 says to prove demand before building any of it. An HTTP client for a
service with no API, no pricing and two blocking open questions (Q8, Q9) would be
dead code that cannot be run — and code that cannot be run cannot be wrong, which
is the opposite of proof.

So the second adapter is `Backend\Memory\MemoryBackend`: an in-memory
implementation of all four contracts, held to the same rules by
`BackendConformanceTest`. It answers every question the WordPress layer asks from
the domain model alone. If a future change cannot be satisfied without Solr
vocabulary, it stops compiling.

It pays for itself three ways beyond the proof: the contracts' prose rules are
now failing tests rather than docblocks; the WordPress side can be developed with
no Solr running; and it is the first implementation to report
`is_manageable()` as false.

### `is_manageable()` had no caller

§14.4 says "the Cloud adapter simply reports managed and the UI adapts". The
contract method existed from Phase 1, the Solr adapter implemented it, and
**nothing ever called it** — so the managed-backend path was prose. The Tools
screen and the CLI now honour it, which took four lines and would have taken the
same four lines in Phase 1 if anything had been able to exercise them.

The general lesson, worth stating because it will recur: a contract method with
no caller and one implementor is not an abstraction, it is a comment with a type
signature.

### Migration between backends is what the split was for

This ADR justifies splitting search from indexing mostly on testability. The
larger payoff, named in §21.1 and built here, is that a site can index into one
backend while another keeps answering visitors — so moving between backends never
serves from a half-built index. It is the Phase 7 rebuild one level up, and it
cost a config key and an accessor because the split already existed.

One consequence had to be corrected while building it: `BackendRegistry::schema()`
resolved through the *search* factory. During a migration that is the backend no
longer being written to, so it would have prepared the old backend's schema for
content it was not receiving. Schema follows the index side.
