# ADR-011 — Version support policy

**Status:** Proposed · **Date:** 2026-09-19

## Context

SolrEngine sits between three independently versioned systems: WordPress, WooCommerce and Apache
Solr, on PHP runtimes the site owner chooses. Supporting everything is impossible; supporting only
the newest excludes most of the market.

Researched facts:

- **Solr.** 10.0.0 is the current stable line (released March 2026); 9.10.x is the last 9.x release
  and receives occasional critical fixes; everything below 9.10 is End of Life. Solr 10 requires
  Java 21, defaults to SolrCloud mode, removed `stream.file` / `stream.url` / `stream.body`, removed
  the PHP response writer, and requires base URLs ending in `/solr`. The CoreAdmin guide states
  SolrCloud users "should NOT use the CoreAdmin API at all", and that `SWAP` on a SolrCloud node
  "can result in the core being unusable".
- **WordPress.** 7.1 is current and requires PHP 7.4 or higher; 7.0 dropped PHP 7.2 and 7.3.
- **WooCommerce.** Documents PHP 8.3 as recommended and works on 7.4; requires WordPress 6.9+; has
  *proposed* raising its minimum to PHP 8.1 for the 11.5 release in January 2027.
- **Solarium.** 7.0.0 requires PHP ^8.1 — a constraint removed by ADR-001.

## Decision

| Dimension | Policy |
| --- | --- |
| **PHP** | Minimum **7.4**. Code written 8.x-clean. CI on 7.4, 8.1, 8.3, 8.5. Raise to 8.1 when WooCommerce does, not before |
| **WordPress** | Minimum **6.5**. Tested against current stable and trunk |
| **Solr** | **10.x recommended, 9.10+ supported.** 9.0–9.9 best effort. 8.x and older warned at connection test, not blocked |
| **SolrCloud and user-managed** | Both supported, detected at connection time, behaviour adapted |
| **WooCommerce** | Current stable and the previous minor |
| **Multisite** | Data model from v1; supported configuration from P8 |

Implementation consequences recorded now so they are not rediscovered:

- Request JSON responses only. Never the PHP response writer, which Solr 10 removed.
- Never rely on `stream.body`.
- Normalise the base URL to end in `/solr`; build request paths per request from an immutable base.
- Probe `/admin/info/system` once per connection change, cache version and mode, and gate features
  on it. Never probe per request.
- Aliases for zero-downtime reindexing in SolrCloud only. In user-managed mode, switch SolrEngine's
  own configured core rather than issuing a Solr `SWAP`.
- Never issue CoreAdmin operations against a SolrCloud node.

## Alternatives considered

**PHP 8.1 minimum.** Tempting — better types, enums, readonly properties, and it matches Solarium.
Rejected: ADR-001 removes the dependency that forced it, and excluding PHP 7.4 hosts excludes sites
that both WordPress and WooCommerce still support. The floor should be raised for the product's own
reasons, on the ecosystem's schedule.

**Solr 10 only.** Rejected. 10.0.0 is roughly six months old; 9.10.x is still receiving critical
fixes, and many production clusters will not have upgraded, particularly given the Java 21
requirement.

**Supporting Solr 8.x.** Rejected as a supported configuration. It is End of Life, so supporting it
means carrying compatibility code for installations that should be upgraded for security reasons
regardless. Warn, explain, do not block.

**Following WordPress's absolute minimum (currently older than 7.4).** Rejected. WordPress 7.0
already dropped PHP 7.2 and 7.3, so 7.4 is the effective floor for any site on a current WordPress.

## Consequences

**Positive.** The support matrix is small enough to test honestly in CI and wide enough to cover the
realistic market. Feature detection rather than version assumptions means an unexpected Solr
behaves predictably. The policy is published, so expectations are set before installation rather
than in a support ticket.

**Negative.** PHP 7.4 means forgoing enums, constructor promotion, readonly properties and union
types until the floor rises. Supporting two Solr major versions means two CI targets and occasional
branching in the transport, confined to the adapter by ADR-007.

**Neutral.** The PHP floor is explicitly scheduled to rise with WooCommerce's, which makes it a
planned migration rather than an argument to revisit each release.

## Why

Each floor is pinned to something external and defensible rather than to preference: PHP to what
current WordPress requires, Solr to what upstream still supports, WooCommerce to what its own team
ships. That makes the policy explainable to users, stable against internal debate, and
automatically revisable when the external fact changes.
