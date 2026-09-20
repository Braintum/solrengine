# Compatibility

What SolrEngine supports, what it is tested against, and what it bundles.

The policy behind this table is [ADR-011](../.claude/plans/adr/ADR-011-version-support-policy.md);
the numbers below are what CI actually runs.

## Requirements

| | Minimum | Recommended |
| --- | --- | --- |
| PHP | 7.4 | 8.3 |
| WordPress | 6.5 | Current stable |
| Apache Solr | 9.10 | 10.x |
| WooCommerce | Current stable, or the previous minor | Current stable |
| MySQL / MariaDB | Whatever your WordPress requires | |

SolrEngine needs no PHP extension beyond what WordPress itself requires. It uses
the WordPress HTTP API rather than cURL directly, and `sodium` — bundled with PHP
7.2 and later — for encrypting stored credentials. Where `sodium` is somehow
absent, credential storage reports itself unavailable and points you at
`wp-config.php` instead of failing quietly.

## Solr

| Version | Support |
| --- | --- |
| **10.x** | Recommended. Primary CI target |
| **9.10+** | Supported. Secondary CI target |
| 9.0 – 9.9 | Best effort. Not tested, not blocked |
| 8.x and older | Unsupported. Warned at connection test, not blocked |

Everything below 9.10 is End of Life upstream and receives no security fixes. The
connection test warns rather than refusing, because a plugin that blocks an
administrator out of their own working installation is worse than one that tells
them the truth.

Both deployment modes are supported — SolrCloud and user-managed — detected at
connection time rather than configured, with behaviour adapted per mode. Solr 10
defaults to SolrCloud.

Three Solr 10 changes SolrEngine is built around, recorded because each one breaks
plugins that assumed otherwise: the PHP response writer is gone (SolrEngine
requests JSON only), `stream.body` is gone (never relied on), and base URLs must be
root paths ending in `/solr` (normalised and validated once, then immutable).

**Languages needing a Solr module that is not loaded by default** — Japanese,
Chinese, Korean, Thai — have no analyser in the shipped configset. Content in them
is indexed and searchable; only stemming is missing. See
[multilingual](multilingual.md).

## PHP

CI runs the unit suite on **7.4, 8.1, 8.3 and 8.4**, and the integration suite on
7.4 with WordPress 6.5 (the oldest supported combination) and on 8.3 with current
and trunk. The trunk job is allowed to fail so a WordPress beta cannot block a
release.

The floor stays at 7.4 until WooCommerce raises its own, which is the constraint
that actually matters: a store cannot upgrade PHP for a search plugin. That costs
constructor property promotion, enums, readonly properties, match expressions and
named arguments — accepted deliberately, and the reason `FieldType` is a class of
constants rather than an enum.

Code is written 8.x-clean. PHPCompatibility checks the 7.4 floor in CI, so a
newer syntax cannot reach a release by being merged on a newer machine.

## WordPress

Minimum 6.5. Tested against current stable and trunk.

SolrEngine hooks WordPress at the *query* layer — one `posts_pre_query`
substitution — rather than at the template layer. That is why classic themes,
block themes, Elementor, Bricks and WooCommerce loops keep working: none of them
can tell the difference. It is also why compatibility with a page builder is not a
matrix entry. If a builder renders an ordinary `WP_Query`, it works.

Multisite is supported from 0.9.0, in both collection topologies. See
[multisite](multisite.md).

## Bundled libraries

| Library | Version | Licence | Why |
| --- | --- | --- | --- |
| [Action Scheduler](https://actionscheduler.org/) | ^3.9 | **GPL-3.0-or-later** | Reliable background indexing with claim isolation, which WP-Cron does not have |

**Action Scheduler is GPLv3, and that makes the distributed whole GPLv3.**
SolrEngine's own code is "GPLv2 or later"; combining it with a GPLv3 library means
the package as distributed is effectively GPLv3. Both licences are GPL-compatible
and this satisfies the directory's requirement that every bundled library be
GPL-compatible, but it is a real consequence of a real choice and is recorded here
rather than left for somebody to work out.

It is loaded defensively. Action Scheduler negotiates versions across every plugin
that ships it, so the copy that ends up running may belong to WooCommerce or to
another plugin entirely; requiring SolrEngine's copy adds it to that negotiation
rather than forcing it to win. A broken or partial copy does not fatal the site —
the queue reports itself unavailable and indexing falls back to WP-Cron, which the
dashboard says plainly.

**No other runtime dependency.** [ADR-001](../.claude/plans/adr/ADR-001-solr-client-strategy.md)
removed Solarium, which would have forced a PHP 8.1 floor and put Symfony packages
in every site's global namespace; SolrEngine uses a thin internal transport over
the WordPress HTTP API instead. `vendor/` for development holds only test and
analysis tooling, and none of it is shipped.

Build tooling is `@wordpress/scripts`, used at build time only. Compiled assets are
committed, and CI fails if the committed output does not match a fresh build.

## Optional integrations

| Integration | Detected by | Notes |
| --- | --- | --- |
| WooCommerce | Its own CRUD layer being present | Products indexed as soon as it is active; answering shop queries is opt-in |
| Polylang | `pll_*` functions | Read through its public functions only |
| WPML | `wpml_*` filters | Read through its filter API only |
| Action Scheduler | Version negotiation | Falls back to WP-Cron |
| Persistent object cache | `wp_cache_*` behaviour | Required for rate limiting to be enforced |

Each is read through the other plugin's own public API, never its tables or its
taxonomy terms. Those are implementation details that move between major versions,
and reaching for them is how an integration acquires a dependency on a release
nobody runs any more.

An absent integration is never an error. WooCommerce not being installed is the
normal case; Polylang not being installed means one language.

## What is not supported

- **Solr 8 and older.** Warned, not blocked. Several things SolrEngine relies on
  behave differently or are absent.
- **Shared hosting without Solr.** Most shared hosting does not provide it, and
  SolrEngine does not include one. A VPS, a container, or a managed Solr service is
  needed.
- **Network-wide search** across a multisite network. The data model supports it;
  the feature is not built. See [multisite](multisite.md).
- **Multi-currency WooCommerce.** Prices are indexed in the store's base currency.
  Documented as unsupported rather than approximated, because a wrong price is
  worse than no filter.
- **Attachment content.** Indexing a PDF's text needs extraction SolrEngine does
  not do, so attachments are left off the indexable list rather than indexed as a
  library of titles.
