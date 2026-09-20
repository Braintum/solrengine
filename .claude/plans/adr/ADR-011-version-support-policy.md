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

## Addendum, 2026-09-20 — the floor has to be pinned, not just declared

**Status:** the policy is unchanged. PHP 7.4 remains the floor. What changed is
that the repository now enforces it rather than asserting it.

### The failure

CI's PHP 7.4 job began failing with "your lock file does not contain a compatible
set of packages". Nothing about the plugin had changed. `composer.lock` had been
re-resolved on a PHP 8 machine, and Composer had therefore selected
`doctrine/instantiator` 2.x and `myclabs/deep-copy` 1.14 — both of which require
PHP 8 — as transitive dependencies of PHPUnit.

Two things are worth separating, because the error message conflates them:

- **No runtime dependency excluded 7.4.** The plugin still ran there. Only the
  test tooling could not be installed.
- **The declared floor was therefore untested**, which is the part that matters. A
  supported version nothing runs against is a claim, not a support commitment.

### The fix

`config.platform.php` in `composer.json`, set to the floor. Composer then resolves
as though it were running on 7.4 regardless of the machine, and the lock file is
installable everywhere the plugin claims to run. Two packages downgraded; PHPUnit
itself did not move.

It is also self-enforcing: the next `composer update`, on any machine, produces a
lock that still installs on 7.4. The previous arrangement depended on whoever ran
it remembering, which is not an arrangement.

A second benefit falls out. `composer install --no-dev` at release time resolves
against the floor too, so the shipped `vendor/` is correct by construction rather
than because the build machine happened to be old enough.

### The decision that was reconsidered and kept

Dropping 7.4 was considered, on the reasonable ground that it has had no security
support since November 2022. It was kept, because the reason for the floor was
never that PHP supports 7.4 — it is that **WooCommerce does**. WooCommerce 11.1.1
declares `Requires PHP: 7.4`, so a store on 7.4 can install WooCommerce today and
would not be able to install SolrEngine. That is the wrong way round for a plugin
whose priority persona in §6 runs a shop.

Q7's recorded trigger — "rising to 8.1 when WooCommerce does" — has not fired.
When it does, raise `config.platform.php` in the same commit as the header, the
readme, the CI matrix and `docs/compatibility.md`; the pin is the piece most
easily forgotten, and forgetting it fails CI in a way that does not name itself.
