# ADR-003 — Schema management strategy

**Status:** Proposed · **Date:** 2026-09-19

## Context

SolrEngine must index arbitrary WordPress metadata on Solr installations it does not control,
across many customers, for years, while the set of indexed fields changes with every plugin the
customer installs. Five approaches were evaluated: user-managed schema, plugin-managed schema,
hybrid, dynamic fields, and schemaless mode.

Researched facts:

- The Schema API supports `add-field`, `add-dynamic-field`, `add-field-type`, `add-copy-field` and
  their replace and delete counterparts, and requires `ManagedIndexSchemaFactory`, the default in
  modern Solr.
- The guide states plainly: "Modifying your schema will never modify any documents that are already
  indexed", and previously indexed documents "must be reindexed if existing index data uses schema
  elements that you changed."
- Solr 10 treats all configsets as trusted and requires authentication and authorisation for
  administrative access.
- Schemaless mode infers a field's type from the first value seen; the guide warns that many
  generated copyField rules slow indexing noticeably and enlarge the index.

The current code attempts something different again: it auto-creates Solr cores on every request
using a configset named `solrengine` that exists on no customer's installation.

## Decision

**Hybrid. SolrEngine owns the `se_*` namespace, carries variable data on dynamic fields, maintains
its own schema contract version, and applies only additive Schema API changes after explicit
administrator confirmation.**

1. Dynamic field patterns (`se_*_txt`, `se_*_s`, `se_*_ss`, `se_*_i`, `se_*_l`, `se_*_f`, `se_*_d`,
   `se_*_b`, `se_*_dt`, `se_*_ids`) carry every meta key, taxonomy and attribute. **Adding a newly
   indexed field requires no schema change at all.**
2. Explicit fields exist only where a dynamic pattern will not do: the suggester source, copyField
   targets, and fields needing bespoke analysis.
3. A shipped configset in `configsets/solrengine/` covers greenfield and locked-down installs.
4. The plugin compares the live schema against its contract, displays the exact operations it would
   perform, and applies them only on confirmation — never on activation, never automatically.
5. A copy-pasteable export covers customers whose Solr is administered by a third party.
6. `SCHEMA_CONTRACT_VERSION` is an integer independent of the plugin version. Documents carry
   `se_schema_version_i` so partial reindexing can target stale documents.

## Alternatives considered

**User-managed only.** Retained as the fallback path, rejected as the default. It makes every
newly indexed meta key a support conversation and a Solr restart.

**Plugin-managed, applied automatically.** Rejected. A plugin that silently mutates a shared
production Solr will eventually break an unrelated application in the same cluster, and the
customer will be right to blame it. Capability is not consent.

**Schemaless mode.** Rejected. Type inference from the first observed value is a delayed failure: a
meta key holding `2024-01-01` becomes a date field, and the first row containing `N/A` fails to
index permanently, surfacing weeks later as "some products are missing from search". The guide's
own warnings about copyField proliferation compound it.

**Per-customer generated schemas.** Rejected. Every install diverges, no migration can be tested
against the real distribution of schemas, and support becomes forensic work.

## Consequences

**Positive.** Indexing arbitrary metadata becomes a naming convention rather than a migration
problem — the single largest risk reduction in the plan. Most plugin upgrades require no schema
change whatsoever. SolrEngine never touches a field it did not create. The strategy degrades
gracefully: where the Schema API is unavailable, the export path still works.

**Negative.** Field names are verbose. The administrator must choose a type when allowlisting a
meta key, which is a small UX cost that buys correctness — it is exactly the choice schemaless mode
makes silently and wrongly. Non-additive changes still require a rebuild, which is inherent to Solr
and not a property of this decision.

**Neutral.** Two schema-related versions exist (plugin and contract). This is deliberate: coupling
them would force meaningless migrations on releases that change nothing about the index.

## Why

The hybrid is the only option that is simultaneously safe on infrastructure we do not own,
capable of handling fields nobody has invented yet, and supportable across thousands of divergent
installations. Dynamic fields do the heavy lifting; the Schema API is a convenience with a consent
gate in front of it; the shipped configset is the fallback that makes the whole thing honest.
