# Contracts

Public API. Everything in this directory is what add-on plugins and the future
SolrEngine Cloud adapter bind to, which is why it lives apart from the rest of
`src/`: "is this a breaking change?" should be answerable by reading one
directory's diff.

See [ADR-007](../../.claude/plans/adr/ADR-007-backend-abstraction.md).

## Rules

1. **Interfaces and value objects only.** No behaviour that touches WordPress,
   the network or the database.
2. **No Solr vocabulary.** No `q`, no `fq`, no local parameters, no field names.
   A contract that leaks Solr's wire format has not abstracted Solr, it has
   renamed it — which is the mistake the 0.1.0 prototype made with
   `SolrClientInterface::search( string $q, array $params )`.
3. **Additive changes only, once 1.0 ships.** Until then these are unstable and
   may change freely; every file says so in its `@since`.

## Scope in Phase 0

Phase 0 defines the contracts the foundation itself needs and the shape of the
three backend interfaces. It deliberately does **not** define `SearchQuery`,
`SearchResults`, `Document` or `SchemaPlan`.

Those types are designed in the phase that first needs them, because designing
them now would mean guessing at a shape before the indexing pipeline (Phase 2)
and the query integration (Phase 3) have anything to say about it. Each
interface documents the methods arriving in later phases so the intended
surface is visible without being prematurely frozen. This is Principle 12
applied to our own API.

| Type | Introduced in |
| --- | --- |
| `Result`, `Capabilities`, `IndexStatus`, `DocumentRef`, `WriteResult` | Phase 0 |
| `SchemaState`, `SchemaContract`, `SchemaPlan` | Phase 1 |
| `Document`, `Selector` | Phase 2 |
| `SearchQuery`, `SearchResults`, `SearchHit` | Phase 3 |
| `SuggestQuery`, `Suggestions` | Phase 4 |
| `FacetDefinition`, `FacetResult` | Phase 5 |
