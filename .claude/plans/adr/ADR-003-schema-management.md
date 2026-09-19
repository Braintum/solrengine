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

## Addendum, 2026-09-20 — Phase 7 built the migration UX, and the rebuild path needed correcting

**Status:** the decision held. The Schema API sits behind a consent gate, the
export path is the fallback, and nothing outside `se_` is touched. Three things
this phase added or corrected.

### Stored state, and why the fingerprint earns its keep

§14.3 asks for three pieces of state per connection: the contract version
applied, a fingerprint of the live `se_*` field set, and when it was last
verified. `Schema\AppliedSchema` stores all three and reduces them to one of four
verdicts, as a pure function so every branch is testable without a server.

The fingerprint is the part that turned out to matter. A version alone cannot
distinguish "the schema is as we left it" from "somebody deleted a field last
week", and the first symptom of the second is indexing failing for reasons no
error message explains. The verdict order is deliberate: `behind` is reported
before `altered`, because a schema missing fields needs the same action either
way and telling an administrator their schema was tampered with when it is merely
out of date sends them looking for an intruder.

A fourth verdict, `unrecorded`, covers the schema that is adequate but was
applied by hand or before the plugin was installed. It is a different statement
from "verified", and conflating them would claim knowledge this site does not
have.

### Mode does not determine the rebuild strategy

§10.3 chooses between the alias strategy and the pointer strategy by **detected
mode**. That is necessary and not sufficient. Solr refuses `CREATEALIAS` when the
name is already held by a real collection, which is true on every site that has
not deliberately created an alias — so a SolrCloud site would be told it has
zero-downtime switching, build a replacement index for an hour, and fail at the
last step with a finished index nobody is reading.

`LifecycleBackend::rebuild_style()` therefore reports from what the server
actually has: it resolves the configured name against the cluster's aliases and
claims `STYLE_ALIAS` only when that name really is one. A SolrCloud site without
an alias gets `STYLE_POINTER`, which works — SolrEngine switches the collection it
is configured with — and the admin screen says which it will do before anything
is built. Detection over assumption, which is the same principle ADR-011 applies
to versions.

### Replacement indexes are never deleted automatically

§10.3 suggests deleting the previous collection "after a grace period". Phase 7
does not, and will not. A grace period means a plugin deleting a customer's index
on a timer, and the only reason the previous index exists is to be the way back
when something turns out to be wrong two days later. It is offered as an explicit
action and nothing else.

This is the same stance the migration table in §14.3 already takes on removing a
field — "Leave it. Removal is not worth the risk" — applied one level up. A
`drop_index()` call also refuses the index currently configured, so the last line
of defence does not depend on the UI never offering it.
