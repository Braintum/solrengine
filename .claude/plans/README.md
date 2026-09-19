# SolrEngine — Planning Documents

Planning, architecture and feasibility phase. **No implementation work is authorised from these
documents yet.** They exist so that an implementation phase can start from a settled set of
decisions rather than from improvisation.

| Document | Purpose |
| --- | --- |
| [solrengine-plan.md](solrengine-plan.md) | The comprehensive plan: assessment, vision, requirements, feasibility, architecture, roadmap, risks. |
| [adr/](adr/) | Architecture Decision Records for the decisions that will be expensive to reverse. |

## Reading order

1. `solrengine-plan.md` §1 Executive summary — what SolrEngine should become.
2. `solrengine-plan.md` §2 Current repository assessment — what exists today and what survives.
3. `adr/ADR-001` … `ADR-011` — the eleven load-bearing decisions.
4. `solrengine-plan.md` §25–26 Roadmap and MVP — what to build first.
5. `solrengine-plan.md` §29 Open questions — what still needs a product decision from the owner.

## Status

| Field | Value |
| --- | --- |
| Phase | Planning |
| Date | 2026-09-19 |
| Plugin version assessed | 0.1.0 |
| Author | Planning pass, reviewed once against the source tree |
| Product name | **SolrEngine** — confirmed 2026-09-19, stray names removed from the source (see plan §2.6) |

## How claims are labelled

The plan separates four kinds of statement, and marks them explicitly:

- **Researched fact** — verified against official documentation, with a source link in §31.
- **Recommendation** — an architectural judgement made by this plan.
- **Assumption** — something taken as given because no decision exists yet. Listed in §29.
- **Future possibility** — deliberately not decided, and deliberately not built.

Numbers presented as performance targets are **proposed targets to validate**, never measurements.
