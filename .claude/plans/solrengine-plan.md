# SolrEngine — Product and Technical Architecture Plan

| | |
| --- | --- |
| **Status** | Planning. No implementation authorised from this document. |
| **Date** | 2026-09-19 |
| **Assessed version** | 0.1.0 (`solrengine.php`, 24 tracked files, ~1,100 lines of PHP) |
| **Scope** | Repository assessment, product definition, feasibility, architecture, roadmap, risks |
| **Companion** | [adr/](adr/) — eleven Architecture Decision Records |

**Claim labelling.** *Researched fact* = verified against official documentation, sourced in §31.
*Recommendation* = a judgement made by this plan. *Assumption* = taken as given, listed in §29.
*Future possibility* = deliberately not decided and not built. All performance numbers are
**proposed targets to validate**, not measurements.

---

## Table of contents

1. [Executive summary](#1-executive-summary)
2. [Current repository assessment](#2-current-repository-assessment)
3. [Product vision](#3-product-vision)
4. [Goals](#4-goals)
5. [Non-goals](#5-non-goals)
6. [Personas and use cases](#6-personas-and-use-cases)
7. [Functional requirements](#7-functional-requirements)
8. [Technical requirements](#8-technical-requirements)
9. [Feasibility analysis](#9-feasibility-analysis)
10. [Solr architecture](#10-solr-architecture)
11. [WordPress architecture](#11-wordpress-architecture)
12. [Indexing architecture](#12-indexing-architecture)
13. [Search architecture](#13-search-architecture)
14. [Schema strategy](#14-schema-strategy)
15. [WooCommerce architecture](#15-woocommerce-architecture)
16. [REST API strategy](#16-rest-api-strategy)
17. [Admin UX](#17-admin-ux)
18. [Security model](#18-security-model)
19. [Performance strategy](#19-performance-strategy)
20. [Multisite strategy](#20-multisite-strategy)
21. [SaaS architecture](#21-saas-architecture)
22. [Free and commercial strategy](#22-free-and-commercial-strategy)
23. [Testing strategy](#23-testing-strategy)
24. [Repository architecture](#24-repository-architecture)
25. [Phased roadmap](#25-phased-roadmap)
26. [MVP definition](#26-mvp-definition)
27. [Future roadmap](#27-future-roadmap)
28. [Risks](#28-risks)
29. [Open questions](#29-open-questions)
30. [Architecture decision records](#30-architecture-decision-records)
31. [Research sources](#31-research-sources)
- [Appendix A — WordPress.org compliance requirements](#appendix-a--wordpressorg-compliance-requirements)
- [Appendix B — Coding standards, tooling and dependencies](#appendix-b--coding-standards-tooling-and-dependencies)
- [Appendix C — Self-review log](#appendix-c--self-review-log)

---

## 1. Executive summary

SolrEngine should become a **search backend for WordPress that WordPress does not depend on**. It
replaces the relevance and speed of core WordPress search with Apache Solr, while leaving every
theme, template, page builder and WooCommerce surface working unchanged, and falling back to
native WordPress search the moment Solr is unreachable.

The three decisions that shape everything else:

**One.** The plugin hooks WordPress at the *query* layer, not the template layer. A single
`posts_pre_query` return substitutes Solr results into an ordinary `WP_Query`. Themes, block
themes, Elementor, Bricks and WooCommerce loops keep working because none of them can tell the
difference. Returning `null` from that same filter is the entire fallback story — graceful
degradation costs one line, not a subsystem.

**Two.** Arbitrary WordPress metadata is indexed through **Solr dynamic fields in a reserved
`se_*` namespace**, not through per-site schema generation. This converts the hardest problem in
the product — "index anything, on infrastructure we do not control, forever" — from a schema
migration nightmare into a naming convention. SolrEngine maintains its own schema contract
version, proposes additive changes through the Schema API, and never silently mutates a
customer's Solr.

**Three.** Indexing is asynchronous, queued, coalesced and resumable from day one. No content
change ever performs an HTTP request inside the request that saved it. A site with two million
posts and a site with two hundred use the same code path.

Three findings should change the current plan's assumptions:

- **The existing code is a prototype, not a foundation.** Its interfaces and layering are the
  right instinct, but the implementations contain correctness bugs that make the plugin
  non-functional as shipped: a fatal `TypeError` on every WooCommerce product save, an endpoint
  string that corrupts itself on each index call, a settings page wired to an option nobody
  reads, and deletions that never reach Solr at all. §2 itemises this. Keep roughly the shape,
  rewrite the substance.
- **`wp_safe_remote_get()` cannot be used to talk to Solr.** It rejects loopback and private IP
  ranges and permits only ports 80, 443 and 8080 — and the overwhelmingly common Solr deployment
  is `127.0.0.1:8983`. The standard WordPress SSRF mitigation is unavailable here, so SolrEngine
  must ship a purpose-built endpoint validator instead of assuming the platform provides one.
  This is the single most under-appreciated security requirement in the brief.
- **"Instant" search through the WordPress REST API has a latency floor WordPress imposes, not
  Solr.** Solr answering in 5 ms does not help when bootstrapping WordPress to serve the REST
  request costs an order of magnitude more on typical shared hosting. §19 treats this as a
  product constraint with three honest options rather than a tuning problem.

The recommended commercial architecture is a **fully functional free plugin** on WordPress.org
that speaks to a customer's own Solr, plus a genuine managed SaaS backend and separately
distributed add-on plugins. WordPress.org guideline 5 forbids locked functionality outright;
guideline 6 explicitly permits SaaS interfaces. The distinction that keeps SolrEngine compliant
is that SolrEngine Cloud is an *alternative backend*, never an unlock code. §22 develops this,
and it is an architectural constraint, not a pricing note: it is why the backend adapter seam
exists in the MVP rather than in version 2.

---

## 2. Current repository assessment

### 2.1 What is there

24 tracked files. Composer with PSR-4 autoloading under `SolrEngine\`, a hand-rolled service
container, a `Config` object with dot-notation access, four interfaces, two Solr client
implementations, an indexer, a searcher, a mapper, a settings page, a front-end controller, a
legacy widget, and a WooCommerce product indexer. Solarium 6.4.0 is vendored. There is no
`readme.txt`, no `package.json`, no test configuration, no CI, no `languages/`, no `assets/`,
no REST endpoint and no WP-CLI command.

### 2.2 What is sound

The **instincts** are right and should be preserved as instincts, not as code:

- Separating `SolrClientInterface`, `IndexerInterface`, `MapperInterface` and `SearcherInterface`
  anticipates exactly the backend abstraction the SaaS plan needs.
- A mapper that turns a `WP_Post` into a document, separate from the transport that ships it, is
  the correct decomposition.
- Constructor injection through a container, rather than singletons and global state, is
  unusually disciplined for a WordPress plugin.
- `IndexManager` registering an indexer per content type anticipates custom post types.
- Committing to Composer and PSR-4 from the start avoids a painful migration later.

### 2.3 What is broken

These are defects verified by reading the source, not style objections.

| # | Defect | Location | Consequence |
| --- | --- | --- | --- |
| 1 | `onProductSave()` passes a `WC_Product` object to `IndexManager::index()`, whose signature is `int $id` | `src/Plugin.php:224`, `src/Index/IndexManager.php:57` | **Fatal `TypeError` on every product save** when WooCommerce is active |
| 2 | `SolrClient::create()` appends the core to `$this->endpoint` and is called on every `index()` | `src/Solr/SolrClient.php:138`, `src/Solr/SolrIndexer.php:56` | Endpoint degrades to `…/solr/post/post/post/…`; indexing fails after the first document |
| 3 | `ProductIndexer::index()` builds an enriched document, discards it, and delegates to the base indexer | `src/WooCommerce/ProductIndexer.php:60-78` | Price, SKU and stock are **never indexed**; the WooCommerce integration is a no-op |
| 4 | `Mapper::mapProduct()` reads `$product->ID`, `->name`, `->price` from a `WC_Product` | `src/Utils/Mapper.php:62-71` | Returns an empty document; `WC_Product` exposes data through getters, not public properties |
| 5 | No `before_delete_post`, `wp_trash_post` or `transition_post_status` hook exists anywhere | verified by grep | **Deleted and unpublished content stays searchable forever.** A correctness *and* privacy defect |
| 6 | `SettingsPage` writes `solrengine_settings['solr_endpoint']`; `Config` reads `solrengine_solr_endpoint` | `src/Admin/SettingsPage.php:46`, `src/Plugin.php:85` | **The settings page has no effect.** Saving the endpoint changes nothing |
| 7 | `Config::loadFromEnv()` reads the environment variable `WPSOLR_SOLR_ENDPOINT` | `src/Plugin.php:91` | Adopts a competing commercial plugin's namespace. Remove before any public release |
| 8 | Rewrite rules are registered on `init` but flushed during activation, before `init` fires | `src/Front/SearchController.php:58`, `solrengine.php:51` | The `/solrengine-search/` route 404s until permalinks are manually re-saved |
| 9 | `commit=true` on every single-document update | `src/Solr/SolrClient.php:90,102` | A hard Solr commit per post save. The classic Solr performance anti-pattern |
| 10 | `SolrClient::request()` tests `false === $resp`, but `wp_remote_retrieve_body()` returns `''` on error and `is_wp_error()` is never branched on | `src/Solr/SolrClient.php:65-72` | Network and HTTP errors are silently swallowed and reported as success |
| 11 | `SolariumClient::create()` prefixes the core with `get_bloginfo('name')` | `src/Solr/SolariumClient.php:111-119` | Renaming the site orphans the entire index. Unsanitised for Solr core naming rules |
| 12 | `create()` calls `add_core_if_not_existing()`, issuing a CoreAdmin status request | `src/Solr/SolariumClient.php:102,124` | A Solr admin API round trip **on every request that touches the client** |
| 13 | Core auto-creation references a configset named `solrengine` | `src/Solr/SolariumClient.php:158` | The configset does not exist on any customer's Solr, so creation always fails |
| 14 | `SolariumClient::ping()` returns `bool`; `SolrClient::ping()` returns `array` | `src/Solr/SolariumClient.php:330` vs `SolrClient.php:129` | Interface contract violated; callers break when the client type changes |
| 15 | The user's raw query string is passed straight to Solr as `q` | `src/Solr/SolrSearcher.php:48-49` | **Solr query injection.** A visitor can submit local-parameter and boolean syntax that rewrites the query |
| 16 | `SearchController` passes `['rows' => 10]`; `SolrSearcher` reads `$options['params']` | `src/Front/SearchController.php:80`, `SolrSearcher.php:47` | Row limits are ignored |
| 17 | `renderResultsPage()` calls `get_header()`/`get_footer()` then `exit` | `src/Front/SearchController.php:98-128` | Breaks on block themes, which have no `header.php`; `exit` bypasses shutdown hooks and caching layers |
| 18 | `Mapper::collect_meta()` returns **all** post meta, including `_`-prefixed private keys | `src/Utils/Mapper.php:79-88` | Private meta and potential PII pushed into a search index |
| 19 | `get_post_time('c', …)` emits an ISO 8601 offset timestamp | `src/Utils/Mapper.php:38` | Solr date fields require `…Z` (UTC); strict date fields reject the value |
| 20 | `register_activation_hook()` called inside `Plugin::__construct()`, which runs on `plugins_loaded` | `src/Plugin.php:57-58` | Dead code. Activation runs in a request where `plugins_loaded` has not fired for this plugin |
| 21 | Document `id` is the bare post ID | `src/Utils/Mapper.php:32` | Collides across post types, across sites, and across any future object type |
| 22 | `sanitize_text_field()` applied to content being **indexed** | `src/Utils/Mapper.php:33` | Sanitisation is an input-storage concern; here it silently mangles searchable text |
| 23 | `composer.json` declares `php: >=7.4` but requires Solarium ^6.0; Solarium 6.3.7+ needs PHP 8.1+ | `composer.json` | The declared floor is unsatisfiable with the resolved dependency |
| 24 | `composer.lock` is in `.gitignore` and untracked | `.gitignore:4` | Builds are not reproducible |
| 25 | `tests/test-solarium.php` is a debug admin page, referenced by nothing | verified by grep | Dead file that Plugin Check will flag as an unexpected asset |
| 26 | `docs/SOLARIUM.md` and `examples/solarium-config.php` document a `solrengine_config` filter | verified by grep — not present in `src/` | Documentation describes an API that does not exist |
| 27 | ~~Three product names in one codebase: "SolrEngine", "WP Solr Search", "LOOP Search"~~ | `SettingsPage.php`, `SolariumClient.php` | **Resolved 2026-09-19.** The project name is **SolrEngine**; the other two were leftover strings. All display strings, log prefixes and the `WPSOLR_`-prefixed environment variable were renamed. See §2.6 |

### 2.4 Missing for a directory submission

`readme.txt` (mandatory), `Requires at least` / `Requires PHP` plugin headers, a `License` header
reading "GPLv2 or later" rather than `GPL2`, `uninstall.php`, `languages/`, `index.php` silence
files, and a documented disclosure of every outbound request. None of these are optional.

### 2.5 Verdict

**Keep the architecture sketch. Replace the implementation.**

| Disposition | Items |
| --- | --- |
| **Keep as concept, rewrite as code** | Interface-first layering, mapper/transport separation, container, per-type indexer registry |
| **Keep with modification** | `composer.json` (fix the PHP floor, add dev tooling, track the lock file), `phpcs.xml` (rename to `.dist`, reconsider the naming exclusions) |
| **Replace** | Both Solr clients, `SolrIndexer`, `SolrSearcher`, `Mapper`, `SettingsPage`, `SearchController`, `ProductIndexer`, `Config` |
| **Remove** | `SearchWidget` (legacy widget API), `tests/test-solarium.php`, `examples/`, `docs/SOLARIUM.md`, the `WPSOLR_` environment variable, the Solarium dependency (see ADR-001) |

This is roughly a rewrite that preserves about five percent of the lines and most of the intent.
That is the right call at 1,100 lines and the wrong call at 30,000 — which is precisely the
argument for settling the architecture now.

**Executed on 2026-09-19.** Every file in the Replace and Remove rows was deleted once the
Phase 0 foundation was in place and passing its gates. `src/` now holds only the new code. The
old files remain in git history for reference; see §25.1.

**Nothing in this document authorises deleting or rewriting any of it yet**, with the single
exception recorded in §2.6.

### 2.6 Naming decision, applied 2026-09-19

**The project name is SolrEngine.** Confirmed by the owner in response to defect #27. The other two
names in the codebase were leftover strings, not evidence of a licensing problem, which closes open
question Q12.

Ten occurrences across four files were renamed. This is the only change made to production code
during the planning phase, and it is deliberately confined to labels and identifiers:

| File | Change |
| --- | --- |
| `src/Admin/SettingsPage.php` | Menu title, page title, heading and a docblock: "WP Solr Search" → "SolrEngine" |
| `src/Solr/SolariumClient.php` | Three WP-CLI log prefixes ("LOOP Search") and one `error_log` prefix → "SolrEngine" |
| `src/Plugin.php`, `src/Config.php` | Environment variable `WPSOLR_SOLR_ENDPOINT` → `SOLRENGINE_SOLR_ENDPOINT` |

The first two rows are cosmetic. **The third is a behavioural change**: any environment configured
with the old variable name stops being read. That is intentional — it was defect #7, a competing
commercial plugin's namespace — and it is safe here because the plugin is unreleased at 0.1.0.

No logic, structure, hook or contract was touched. Every defect in §2.3 other than #7 and #27
remains open and is addressed by the roadmap, not by this change.

**Naming conventions that follow**, to be applied from P0 onward:

| Surface | Form |
| --- | --- |
| Product and display name | `SolrEngine`, one word, capital S and E |
| PHP namespace | `SolrEngine\` |
| Text domain, slug, option and table prefixes, hooks, CLI namespace | `solrengine` |
| Constants and environment variables | `SOLRENGINE_` |
| Solr field namespace | `se_` (§10.1) |

The text domain, slug and namespace were already correct and need no migration.

---

## 3. Product vision

> SolrEngine makes WordPress search fast, relevant and filterable at any content scale, without
> making the site depend on the search infrastructure to stay online.

Two deployment shapes, one plugin:

```
Self-hosted (v1)                  Managed (v2)

WordPress                         WordPress
    |                                 |
SolrEngine                        SolrEngine
    |                                 |
Customer's Solr                   SolrEngine Cloud API
                                      |
                                  Managed Solr
```

The seam between "SolrEngine" and whatever sits beneath it is the product's most valuable
structural asset. It is what permits a hosted offering later, what keeps the WordPress
integration testable without a running Solr, and what lets an entirely different engine be
introduced without rebuilding the plugin. It must exist in the MVP even though the MVP ships only
one adapter.

### What SolrEngine is not

It is not a Solr administration console. It is not a general-purpose Solr proxy. It is not a
replacement for WordPress's data layer. Search results are a *view*; WordPress remains the system
of record, and the index is always reconstructible from it.

---

## 4. Goals

| # | Goal | How it is measured |
| --- | --- | --- |
| G1 | Search quality that is visibly better than core WordPress search | Partial matching, stemming, field boosting, phrase handling, typo recovery |
| G2 | Search speed that stays flat as content grows | Query latency does not degrade materially between 10k and 1M documents |
| G3 | WordPress keeps working when Solr does not | Every search path has a native fallback; no fatal errors, no white screens |
| G4 | Index anything a site actually has | Posts, pages, CPTs, taxonomies, allowlisted meta, WooCommerce products |
| G5 | Usable by someone who is not a Solr engineer | Connect, choose content, index — three steps, no XML editing |
| G6 | Not restrictive for someone who *is* a Solr engineer | Field mapping, boosts, analyzers and raw configset control remain reachable |
| G7 | Accepted into the WordPress.org directory, and stays accepted | Plugin Check clean, guideline-compliant, security-reviewable |
| G8 | Supports a commercial future without compromising G7 | Backend adapters, add-on contracts, documented external services |
| G9 | Maintainable by a WordPress developer who did not write it | Conventional structure, typed contracts, real tests, documented decisions |

---

## 5. Non-goals

Explicitly out of scope, and worth stating so they do not creep in:

- **Managing the customer's Solr infrastructure.** SolrEngine configures its own index; it does
  not install, upgrade, tune or monitor Solr itself.
- **Being the site's data store.** Solr holds a projection. Losing the index loses nothing.
- **Replacing WooCommerce's cart, pricing or tax engines.** SolrEngine indexes what those engines
  compute; it never recomputes them.
- **A visual Solr schema editor in v1.** §14 explains why this is a trap.
- **Search analytics in the free plugin.** §22 and §27.
- **Semantic or vector search in v1.** §27. The architecture must not preclude it; the roadmap
  must not promise it.
- **Supporting every Solr version ever released.** §10.6 sets a concrete policy.
- **Replacing WooCommerce's filter blocks with a parallel UI in v1.** §15.6.

---

## 6. Personas and use cases

**Priya — content site owner, 12,000 posts.** Core search returns nothing useful for two-word
queries. She has managed WordPress hosting with no Solr. *She is the reason SolrEngine Cloud
exists.* Until it does, she is not a reachable customer, and the plan should say so rather than
pretend a "just point it at Solr" flow serves her.

**Marek — agency developer, builds for clients.** Runs Solr in Docker alongside staging. Wants
WP-CLI, deterministic reindexing, hooks to add custom fields, and a plugin that does not break
the client's page builder. He is the **primary MVP persona**: he can supply the infrastructure,
he will file good bug reports, and he chooses the plugin for dozens of sites.

**Dana — WooCommerce store owner, 40,000 SKUs, 300,000 variations.** Layered navigation times
out; filtered category pages take eight seconds. She will pay, and her problem is *filtering*
more than *searching*. She is the strongest commercial signal in the brief and the reason
WooCommerce is phase 6 rather than phase 9.

**Sam — enterprise platform engineer, multisite network, SolrCloud with authentication.** Needs
per-site isolation, no surprise admin API calls against his cluster, and credentials that are not
sitting in `wp_options` in plaintext. He is the reason §18 is long.

**Core use cases.** Site-wide search replacement · filtered product catalogue browsing ·
autocomplete in a header search box · a custom front end driven entirely by REST · scripted bulk
reindex after a content migration · network-wide search across a multisite.

---

## 7. Functional requirements

Priority: **M** = MVP, **1** = version 1.x, **F** = future.

### Connection and setup

| ID | Requirement | Pri |
| --- | --- | --- |
| F-01 | Configure Solr base URL, collection/core, timeouts, batch size | M |
| F-02 | HTTP Basic authentication; credentials settable via `wp-config.php` constants | M |
| F-03 | Connection test reporting reachability, auth, collection existence, Solr version, and Schema API availability as distinct results | M |
| F-04 | Detect Solr version and operating mode (SolrCloud vs user-managed) and adapt behaviour | M |
| F-05 | Ship a reference configset for self-hosted installs | M |
| F-06 | Client-certificate and JWT authentication | F |

### Schema

| ID | Requirement | Pri |
| --- | --- | --- |
| F-10 | Maintain a versioned schema contract independent of the plugin version | M |
| F-11 | Compare the live Solr schema against the contract and report the difference | M |
| F-12 | Apply additive schema changes through the Schema API on explicit admin confirmation only | M |
| F-13 | Export the required schema as copy-pasteable configuration for locked-down installs | M |
| F-14 | Detect that a contract change requires a reindex, and say so plainly | 1 |
| F-15 | Zero-downtime reindex via collection alias switching (SolrCloud) | 1 |

### Indexing

| ID | Requirement | Pri |
| --- | --- | --- |
| F-20 | Select which post types are indexed | M |
| F-21 | Index core post fields, taxonomy terms, and author | M |
| F-22 | Index an explicit allowlist of post meta keys with declared types | M |
| F-23 | Full synchronisation that is batched, resumable, cancellable and progress-reporting | M |
| F-24 | Incremental indexing on create, update, delete, trash, untrash and status change | M |
| F-25 | All indexing asynchronous; no outbound request during a content save | M |
| F-26 | Retry with backoff; failures recorded and inspectable | M |
| F-27 | Reconciliation job detecting drift between WordPress and the index | 1 |
| F-28 | Index taxonomy terms and users as first-class object types | 1 |
| F-29 | Index attachment text content | F |

### Search

| ID | Requirement | Pri |
| --- | --- | --- |
| F-30 | Replace the main WordPress search query with Solr results | M |
| F-31 | Automatic fallback to native WordPress search on any backend failure | M |
| F-32 | Relevance: field boosting, phrase boost, configurable minimum-match, stemming, stopwords | M |
| F-33 | Pagination, total counts, and sorting by relevance, date or title | M |
| F-34 | Result highlighting | 1 |
| F-35 | Typo tolerance via a no-results fuzzy retry | 1 |
| F-36 | Synonyms | 1 |
| F-37 | Recency boosting | 1 |
| F-38 | Per-language analyzed fields and language-aware querying | 1 |

### Facets, instant search, front end

| ID | Requirement | Pri |
| --- | --- | --- |
| F-40 | Server-declared facets: terms, range, and hierarchical taxonomy | 1 |
| F-41 | Multi-select faceting with correct counts | 1 |
| F-42 | Autocomplete/suggest endpoint | 1 |
| F-43 | Instant-search UI component with debounce, cancellation and keyboard accessibility | 1 |
| F-44 | Gutenberg blocks: search box, results, facet | 1 |
| F-45 | Shortcodes equivalent to the blocks | 1 |

### WooCommerce

| ID | Requirement | Pri |
| --- | --- | --- |
| F-50 | Index products with SKU, prices, stock status, attributes, categories, tags, brands, ratings, visibility | 1 |
| F-51 | Denormalise variation data onto the parent product document | 1 |
| F-52 | Respect catalogue visibility, out-of-stock hiding, and product status | 1 |
| F-53 | Power product search and catalogue filtering through Solr | 1 |
| F-54 | Integrate with the Product Collection block's query hook | 1 |
| F-55 | Price range and attribute facets with counts | 1 |
| F-56 | Multi-currency support | F |

### Platform

| ID | Requirement | Pri |
| --- | --- | --- |
| F-60 | REST endpoints for search and status | M |
| F-61 | REST endpoints for suggest and facets | 1 |
| F-62 | WP-CLI: `status`, `test`, `index`, `reindex`, `delete`, `schema` | M |
| F-63 | WP-CLI: `search` for debugging | 1 |
| F-64 | Admin dashboard: connection, index, queue, failures, last sync | M |
| F-65 | Documented action and filter extension points | M |
| F-66 | Multisite: per-site configuration with network defaults | 1 |
| F-67 | Full internationalisation | M |

---

## 8. Technical requirements

### Platform support

*Researched facts.* WordPress 7.1 is the current release and requires PHP 7.4 or higher;
WordPress 7.0 dropped PHP 7.2 and 7.3. WooCommerce documents PHP 7.4 as its working minimum with
PHP 8.3 recommended, WordPress 6.9+, MySQL 8.0+ or MariaDB 10.6+, and has *proposed* raising its
minimum to PHP 8.1 for the WooCommerce 11.5 release in January 2027.

*Recommendations:*

| Dimension | v1 policy | Rationale |
| --- | --- | --- |
| PHP | Minimum **7.4**, code written 8.x-clean, CI matrix 7.4 / 8.1 / 8.3 / 8.5 | Matches the WordPress floor. Raise to 8.1 when WooCommerce does, not before, and not because a dependency demands it |
| WordPress | Minimum **6.5** | `Requires Plugins` header support matters for the future add-on model; every API used is older than this. Note that WooCommerce itself requires 6.9, so the WooCommerce integration effectively raises the floor on stores — the core plugin does not need to |
| Solr | **9.10+ and 10.x**, feature-detected | Per the Solr download page, 10.x is the current stable line, 9.10.x is the last 9.x and receives critical fixes, and everything older is End of Life |
| MySQL | Whatever WordPress supports | SolrEngine adds two small tables and no exotic SQL |
| Browsers | Evergreen + Safari 2 versions back | No IE, no polyfill budget |

### Architectural requirements

- **No runtime PHP dependency beyond Action Scheduler.** See ADR-001 and ADR-004.
- **Every outbound request is to an administrator-configured host.** No phone-home, no telemetry,
  no CDN asset in v1. This is a WordPress.org requirement (guideline 7), not a preference.
- **All contracts live in one namespace** (`SolrEngine\Contracts`) and are treated as semantically
  versioned public API from v1.0 onward, because add-ons and the Cloud adapter will depend on them.
- **The WordPress layer never sees Solr syntax.** No `fq`, no `q`, no local parameters above the
  backend adapter. This is what makes ADR-007 achievable later.
- **No fatal error may originate from an unreachable backend.** Every backend call returns a
  result object or a typed failure; nothing throws across the integration boundary.

---

## 9. Feasibility analysis

Legend: ✅ strongly feasible · ⚠️ feasible with limitations · ⚠️⚠️ requires careful architecture ·
❌ not recommended.

| Capability | Verdict | Reasoning |
| --- | --- | --- |
| **Replacing WordPress search** | ✅ | `posts_pre_query` (WordPress 4.6+) short-circuits `WP_Query::get_posts()` entirely. Returning `null` restores default behaviour, which makes the fallback path free rather than engineered |
| **Indexing posts, pages, CPTs** | ✅ | Ordinary WordPress data with well-defined hooks. The only real work is doing it asynchronously |
| **Indexing taxonomies and terms** | ✅ | Denormalise onto the post document for filtering; index terms as their own object type for term search |
| **Indexing arbitrary metadata** | ⚠️⚠️ | Feasible *only* via dynamic fields and an explicit allowlist. Auto-indexing all meta is ❌ — it leaks private `_`-prefixed keys, bloats the index, and creates unresolvable type conflicts |
| **Solr dynamic fields for metadata** | ✅ | The single highest-leverage decision in the plan. Arbitrary keys become `se_meta_{key}_{typesuffix}` with no schema mutation at all |
| **Schema API management** | ⚠️ | Available, and documented as requiring `ManagedIndexSchemaFactory` (the modern default). But Solr 10 treats all configsets as trusted and requires authenticated admin access, and the guide is explicit that schema changes never rewrite indexed documents. Safe only as an additive, opt-in, admin-confirmed operation |
| **Schemaless mode** | ❌ | Type inference from the first value observed is a data-dependent time bomb: one `"N/A"` in a field Solr guessed was a date fails indexing permanently. The guide also warns that generated copyField rules slow indexing and inflate index size |
| **Auto-creating cores/collections** | ⚠️⚠️ | The CoreAdmin guide states SolrCloud users "should NOT use the CoreAdmin API at all", and SolrCloud is the default startup mode in Solr 10. Creation must be mode-aware, opt-in, and never a side effect of a page load — which the current code does on every request |
| **Instant search / autocomplete** | ⚠️ | Solr side is ✅ (Suggester with `AnalyzingInfixLookupFactory`, or edge n-grams). The constraint is WordPress bootstrap latency in front of it, not Solr. See §19.4 |
| **Faceting** | ✅ | The JSON Facet API covers terms, range, nested sub-facets, statistics and per-facet domains. Multi-select works through `{!tag=X}` filters plus `domain:{excludeTags:X}` |
| **Hierarchical taxonomy facets** | ✅ | Index materialised ancestor paths as a multi-valued string field and facet with a prefix |
| **WooCommerce product filtering** | ⚠️⚠️ | Solr is an excellent fit for the filtering workload. The difficulty is WooCommerce correctness — prices vary by tax configuration and customer context, visibility has four states, and variations complicate every aggregate. See §15 |
| **Price range filtering** | ⚠️ | Trivial in Solr. Non-trivial in WooCommerce, because the displayed price depends on tax settings and customer location. Index a documented base price and state the limitation |
| **Attribute filtering** | ✅ | Global `pa_*` attributes are taxonomies. Custom per-product attributes are meta and need the allowlist path |
| **Product variations** | ⚠️⚠️ | Parent-centric denormalisation is the right model. Child documents multiply index size and break facet counts if mixed carelessly. ADR-006 |
| **REST API** | ✅ | Straightforward, provided public endpoints accept only server-declared parameters. An endpoint that forwards Solr query syntax is ❌ unconditionally |
| **WP-CLI** | ✅ | The correct home for long-running indexing work, free of web-server timeouts |
| **Multisite** | ⚠️ | Architecturally cheap if `site_id` is in the document and every query filters on it from day one. Retrofitting it later is expensive. Build the field, defer the UI |
| **SolrCloud support** | ✅ | Collections API, aliases and JSON faceting all work. Requires avoiding CoreAdmin operations, particularly `SWAP`, which the guide says "can result in the core being unusable" |
| **User-managed (standalone) Solr** | ✅ | Supported, but aliases are a SolrCloud-only feature, so zero-downtime reindexing degrades to a configuration pointer switch |
| **Hosted SaaS backend** | ⚠️⚠️ | Feasible and permitted by WordPress.org guideline 6. The hard parts are privacy disclosure, data residency and operating cost, not the plugin code. §21 |
| **Free core + commercial add-ons** | ✅ | Guideline 5 explicitly permits "add-on plugins, hosted outside of WordPress.org". Locked features inside the free plugin are ❌ |
| **Semantic / vector search** | ⚠️ | Solr 10 ships vector search. The obstacle is embedding generation: cost, latency and sending content to a third party. Keep the seam, build nothing. §27 |
| **Rate limiting inside WordPress** | ⚠️ | Best-effort only. Without a persistent object cache, counters live in the options table or are lost. Do not present it as DoS protection |
| **Sub-50 ms end-to-end instant search** | ❌ | Not achievable through the WordPress REST API on typical shared hosting. §19.4 gives three honest alternatives |

---

## 10. Solr architecture

### 10.1 The document model

*Recommendation.* One Solr document per **indexable object**, not per database row, with a
deliberately search-oriented shape. Every field lives in a reserved `se_` namespace so SolrEngine
can share a collection with other applications without colliding, and so its own fields can be
identified, migrated and deleted as a set.

```
id                      "se:{site_id}:{object_type}:{object_id}"    e.g. "se:1:post:4211"

# identity and ownership
se_site_id_i            int      always written, always filtered on
se_object_type_s        string   post | term | user | wc_variation | ...
se_object_id_i          int      the WordPress ID
se_post_type_s          string   post | page | product | <cpt>
se_status_s             string   publish | private | draft | ...
se_visibility_s         string   public | private | password | restricted
se_lang_s               string   BCP-47 tag, "und" when unknown

# searchable text
se_title_txt            text     analysed, highest boost
se_excerpt_txt          text
se_content_txt          text
se_all_txt              text     copyField catch-all
se_suggest              text     suggester source

# sortable / display
se_title_s              string   docValues, for sorting
se_url_s, se_slug_s, se_thumbnail_url_s
se_author_id_i, se_author_name_s
se_date_dt, se_modified_dt      UTC, "…Z"
se_menu_order_i, se_comment_count_i

# taxonomy (per taxonomy, multi-valued, docValues)
se_tax_{tax}_ss         term names           faceting and display
se_tax_{tax}_ids        term IDs             filtering
se_tax_{tax}_path_ss    "1", "1/5", "1/5/9"  hierarchical faceting
se_tax_terms_txt        text     copyField aggregate of every taxonomy's term names,
                                 so relevance can weight taxonomy matches with a single
                                 `qf` entry — `qf` does not accept wildcard field names

# metadata (dynamic fields, allowlisted keys only)
se_meta_{key}_s   _ss   _i   _l   _f   _d   _b   _dt   _txt

# WooCommerce — see §15
se_wc_*

# bookkeeping
se_schema_version_i, se_index_version_i, se_indexed_dt
_version_               Solr-internal, enables optimistic concurrency
```

Why the composite `id`:

- **Collision-free** across post types, object types and sites — the current bare-post-ID scheme
  collides on all three axes.
- **Prefix-addressable.** Deleting one site's documents is a delete-by-query on `se_site_id_i`;
  no ID list to enumerate.
- **Stable across reindexes**, so an update is an upsert and a reindex is idempotent.
- **Forward-compatible** with object types that do not exist yet, and with SaaS multi-tenancy by
  prepending a tenant segment.

*Recommendation, stated explicitly:* do not mirror the WordPress database. `post_content_filtered`,
`ping_status`, `to_ping` and friends have no search value and cost index size. Index what is
searched, filtered, sorted, faceted or displayed — nothing else.

### 10.2 Collection topology

| Model | Advantages | Costs | Verdict |
| --- | --- | --- | --- |
| **One collection per WordPress site** | Isolated schema, relevance and configuration; deletion is dropping a collection; noisy-neighbour-free; simplest mental model | A 500-site network means 500 collections, which strains SolrCloud cluster state | **Default for v1** |
| **Shared collection, `site_id` filter** | One schema to manage; network-wide search is a filter change; efficient for many small sites | Shared relevance statistics across sites; one site's bulk reindex affects others; deletion is a delete-by-query | **Option for large multisite networks** |
| **Collection per content class** | Independent tuning for products vs articles | Cross-type search needs distributed queries; more moving parts | Not recommended |

*Recommendation.* The topology should be a **deployment choice, not an architectural one**. Because
`se_site_id_i` is written on every document and injected into every query unconditionally — even on
a single-site install where it is always `1` — moving between the two models requires no change to
the document model, the query builder, or any stored configuration beyond the collection name.
That property is worth the negligible cost of a redundant filter on single-site installs.

### 10.3 Index lifecycle

```
provision → verify schema contract → full sync → incremental sync
                                          ↑            |
                                          |            v
                                    reindex  ←  contract change requiring rebuild
                                          |
                        SolrCloud: build alongside → CREATEALIAS switch → drop old
                   user-managed: build into second core → repoint configuration
```

*Researched fact.* Solr collection aliases are a SolrCloud feature; the guide states a standard
alias "can be used to atomically swap which backing/underlying collection is 'live'". The CoreAdmin
guide separately warns "Do not use SWAP with a SolrCloud node."

*Recommendation.* Two lifecycle strategies, chosen by detected mode:

- **SolrCloud**: index into `solrengine_v{n+1}`, verify document counts against WordPress, then
  `CREATEALIAS` the serving alias onto it, then delete the old collection after a grace period.
  Genuinely zero-downtime.
- **User-managed**: index into a second core, verify, then switch the core name in SolrEngine's own
  configuration. A configuration pointer switch, not a Solr `SWAP` — it is reversible from
  WordPress and avoids a Solr operation whose safety varies by mode.

In both cases the *previous* index survives long enough to roll back. "Rebuild in place" remains
available for small sites that prefer simplicity, with an explicit warning that search quality
degrades while it runs.

### 10.4 Commit strategy

*Recommendation.* Never `commit=true` per document — the current code's largest performance defect.

| Situation | Strategy |
| --- | --- |
| Incremental updates | `commitWithin` of 60 seconds (configurable), no explicit commit |
| Full-sync batches | No commit; rely on `autoCommit` with `openSearcher=false` from the configset |
| End of a full sync | One explicit hard commit |
| Deletions | `commitWithin` of 5 seconds — removal from results should feel prompt |

The visibility lag this introduces is a product decision worth surfacing in the UI: "changes appear
in search within about a minute" is honest and cheap, whereas per-document commits make Solr slow
for everyone.

### 10.5 Connection management

Configuration surface: base URL, collection or core name, authentication mode, username, password,
connect timeout, read timeout, indexing batch size, and maximum query rows.

*Researched fact.* Solr supports Basic authentication via a `security.json` `BasicAuthPlugin`,
in both standalone and SolrCloud mode, plus JWT and certificate plugins and a `MultiAuthPlugin`.
Passwords travel in plain text, so the guide strongly recommends TLS.

*Recommendation.* v1 supports no-auth and HTTP Basic. Credentials come from `wp-config.php`
constants when defined, and from encrypted storage otherwise (§18.3). TLS is required whenever the
endpoint is not loopback, with an explicit, logged override for the rare private-network exception.

The connection test reports **independent** results rather than one boolean, because each failure
has a different remedy:

```
✓ DNS resolves and host is reachable          (network / firewall)
✓ TLS certificate valid                       (certificate)
✓ Authentication accepted                     (credentials)
✓ Collection "wordpress" exists               (provisioning)
✓ Solr 10.0.0, SolrCloud mode                 (compatibility)
✓ Schema API available (managed schema)       (schema strategy)
✓ JSON Facet API available                    (feature gating)
⚠ Suggester component not configured          (degraded feature, not an error)
```

### 10.6 Solr version compatibility policy

*Researched facts.* Solr 10.0.0 is the current stable line; 9.10.x is the last 9.x release and
receives occasional critical fixes; all versions below 9.10 are End of Life. Solr 10 requires
Java 21, defaults to SolrCloud mode, removed `stream.file`/`stream.url`/`stream.body`, removed the
PHP response writer, and requires base URLs to be root paths ending in `/solr`.

*Recommendation:*

| Version | Support level |
| --- | --- |
| Solr 10.x | **Recommended.** Primary CI target |
| Solr 9.10+ | **Supported.** Secondary CI target |
| Solr 9.0–9.9 | Best effort. Not tested, not blocked |
| Solr 8.x and older | Unsupported. Warn at connection test, do not hard-block |

Implementation consequences worth recording now:

- Never request the PHP response writer. JSON only — which is the only thing worth using anyway.
- Never rely on `stream.body`; it is gone in 10.
- Normalise the configured base URL to end in `/solr`, and validate that the collection path is
  appended once and only once. This is defect #2 in §2.3, and it should be structurally impossible
  in the new design: the base URL is immutable, and paths are built per request.
- Probe `/admin/info/system` once per connection change, cache the version and mode, and gate
  feature use on it. Never probe on every request, which is defect #12.

---

## 11. WordPress architecture

### 11.1 Layering

```
┌──────────────────────────────────────────────────────────────┐
│ WordPress surfaces                                           │
│  themes · blocks · shortcodes · WooCommerce · REST · WP-CLI  │
└───────────────────────────┬──────────────────────────────────┘
                            │
┌───────────────────────────▼──────────────────────────────────┐
│ Integration layer          — knows WordPress, not Solr       │
│  QueryIntegration · Content providers · Hook listeners       │
│  Admin screens · REST controllers · CLI commands             │
└───────────────────────────┬──────────────────────────────────┘
                            │  Contracts only
┌───────────────────────────▼──────────────────────────────────┐
│ Domain layer               — knows neither                   │
│  SearchQuery · SearchResults · Document · FieldMap           │
│  FacetDefinition · SchemaContract · IndexJob                 │
└───────────────────────────┬──────────────────────────────────┘
                            │  Contracts only
┌───────────────────────────▼──────────────────────────────────┐
│ Backend adapters           — knows the engine, not WordPress │
│  SolrBackend  ·  CloudBackend (future)                       │
└──────────────────────────────────────────────────────────────┘
```

The rule that makes this real: **the domain layer must be unit-testable with no WordPress and no
Solr loaded**, and the backend adapter must be testable with no WordPress loaded. If a `SearchQuery`
object needs `get_option()` to be meaningful, the seam has already leaked.

### 11.2 Bootstrap

The current bootstrap fails on three counts: it requires the autoloader unconditionally, it
registers activation hooks from a context where they cannot fire, and it constructs services
eagerly. *Recommendation:*

- Guard the autoloader. A missing `vendor/` must produce an admin notice, not a fatal error.
- Check the PHP version before loading any namespaced code, and deactivate gracefully below the
  floor.
- Register activation and deactivation hooks in the main plugin file only, at top level.
- Activation does the minimum: write a schema-contract marker, schedule nothing, contact nothing.
  **Activation must never make a network request** — it runs in a request the user is waiting on,
  and a firewalled Solr turns activation into a timeout.
- Build services lazily. Nothing should touch the database or the network until a hook actually
  fires.
- Load the text domain on `init`, per current WordPress guidance.

### 11.3 Extension points

*Recommendation.* Roughly a dozen deliberate hooks, not fifty incidental ones. Every hook is public
API from v1.0 and must be documented and tested.

| Hook | Type | Purpose |
| --- | --- | --- |
| `solrengine_indexable_post_types` | filter | Which post types are eligible |
| `solrengine_should_index_object` | filter | Veto a single object, with reason |
| `solrengine_document` | filter | Final document before it is queued. **The primary customisation point** |
| `solrengine_field_map` | filter | Declare additional field mappings |
| `solrengine_search_query` | filter | The domain `SearchQuery` before it reaches a backend |
| `solrengine_search_results` | filter | The domain `SearchResults` after a backend returns |
| `solrengine_facet_definitions` | filter | Register facets |
| `solrengine_should_handle_query` | filter | Veto SolrEngine taking over a specific `WP_Query` |
| `solrengine_backend_registry` | action | Register an additional backend adapter |
| `solrengine_admin_tabs` | filter | Register an admin screen (add-on seam) |
| `solrengine_indexed` | action | After a batch is written; `( $refs, $result )` |
| `solrengine_index_failed` | action | After a batch fails; `( $refs, $error )` |

Two deliberate omissions: no hook exposes a raw Solr request, and no hook lets a third party inject
query syntax. Both would make the injection guarantees in §18 unenforceable and would freeze the
Solr wire format as public API.

---

## 12. Indexing architecture

### 12.1 Pipeline

```
WordPress event
      │
      ▼
ChangeDetector ──► request-scoped dirty set  (coalesces N hooks → 1 entry)
      │
      ▼  on shutdown
JobQueue (Action Scheduler)  ──► claimed in batches
      │
      ▼
DocumentBuilder ──► FieldMap ──► transformers ──► `solrengine_document`
      │
      ▼
BulkWriter ──► backend adapter ──► Solr  (commitWithin, no per-doc commit)
      │
      ├─ success ──► run record updated
      └─ failure ──► retry with backoff ──► dead-letter after N attempts
```

### 12.2 Coalescing is the critical design element

A single `wp_update_post()` fires `save_post`, several `updated_post_meta` calls, `set_object_terms`
per taxonomy, and `transition_post_status`. A naive listener queues six jobs for one logical change.
On a bulk edit of 500 posts it queues thousands.

*Recommendation.* Hook listeners never queue directly. They add an object reference to a
request-scoped dirty set, deduplicated by reference. A single `shutdown` handler flushes the set
into one queued action per object. This turns hook noise into exactly one job per changed object,
and it is the difference between an importer running at normal speed and an importer crawling.

### 12.3 Events to observe

| Event | Hook | Action |
| --- | --- | --- |
| Create / update | `wp_after_insert_post` | Reindex. **Preferred over `save_post`** because it fires after meta and terms are written — using `save_post` indexes a document whose taxonomy and meta are still stale |
| Status change | `transition_post_status` | Index or remove depending on the new status's visibility |
| Trash / untrash | `wp_trash_post`, `untrashed_post` | Remove / reindex |
| Delete | `before_delete_post` | Remove. **Absent entirely from the current code** |
| Meta change | `added_post_meta`, `updated_post_meta`, `deleted_post_meta` | Mark dirty only if the key is in the allowlist |
| Terms | `set_object_terms`, `edited_term`, `delete_term` | Mark the object dirty; a term edit marks every attached object dirty via a background fan-out job |
| Author | `profile_update`, `deleted_user` | Fan-out job if author fields are denormalised |
| Featured image | `updated_post_meta` on `_thumbnail_id`, plus attachment edits | Mark dirty |
| Permalink structure | `permalink_structure_changed` | Full reindex required; URLs are denormalised |

The fan-out cases — renaming a term attached to 50,000 posts — must themselves be queued, batched
jobs. This is a place where a naive implementation writes a `WP_Query` with `posts_per_page => -1`
and dies.

### 12.4 Queue: Action Scheduler behind a contract

*Recommendation, see ADR-004.* Use Action Scheduler, accessed only through a `JobQueue` contract.

Why: it is a mature job queue built for distribution inside plugins, with retries, claim-based
concurrency, an admin UI and logging. It is already present on every WooCommerce site — a large
fraction of the target market. Rebuilding claim semantics and retry backoff correctly is more work
than it looks, and getting it subtly wrong produces duplicate or lost indexing.

Caveats to design around rather than discover:
- Action Scheduler is GPLv3. Combining it with a GPLv2-or-later plugin is permitted, and the
  effective licence of the distributed whole becomes GPLv3. Worth recording deliberately.
- Its version-negotiation loader means another plugin's copy may win. Initialise defensively and
  degrade to a WP-Cron driver if the expected API is absent.
- It depends on WP-Cron, which is disabled or unreliable on some hosts. The admin dashboard must
  detect a stalled queue and say so, and WP-CLI must be able to drain the queue directly.

The contract keeps a custom table driver, a WP-CLI-only driver, or a hosted worker available later
without touching any calling code.

### 12.5 Full synchronisation

*Recommendation.* Cursor-based, never offset-based.

```
run { id, object_types[], started_at, cursor, processed, failed, state }

each batch:
  SELECT ids WHERE type IN (...) AND id > cursor ORDER BY id ASC LIMIT n
  build documents · write batch · advance cursor · record counts
  reschedule self if work remains
```

Offset pagination (`paged`/`offset`) degrades quadratically on large tables and silently skips rows
when content changes mid-run. An ID cursor is stable, resumable after a crash, and cheap.

Batch mechanics:
- **Adaptive sizing.** Start at 100 documents; measure elapsed time and peak memory; scale toward
  a target of roughly 20 seconds and 60 percent of the memory limit per batch. Hard-coded batch
  sizes are wrong on both a 256 MB shared host and a 4 GB dedicated one.
- **Memory hygiene.** Query with `fields => 'ids'` and `no_found_rows => true`, hydrate in chunks,
  and call `clean_post_cache()` per chunk. Never `wp_cache_flush()` — on a site with a persistent
  object cache that evicts everyone's cache, which is a real and frequently-shipped bug.
- **Progress** is a stored run record, not a transient. It survives, it is queryable by CLI and
  REST, and it supports resume, cancel and restart.
- **Failure isolation.** One malformed document must not fail a batch of 100. On a batch rejection,
  retry the batch document-by-document, quarantine the offenders with their error, and continue.

### 12.6 Synchronous versus asynchronous

*Recommendation.* Asynchronous, always, with no supported synchronous mode in production.

The brief states a preference for resilient asynchronous architecture, and the research supports it
unambiguously. Synchronous indexing puts a network call with a multi-second timeout inside
`wp_insert_post()`. When Solr is slow, every editor's save is slow. When Solr is down, saves fail or
hang. This directly violates Principle 1, and there is no configuration flag that makes it safe.

A `SOLRENGINE_SYNC_INDEXING` constant may exist for integration tests and local development. It
should not appear in the settings UI.

### 12.7 Reconciliation

Indexes drift — a crashed worker, a direct database import, a restored backup, a manually deleted
collection. *Recommendation:* a low-frequency reconciliation job that compares WordPress counts per
post type against Solr facet counts, and sweeps for documents whose `se_indexed_dt` predates the
object's `post_modified_gmt`. Discrepancies queue repairs and surface on the dashboard. Without
this, silent staleness is undetectable until a customer notices, and by then trust is gone.

---

## 13. Search architecture

### 13.1 Layering

```
WordPress search query / REST request / block
              │
              ▼
      QueryIntegration          builds a domain SearchQuery from WP_Query vars
              │
              ▼
      SearchService             applies policy: visibility, site scope, permissions,
              │                 caching, fallback decision
              ▼
      SearchBackend (contract)  search(SearchQuery): SearchResults
              │
      ┌───────┴────────┐
      ▼                ▼
 SolrBackend      CloudBackend (future)
```

`SearchQuery` carries terms, filters, facet requests, sort, pagination, language and site scope as
structured values. `SearchResults` carries hydrated references, total count, facet results, timing
and the query that produced them. Neither type knows Solr exists — that is the whole point, and it
is what ADR-007 depends on.

### 13.2 WordPress integration: the recommendation

*Recommendation: Approach C, staged — query replacement first, own endpoints second.*

**Primary mechanism.** Mark the query in `pre_get_posts`, then return results from
`posts_pre_query`:

```php
// Decide, on pre_get_posts: is this a search query we should handle?
//   main query · is_search() · not admin · not a feed (configurable)
//   · not suppress_filters · passes solrengine_should_handle_query

// Then, on posts_pre_query:
//   query Solr → on success, return posts (respecting $query->get('fields'))
//                and set $query->found_posts and $query->max_num_pages
//   → on any failure, return null  ← WordPress runs its normal query
```

Why this and not the alternatives:

| Mechanism | Assessment |
| --- | --- |
| `posts_pre_query` | **Chosen.** Replaces the query without touching SQL. Themes, pagination, block themes and page builders are unaffected because they interact with `WP_Query`, not with the database. Returning `null` is a complete, zero-cost fallback |
| `posts_request` | Rejected. Replacing the SQL string forces a database round trip to fetch rows SolrEngine already has, and it is brittle against every plugin that also filters the clause |
| `pre_get_posts` alone | Insufficient. It can reorder by an ID list, but `post__in` ordering plus pagination plus counts is fragile and caps result sets |
| Own endpoint only | Rejected as the primary path. It abandons every existing theme search template — which is what the current code does, and why it renders broken output on block themes |

Two implementation details that matter: honour `$query->get('fields')` so `ids` and `id=>parent`
requests are not handed full objects, and prime the post cache with `_prime_post_caches()` for the
returned IDs so rendering does not issue one query per result.

**Secondary mechanism.** REST endpoints for instant search, facet UIs and headless front ends
(§16). These share the `SearchService`, so relevance and visibility rules cannot diverge between
the two paths.

### 13.3 Relevance

*Recommendation.* `edismax` with a configured field-boost profile and sane defaults:

| Parameter | Default | Purpose |
| --- | --- | --- |
| `qf` | `se_title_txt^5 se_excerpt_txt^2 se_content_txt^1 se_tax_terms_txt^1.5` | Field weighting. `qf` takes explicit field names, not wildcards, which is why §10.1 defines `se_tax_terms_txt` as a copyField aggregate of every taxonomy |
| `pf` | `se_title_txt^10 se_content_txt^2` | Phrase proximity boost |
| `mm` | `2<75%` | One or two terms must all match; beyond that, 75 percent |
| `tie` | `0.1` | Small credit for matches in secondary fields |
| `boost` | `recip(ms(NOW/HOUR,se_date_dt),3.16e-11,1,1)` | Optional recency. `NOW/HOUR` rounding keeps the filter cache usable |

Progressive strategies, applied in order and stopping at the first non-empty result:

1. Standard `edismax` with the profile above.
2. If zero results, retry with per-term fuzziness. *Recommendation:* this no-results retry rather
   than always-on fuzzy matching — always-on fuzziness degrades precision on every query to rescue
   the small fraction that need it, and it is measurably slower.
3. If still zero, retry with `mm=1` (any term matches).

Stemming, stopwords and synonyms are analyzer concerns configured in the shipped configset, not
runtime parameters. Exposing analyzer configuration in the admin UI is deliberately deferred: in
practice it requires uploading and reloading a configset, not a Schema API call, and the UI cost is
high while the audience is small.

### 13.4 Facets

*Recommendation.* The **JSON Facet API exclusively.** ADR-008.

It provides nested sub-facets, statistics, per-facet domain manipulation and a response structure
that maps cleanly onto a REST payload. The classic `facet.*` parameters offer nothing it lacks.

Multi-select faceting uses the documented tag/exclude pattern: the filter for a facet's own field is
tagged, and that facet's domain excludes its own tag, so selecting "Lenovo" leaves the other brand
counts visible.

```json
{
  "query": "{!edismax v=$se_q}",
  "filter": ["{!tag=brand}se_tax_brand_ids:42", "se_site_id_i:1"],
  "facet": {
    "brand": { "type": "terms", "field": "se_tax_brand_ss", "limit": 20,
               "domain": { "excludeTags": "brand" } },
    "price": { "type": "range", "field": "se_wc_price_min_f",
               "start": 0, "end": 2000, "gap": 100 },
    "price_stats": { "type": "query", "q": "*:*",
                     "facet": { "min": "min(se_wc_price_min_f)",
                                "max": "max(se_wc_price_min_f)" } }
  }
}
```

**The security rule that governs this entire feature: facet definitions live server-side, and the
API accepts only facet keys.** A client asks for `facets=brand,price`; it never sends facet JSON. A
client sends `filters[brand][]=42`; it never sends `fq`. This single constraint is what prevents
the search endpoint from becoming an unrestricted Solr proxy, and it is why §16 and §13 must be
designed together rather than sequentially.

Hierarchical taxonomy facets use the materialised path field: faceting on `se_tax_cat_path_ss` with
a prefix of `1/5/` yields exactly the children of term 5, with counts, in one request.

### 13.5 Instant search

*Recommendation for the Solr mechanism:* `AnalyzingInfixLookupFactory` via the Suggester component
for phrase-style suggestions, with edge n-grams on a dedicated field as the fallback where the
suggester is unavailable.

*Researched facts.* The Suggester requires an explicit build (`suggest.build=true`), and the guide
warns that `buildOnCommit` is not recommended for large indexes with frequent commits. FST-based
lookups have the lowest memory cost. `AnalyzingInfixLookupFactory` and `BlendedInfixLookupFactory`
support context filtering when backed by a `DocumentDictionaryFactory`.

*Recommendation.* Build the suggester on a schedule (a queued job), not on commit — otherwise every
post save triggers a dictionary rebuild, which on a large index is catastrophic. Context filtering
carries the site scope and post-type restriction so suggestions never cross boundaries.

Client behaviour: minimum two characters, 150 ms debounce, `AbortController` cancellation of
in-flight requests, a small in-memory cache keyed by the normalised prefix, and full
`role="combobox"` keyboard and screen-reader semantics. The accessibility work is not optional —
an inaccessible search box is the one component that reliably blocks adoption at institutional
customers.

The latency reality check belongs in §19.4 and should be read before promising "instant".

### 13.6 Failure behaviour

| Failure | Behaviour |
| --- | --- |
| Backend unreachable or times out | Return `null` from `posts_pre_query`; WordPress searches normally. Log once per interval, never per request |
| Backend returns malformed data | Same fallback, recorded as a distinct error class |
| Circuit open (N consecutive failures) | Skip the backend call entirely for a cooldown, avoiding a timeout per visitor while Solr is down |
| Authentication rejected | Fallback plus a persistent, dismissible admin notice — this needs human attention, unlike a transient timeout |
| Schema mismatch detected | Continue serving, warn in admin, offer migration |
| REST search endpoint, backend down | HTTP 503 with a machine-readable code and `Retry-After`. Do **not** silently return WordPress results through a Solr-shaped API, which would make the failure invisible to a client that depends on facets |

Principle 1 in one sentence: **the fallback path must be the cheapest path to implement, or it will
rot.** Hooking at `posts_pre_query` is what makes that true here.

---

## 14. Schema strategy

### 14.1 The five options assessed

| Option | Feasibility | Admin burden | Migration risk | Verdict |
| --- | --- | --- | --- | --- |
| **A. User-managed schema** | High | High — hand-edit XML, restart Solr | Low; changes are deliberate | Required as a **fallback** for locked-down and managed Solr |
| **B. SolrEngine-managed schema** | Medium | Low | High — the plugin mutates infrastructure it does not own | ❌ as the default |
| **C. Hybrid: owned namespace + Schema API** | High | Low | Low — additive, confirmed, reversible | ✅ **Recommended** |
| **D. Dynamic fields** | High | None | None — no schema change at all | ✅ **The mechanism inside C** |
| **E. Schemaless mode** | High initially | Low initially | Severe and delayed | ❌ |

Detail on the two rejections, because they are the tempting ones:

**Why not B.** Solr 10 treats all configsets as trusted and requires authenticated administrative
access; the Schema API needs a managed schema factory; and the guide is unambiguous that modifying
a schema never updates already-indexed documents. A plugin that mutates a shared production Solr
on activation will eventually break an unrelated application in the same cluster, and the customer
will be right to blame us. Capability plus consent, never capability alone.

**Why not E.** Schemaless infers a field's type from the first value it sees. A meta key that
usually holds `2024-01-01` becomes a date field; the first row containing `N/A` fails to index, and
every subsequent one does too — with an error that surfaces as "some products are missing from
search" weeks later. The guide additionally warns that generated copyField rules slow indexing and
inflate index size. Convenient at demo time, unsupportable in production.

### 14.2 The recommended strategy

**SolrEngine owns the `se_*` namespace and nothing else.**

1. **Dynamic fields carry all variable data.** The shipped configset declares roughly a dozen
   dynamic patterns (`se_*_txt`, `se_*_s`, `se_*_ss`, `se_*_i`, `se_*_l`, `se_*_f`, `se_*_d`,
   `se_*_b`, `se_*_dt`, `se_*_ids`). Every WordPress meta key, every taxonomy, every WooCommerce
   attribute and every field a third-party plugin adds maps onto an existing pattern. **Adding a
   new indexed field therefore requires no schema change whatsoever** — which is what makes "index
   arbitrary metadata" feasible rather than merely possible.
2. **Explicit fields exist only where a dynamic pattern genuinely will not do** — the suggester
   source, copyField targets, and fields needing bespoke analysis.
3. **The Schema API is used only to add missing `se_*` definitions**, only after showing the
   administrator the exact operations, and only on confirmation. SolrEngine never replaces or
   deletes a field it did not create, and never touches a field outside its namespace.
4. **A shipped configset** covers greenfield installs and locked-down environments. It is a real
   deliverable in `configsets/`, not a documentation snippet.
5. **A copy-pasteable export** covers customers whose Solr is managed by someone else entirely.

### 14.3 Schema contract versioning

*Recommendation.* SolrEngine maintains a `SCHEMA_CONTRACT_VERSION` integer that is **independent of
the plugin version**, because most plugin releases change nothing about the schema and coupling
them forces meaningless migrations.

```
plugin 1.4.2  ──declares──►  schema contract v3
                                   │
                    ┌──────────────┼──────────────┐
                    ▼              ▼              ▼
              required fields   analyzers   copyField rules
                    │
                    ▼
        compare against live schema  ──►  additive plan  ──►  admin confirms  ──►  apply
                    │
                    └─►  if a change is non-additive  ──►  rebuild + alias switch
```

Stored state per connection: contract version applied, a fingerprint of the live `se_*` field set,
and the timestamp of the last verification. Every document also carries `se_schema_version_i`, so a
partial reindex can target exactly the documents written under an older contract instead of
rebuilding everything.

Migration classification:

| Change | Additive? | Requires reindex? | Path |
| --- | --- | --- | --- |
| New dynamic field pattern | Yes | No | Schema API, confirmed |
| New explicit field | Yes | Only for existing documents needing it | Schema API, then targeted reindex |
| New copyField rule | Yes | Yes, for existing documents | Schema API, then full reindex |
| Change a field's type | **No** | Yes | Build a new index, switch the alias |
| Change analysis of an existing field | **No** | Yes | Build a new index, switch the alias |
| Remove a field | Yes, technically | No | Leave it. Removal is not worth the risk |

The last row is a deliberate stance: orphaned `se_*` fields cost almost nothing and deleting fields
that copyField rules reference fails in ways that are tedious to unpick.

### 14.4 Multi-site and SaaS implications

Per-site collections make schema management per-site, which is simple but multiplies migrations
across a large network — so network-wide migration must be a CLI operation from the outset, not a
per-site button.

In SaaS mode the schema problem largely disappears: SolrEngine Cloud controls its own Solr, applies
contracts server-side, and the plugin never sees a Schema API. That asymmetry is an argument for
keeping schema management strictly behind the backend contract rather than letting admin screens
call it directly — the Cloud adapter simply reports "managed" and the UI adapts.

---

## 15. WooCommerce architecture

WooCommerce is a first-class integration, not a custom post type with extra fields. The difference
is that product *correctness* has business consequences: a wrong price, a hidden product or a
stale stock status costs the merchant money, where a stale blog post costs nothing.

### 15.1 Read through CRUD, never through post meta

*Researched fact.* High-Performance Order Storage is the default storage for orders, and
WooCommerce 10.7 disabled sync-on-read by default, so code that writes to `wp_postmeta` and expects
it to appear in the modern tables now fails silently. Products themselves still live in the posts
tables today, but WooCommerce has pursued a data-store abstraction for years and the same migration
is the stated direction.

*Recommendation.* Read every product value through `wc_get_product()` and the CRUD getters. Never
read `_price`, `_sku` or `_stock_status` from post meta directly. This costs a little performance
during indexing and buys immunity from a storage migration that would otherwise silently empty the
index. Where bulk indexing performance demands it, batch-prime WooCommerce's own caches rather than
bypassing its API.

### 15.2 The variation problem

A store with 40,000 products and 300,000 variations has three modelling options:

| Model | Index size | Facet correctness | Verdict |
| --- | --- | --- | --- |
| Parent documents only, variation data denormalised | ~40k docs | Counts are per product, which is what shoppers expect | **Recommended** |
| Variation documents only | ~300k docs | Counts inflate; the same product appears repeatedly | Rejected |
| Both, in one collection | ~340k docs | Facet counts double-count unless every query filters by type | Rejected as a default |

*Recommendation (ADR-006).* Index the **parent product** as the searchable and filterable document,
denormalising from its variations:

```
se_wc_price_min_f / se_wc_price_max_f      across purchasable variations
se_wc_on_sale_b                            true if any variation is on sale
se_wc_stock_status_s                       instock if any variation is in stock
se_wc_variation_skus_ss                    every child SKU, for SKU search
se_wc_attr_{taxonomy}_ss                   union of attribute values across variations
se_wc_variation_count_i
```

A shopper filtering "16 GB RAM" and "in stock" gets products that *have* such a variation. That is
the expected behaviour and it is what WooCommerce's own attribute lookup table was built to
deliver. Where a merchant genuinely needs variation-level results, child documents can be added
later behind a setting, with `se_object_type_s` filtering keeping facet counts honest.

### 15.3 Fields to index

| Group | Fields |
| --- | --- |
| Identity | SKU, product type, parent ID, permalink, featured image |
| Text | name, description, short description, SKU (searchable), attribute values |
| Price | regular, sale, active, min/max across variations, on-sale flag, sale start/end dates |
| Stock | stock status, backorders allowed, and a **bucketed** availability level |
| Taxonomy | product categories with hierarchy paths, tags, brands, shipping class |
| Attributes | global `pa_*` taxonomies; custom per-product attributes via the meta allowlist |
| Commerce signals | average rating, review count, total sales, featured flag, date created |
| Visibility | catalogue visibility, post status, password protection |

*Recommendation on stock quantity:* index a **bucket** (`out`, `low`, `in`) rather than the exact
number, by default. Exact stock is commercially sensitive, it is the highest-churn field in the
catalogue — every order rewrites it — and re-indexing a product on every purchase is a
self-inflicted load problem on exactly the sites that can least afford it. Exact quantity remains
available as an opt-in for merchants who need it.

### 15.4 The price problem, stated plainly

WooCommerce prices are not a property of a product. The displayed price depends on the tax display
setting, the customer's country, their customer role, active coupons, and any multi-currency
plugin. A single indexed `price` field is therefore **an approximation**, and the plan should say
so rather than discover it in a support ticket.

*Recommendation.* Index the base price and, when the store displays tax-inclusive prices, a second
tax-inclusive field computed with the store's base tax rate. Use indexed prices for **filtering and
sorting** only, and render the displayed price through WooCommerce's own functions at output time.
Document the limitation: price filtering reflects base prices, which can differ from the displayed
price for customers in other tax jurisdictions. Multi-currency is out of scope for v1 with a
documented hook for plugin authors.

### 15.5 Visibility rules

Every product query must apply, server-side and unconditionally: catalogue visibility (`visible`,
`catalog`, `search`, `hidden` — and search and catalogue contexts differ), post status, the store's
"hide out of stock items" setting, and password protection. These are filters SolrEngine adds
itself; they are never client-supplied and never optional. A visibility bug here shows customers
products the merchant deliberately hid.

### 15.6 Front-end integration

*Researched fact.* WooCommerce provides a `woocommerce_product_collection_build_query` filter,
described as the place for developers to implement custom query generation, with the result passed
into WooCommerce's own query merging. The Product Filters block is built on the Interactivity API,
and WooCommerce has stated all new blocks will be.

*Recommendation, in priority order:*

1. **Integrate with what exists.** Hook `woocommerce_product_collection_build_query` for the
   Product Collection block and `woocommerce_product_query` for classic shop loops. Products appear
   through Solr with no theme change and no new UI.
2. **Provide facet data through REST** so themes, page builders and custom front ends can render
   filters however they wish.
3. **Ship SolrEngine's own filter blocks** only where WooCommerce's do not reach.
4. **Do not rebuild WooCommerce's filter UI in v1.** ⚠️ The Interactivity API surface is actively
   evolving through 2026; a parallel filter UI would be a permanent maintenance liability, and
   WooCommerce's own blocks are what merchants and theme authors already expect.

### 15.7 WooCommerce events

Beyond product saves: `woocommerce_update_product`, `woocommerce_new_product`,
`woocommerce_delete_product`, variation create/update/delete (each reindexing the **parent**),
`woocommerce_product_set_stock` and `woocommerce_variation_set_stock`,
`woocommerce_variable_product_sync_data` after price synchronisation, the `wc_scheduled_sales` cron
event for sale price transitions, term changes on `product_cat` / `product_tag` / `pa_*`, and
review approval where ratings are indexed.

Two dedicated anti-stampede measures: stock changes from orders should mark the product dirty for
the bucketed field only and can be coalesced aggressively, and `wc_scheduled_sales` must fan out as
a batched job rather than reindexing every scheduled product inline.

---

## 16. REST API strategy

### 16.1 Endpoints

| Route | Access | Purpose |
| --- | --- | --- |
| `GET /solrengine/v1/search` | Public | Search with server-declared filters, facets and sorts |
| `GET /solrengine/v1/suggest` | Public | Autocomplete |
| `GET /solrengine/v1/facets` | Public | Facet counts for a query without result documents |
| `GET /solrengine/v1/status` | `manage_options` | Connection, index, queue and schema status |
| `POST /solrengine/v1/connection/test` | `manage_options` | Run the connection diagnostic |
| `POST /solrengine/v1/index/full` | `manage_options` | Start, resume or cancel a full sync |
| `GET /solrengine/v1/index/progress` | `manage_options` | Progress of the current run |
| `POST /solrengine/v1/schema/apply` | `manage_options` | Apply the confirmed schema plan |

### 16.2 The rule that defines this API

**The public endpoints expose a search *product*, not a Solr *proxy*.** Concretely:

- `q` is user text. It is never interpolated into a Solr query string; it is bound as a separate
  parameter and referenced by the query template (§18.2).
- `filters` accepts only keys from the server-side facet registry, with values validated against
  each facet's declared type. Unknown keys are rejected, not ignored — silently ignoring them hides
  client bugs and makes probing cheap.
- `facets` accepts only registry keys. Facet JSON is never accepted from a client.
- `sort` accepts only values from a declared enum.
- `per_page` is clamped to 50; `page` is clamped so that `page × per_page` stays within a
  configured deep-paging ceiling, because deep paging is an inexpensive way to make Solr expensive.
- `fields` returned are restricted to those flagged returnable. A meta field can be searchable and
  filterable without being readable by the public.

If a parameter cannot be expressed as a constrained, enumerated value, it does not belong on a
public endpoint.

### 16.3 Authorisation, validation, errors

Every admin route declares a real `permission_callback` running `current_user_can()`. None ever
returns `__return_true` — a mistake common enough that WordPress core shipped a warning for it.
Admin routes also verify the REST nonce for cookie-authenticated requests.

Every parameter declares `type`, `sanitize_callback` and `validate_callback` in its `args`
definition, so validation is declarative and reviewable rather than scattered through handlers.

Errors are `WP_Error` with stable machine codes (`solrengine_backend_unavailable`,
`solrengine_invalid_filter`, `solrengine_rate_limited`) and messages that never echo internal
details — no Solr URLs, no stack traces, no raw backend responses. Leaking the Solr endpoint
through an error message hands an attacker the internal host name.

### 16.4 Rate limiting and caching

*Recommendation, with an honest limitation.* A token bucket keyed on route plus client IP, stored
in the object cache. Where no persistent object cache exists this degrades badly, and the
documentation must say so: it discourages casual abuse and is **not** DoS protection. Real
protection belongs at the CDN or web server.

Caching layers, from cheapest to most expensive:

| Layer | What | When |
| --- | --- | --- |
| Browser | `Cache-Control` on suggest responses, short | Anonymous visitors only |
| Object cache | Normalised query → results | Anonymous, public-only result sets |
| Object cache | Facet counts without a text query | These change slowly and are expensive |
| Solr | filter cache, query result cache | Configured in the shipped configset |

Cache keys include the schema contract version and an index generation counter, so a reindex or a
schema change invalidates everything at once without enumerating keys. **Never cache results for
logged-in users** whose visible content may differ, and never cache a response that included
non-public documents.

### 16.5 Versioning

`/v1/` is frozen on release: additive changes only, and a `/v2/` namespace for anything breaking,
with both served during a deprecation window. The response envelope is stable:

```json
{
  "results": [ { "id": 123, "type": "post", "title": "…", "url": "…", "score": 8.1,
                 "highlights": { "content": ["… <mark>term</mark> …"] } } ],
  "total": 482,
  "page": 1,
  "per_page": 10,
  "facets": { "category": { "buckets": [ { "key": "guides", "label": "Guides", "count": 31 } ] } },
  "took_ms": 14,
  "backend": "solr"
}
```

---

## 17. Admin UX

### 17.1 Information architecture

```
SolrEngine
├── Dashboard      status, document count, queue depth, failures, last sync, quick actions
├── Connection     endpoint, auth, timeouts, connection test, Solr version and mode
├── Content        which post types are indexed; meta allowlist; exclusion rules
├── Search         relevance profile, field boosts, fallback behaviour
├── Tools          full reindex, schema check and apply, export configuration, logs
└── Advanced       field mappings, facet definitions, analyzers (read-only in v1)
```

Six screens, not the twelve the brief sketched. `Fields`, `Facets` and `WooCommerce` fold into
`Content` and `Advanced`; `Jobs` and `Logs` fold into `Tools`; `Index` folds into `Dashboard`.
*Recommendation:* screens should be added when a section genuinely cannot fit, because every extra
top-level item makes the setup path longer for the person in §6 who is not a Solr expert.

### 17.2 Two modes, one screen

Rather than a global "simple/advanced" toggle — which forces users to guess which mode holds the
setting they need — each screen shows sane defaults with an "Advanced" disclosure inline. Priya
never opens them. Marek opens one on a screen he already found.

### 17.3 Setup flow

```
1. Connect      URL · collection · authentication          → Test connection
2. Verify       Solr version · mode · schema comparison    → Apply schema, or export it
3. Choose       post types · meta allowlist                → defaults preselected
4. Index        start full sync                            → live progress, safe to leave
5. Enable       take over WordPress search                 → off until an index exists
```

Two deliberate properties. **Step 5 is last and opt-in**: enabling search replacement before an
index exists produces an empty site, which is the single worst first impression this product can
make. And **each step is re-enterable** — nothing is a one-shot wizard, because real setups fail
at step 2 and resume a day later once a sysadmin has opened a firewall.

### 17.4 Dashboard

```
Connection    ● Connected · Solr 10.0.0 · SolrCloud · 14 ms
Index         124,382 documents · schema contract v3 (current)
Queue         31 pending · 2 failed            [ View failures ]
Last sync     2026-09-18 23:12 · 4m 21s · 0 errors
Search        ● Active · 1,284 searches today · 3.1% with no results
```

Every number is a link to the thing that explains it. The no-results percentage is the one
relevance metric worth showing in v1 — it is the fastest signal that the index or the analyzer
configuration is wrong, and it requires no query logging to compute if it is a simple counter.

### 17.5 Observability scope for v1

In: connection state, document count, schema version comparison, queue depth, failed jobs with
their errors, index run history, last error per category, and a debug mode behind a constant that
records query timings.

Out: query logging with terms (privacy and storage), click-through tracking, per-query analytics,
dashboards, charts. §27 and §22 place these in the commercial tier where they can be paid for,
which is also where their operating cost belongs.

Storage: run records and failures go in two small custom tables with automatic pruning. **Never in
the options table** — an autoloaded option that grows without bound is one of the most common
causes of WordPress performance collapse, and a log is exactly the shape that grows without bound.

---

## 18. Security model

### 18.1 SSRF — the headline risk

An administrator types a URL and the server makes a request to it. That is server-side request
forgery by construction, and the usual WordPress mitigation is unavailable here.

*Researched fact.* `wp_http_validate_url()` permits only `http` and `https`, rejects loopback
(127.0.0.0/8), private ranges (10/8, 172.16/12, 192.168/16), link-local (169.254/16), multicast and
reserved ranges unless the `http_request_host_is_external` filter overrides it, and permits only
ports 80, 443 and 8080 by default.

**The consequence: `wp_safe_remote_*()` cannot reach a typical Solr installation.** The canonical
deployment is `http://127.0.0.1:8983/solr` — blocked by the loopback rule *and* by the port rule.
Filtering `http_request_host_is_external` to allow it would weaken the protection for every other
plugin on the site, which is unacceptable.

*Recommendation.* A dedicated `EndpointValidator`, applied every time the endpoint changes and
again before each request:

| Control | Rule |
| --- | --- |
| Scheme | `http` or `https` only |
| Credentials in URL | Rejected — `http://user:pass@host` must not be a supported input |
| TLS | Required unless the host is loopback or the administrator explicitly acknowledges a private-network exception, which is recorded |
| Redirects | `redirection => 0`. A 3xx from the configured host is an error, never a hop |
| DNS | Resolve at validation time and record the address; reject if it resolves into a blocked range without acknowledgement |
| Always blocked | Cloud metadata addresses (`169.254.169.254`, `fd00:ec2::254`), regardless of acknowledgement |
| Private and loopback ranges | Permitted **only** with an explicit acknowledgement checkbox, because they are the normal case here |
| Ports | Configurable allowlist defaulting to 8983, 8443, 80, 443 |
| Response size | Capped; a multi-megabyte response to a ping is an error |
| Timeouts | Separate connect and read timeouts, both bounded |
| Capability | `manage_options` only; `manage_network_options` for network-level configuration |
| Connection test | Nonce-protected **and rate-limited** — an unlimited test endpoint is a port scanner with a WordPress login |

DNS rebinding remains a residual risk: the address checked at validation can differ from the
address connected to. Fully closing it requires pinning the resolved address per request, which
PHP's HTTP transports make awkward. *Recommendation:* implement resolve-and-compare at request
time, document the residual gap honestly, and note that the attacker must already hold
`manage_options` — which is close to full site control anyway. Precision about what a mitigation
does and does not cover is worth more than a claim of completeness.

### 18.2 Query injection

The current code passes the raw visitor query to Solr as `q`. Solr local parameters (`{!…}`) and
boolean syntax are then under visitor control.

*Recommendation.* Two layers:

1. **Parameter substitution.** The query template is server-owned and the user's text is bound
   separately: `q={!edismax v=$se_q}` with `se_q` sent as its own parameter. Text arriving through
   `$se_q` is not evaluated as query syntax at the top level, so `{!` in user input is not a local
   parameter.
2. **Input hygiene.** Strip leading local-parameter syntax, enforce a maximum length, normalise
   whitespace, and escape reserved characters in any value that becomes a term.

Everything else — `fq`, `sort`, `fl`, `facet`, `rows`, `start` — is built exclusively from
server-side definitions. **No user-supplied string is ever concatenated into a Solr parameter.**
Filter values are typed and escaped by the facet definition that owns them.

### 18.3 Credential storage

WordPress offers no secret store, so the honest options are ranked rather than solved:

1. **Best — `wp-config.php` constants.** `SOLRENGINE_SOLR_USER` and `SOLRENGINE_SOLR_PASSWORD`.
   Not in the database, not in a database dump, not editable through a compromised admin session.
   Documented as the recommended approach and detected automatically, with the UI showing "set in
   wp-config.php" and disabling the field.
2. **Acceptable — encrypted option.** Encrypted with `sodium_crypto_secretbox` under a key derived
   from `AUTH_KEY`/`SECURE_AUTH_KEY`, stored non-autoloaded. This protects against a leaked
   database dump and **not** against code execution, since the key lives in `wp-config.php`
   alongside the code. *Say this in the documentation.* Overstating it is worse than not doing it.
3. **Never** — plaintext, rendered back into a form field, included in a system-status export,
   written to a log, or exposed through any REST response.

The UI shows a masked value and a "replace" action. It never round-trips the secret to the browser.

### 18.4 Data exposure

*Recommendation: conservative by default.*

| Content | Default | Override |
| --- | --- | --- |
| Published, publicly viewable | Indexed | — |
| Drafts, pending, auto-drafts | Not indexed | None in v1 |
| Private posts | Not indexed | Opt-in, results filtered by capability at query time |
| Password-protected | Indexed as metadata only; **content excluded** | None |
| Trashed | Removed from the index | — |
| Scheduled | Not indexed until published; indexed by the publish transition | — |
| Post meta | **Nothing** indexed unless allowlisted | Explicit allowlist with declared types |
| `_`-prefixed private meta | Excluded from the allowlist UI by default | Explicit override, with a warning |
| User email, IP, capabilities | Never indexed | None |
| Hidden or private products | Not indexed | — |

The current `collect_meta()` — which returns every meta key including private ones — is the exact
anti-pattern this table exists to prevent. Indexing all meta by default puts licence keys, API
tokens, internal notes and personal data into a search index, and in SaaS mode it would put them on
someone else's server.

Two leakage paths that are easy to miss because they return no documents:

- **Facet counts disclose existence.** If private content is indexed under the opt-in, a facet count
  reveals how many private items match a filter even when none is returned. Any query that may
  match non-public documents must apply the visibility filter *before* faceting, not after — which
  it does naturally, because visibility filters are ordinary filter queries in the same request.
- **Cross-site leakage on a shared collection.** `se_site_id_i` is injected by the query builder
  unconditionally, never supplied by a caller and never removable through a hook. On a shared
  multisite collection this filter is the only thing separating tenants, so it is treated as a
  security control rather than a scoping convenience, and it is covered by the security test layer.

### 18.5 Admin surface

Nonces on every state-changing request. `current_user_can()` in every handler, not only in the
menu-registration capability. All output escaped at the point of output with the
context-appropriate function. All input sanitised on the way in and validated against an expected
shape. No `unserialize()` on anything that crossed the network — Solr responses are parsed as JSON
and nothing else. No `error_log()` in shipped code; logging goes through a logger that respects a
debug constant and redacts credentials.

### 18.6 Threat summary

| Threat | Severity | Mitigation |
| --- | --- | --- |
| SSRF via the endpoint field | **High** | §18.1 validator; `manage_options`; rate-limited testing |
| Solr query injection | **High** | Parameter substitution; server-built parameters only |
| Public endpoint as a Solr proxy | **High** | Registry-only filters and facets; no raw pass-through |
| Private content leaking into results | **High** | Conservative indexing defaults; visibility filters applied server-side |
| Credential theft from the database | Medium | Constants preferred; encrypted at rest otherwise |
| Endpoint abuse / resource exhaustion | Medium | Rate limiting, result caps, deep-paging ceiling, caching |
| CSRF on admin actions | Medium | Nonces plus capability checks |
| Information disclosure through errors | Medium | Machine codes; internal detail only under a debug constant |
| Malicious Solr response | Low | JSON-only parsing, size caps, strict typing of parsed values |
| Supply chain via dependencies | Low | Near-zero runtime dependencies (ADR-001); lock file committed; CI audit |

---

## 19. Performance strategy

### 19.1 Proposed targets

**These are targets to validate, not measurements.** Nothing here is benchmarked; they exist to
give the implementation phase something falsifiable to test against.

| Metric | Proposed target | Conditions |
| --- | --- | --- |
| Solr query time, p95 | < 50 ms | 100k documents, 5 facets, warmed caches |
| REST search round trip, p95 | < 300 ms | Dominated by WordPress bootstrap, not Solr |
| Suggest round trip, p95 | < 150 ms | Cached, anonymous |
| Added latency on post save | < 10 ms | A queue insert and nothing more |
| Full sync throughput | 200–1,000 docs/min per worker | Varies with meta extraction cost |
| Peak memory during indexing | < 60% of the PHP limit | Adaptive batch sizing |
| Index size | 2–4× raw content size | Highly content-dependent |

### 19.2 WordPress-side costs

The expensive parts of indexing are not Solr — they are `get_post_meta()`, `wp_get_object_terms()`
and `get_permalink()` called per document. Mitigations: prime meta and term caches for a whole
batch in one query rather than per document; compute permalinks without a per-post rewrite pass
where the structure allows; skip revision, autosave and importer contexts; and suspend cache
additions during bulk runs so a persistent object cache is not filled with objects nobody will read
again.

### 19.3 Network

Batch document writes (100 is the starting point, adapted at runtime). Enable gzip on request
bodies for large batches. Reuse connections where the transport allows. Retry with exponential
backoff plus jitter, and trip a circuit breaker rather than retrying into a dead server. Separate
connect and read timeouts, because a slow query and an unreachable host need different responses.

### 19.4 The instant-search latency reality

*This challenges the product assumption and should be read as such.*

A request to `/wp-json/solrengine/v1/suggest` bootstraps WordPress: PHP startup, autoloading, every
active plugin's `plugins_loaded`, the REST infrastructure, and only then a Solr call that may take
5 ms. On typical shared hosting, WordPress bootstrap commonly dominates that total by an order of
magnitude. **Solr's speed is not the binding constraint; WordPress's is.**

Three honest options:

| Option | Latency | Cost |
| --- | --- | --- |
| Standard REST endpoint | Acceptable with a persistent object cache and a debounce; poor without | None. The v1 choice |
| A lightweight entry point that loads a reduced WordPress stack | Better, unpredictable | Fragile, hard to keep compatible, and a reviewer will question it |
| The browser queries the search backend directly | Best by a wide margin | Requires a credentialed, rate-limited, publicly reachable endpoint — which is exactly what SolrEngine Cloud is |

*Recommendation.* Ship option 1 in v1 and set expectations accordingly: "fast autocomplete", not
"instant". Treat option 3 as a genuine differentiator for the Cloud product rather than a
nice-to-have — it is one of the strongest technical arguments for the SaaS offering, and it should
inform how §21 is designed rather than being discovered later.

### 19.5 Solr-side

Facet fields require `docValues`, which the dynamic-field naming conventions enforce by
construction. Prefer filter queries over query clauses for anything reusable, since the filter
cache is per-filter. Round date filters (`NOW/DAY`) so they are cacheable at all. Avoid leading
wildcards. Prefer the no-results fuzzy retry over always-on fuzziness. Cap facet limits and
`rows`. Deep paging beyond the configured ceiling is refused rather than served slowly.

### 19.6 Browser

Debounce at 150 ms. Cancel in-flight requests with `AbortController`. Cache by normalised prefix in
memory. Request only the fields the UI renders. No framework for the v1 search components — a
search dropdown does not justify a runtime, and a plugin that ships React for an input box will be
criticised for it, fairly.

---

## 20. Multisite strategy

*Recommendation: build the data model for multisite now, build the UI later.*

What is cheap today and expensive to retrofit:

- `se_site_id_i` on every document, always.
- Site scope in every query, always — even on single-site installs, where it is a constant.
- Configuration readable from either site options or network options through one accessor.
- Document IDs that already namespace the site.

What waits until the multisite phase: the network administration screen, network-wide search,
per-site override UI, and network-level reindex orchestration.

| Deployment | Recommendation |
| --- | --- |
| Small network, shared content model | One collection, filtered by `se_site_id_i` |
| Large network, heterogeneous sites | One collection per site |
| Network-wide search | Requires the shared collection, or a distributed query across collections |

Network activation should configure a network-level connection with per-site opt-in, because a
network administrator enabling a plugin for 400 sites does not want 400 uncoordinated full syncs
starting at once. Index runs across a network must be queued and throttled centrally — a detail
that is easy to get wrong and expensive to discover in production.

---

## 21. SaaS architecture

### 21.1 The seam

```
WordPress integration layer
            │  Contracts (frozen at v1.0)
    ┌───────┴────────┐
    │  SearchBackend │  search · suggest · facets · capabilities
    │  IndexBackend  │  writeBatch · delete · deleteByQuery · status
    │  SchemaBackend │  describe · plan · apply   (Cloud reports "managed")
    └───────┬────────┘
    ┌───────┴────────┐
    ▼                ▼
SolrBackend     CloudBackend
(HTTP → Solr)   (HTTPS → SolrEngine Cloud)
```

Splitting search from indexing is Principle 2 made concrete, and it pays off immediately: a site
can index to one backend while searching another during a migration, and the indexing path can be
tested without a search path existing.

### 21.2 Cloud API surface

```
POST   /v1/indexes/{id}/documents:batch     upsert documents
POST   /v1/indexes/{id}/documents:delete    by id or by structured selector
POST   /v1/indexes/{id}/search              structured query, never Solr syntax
POST   /v1/indexes/{id}/suggest
GET    /v1/indexes/{id}/status              document count, health, quota
POST   /v1/indexes/{id}/schema:apply        server-side; the plugin only declares intent
GET    /v1/account/usage                    quota and billing signals
```

The request body is the **domain model serialised** — the same `Document` and `SearchQuery` the
Solr adapter consumes. If the Cloud API ever needs a field the domain model lacks, that is a signal
the abstraction is leaking and should be fixed in the domain, not patched in the adapter.

Authentication: a per-site API key, issued at sign-up, stored exactly as the Solr password is
(§18.3). Keys are scoped to one index and revocable.

### 21.3 Privacy and disclosure

In Cloud mode, content leaves the customer's server. That is the product, and it must be stated
plainly, in the readme, in the admin UI at the moment of opting in, and in a privacy document.

| Sent | Never sent |
| --- | --- |
| Indexed document fields (title, content, excerpt, URL, taxonomy, allowlisted meta) | Post meta not on the allowlist |
| Search query text and facet selections | User email addresses, passwords, capabilities |
| Site URL, plugin version, index identifier | Order data, customer records, payment information |
| Aggregate usage counters for billing | WordPress database credentials or `wp-config.php` values |

Requirements that follow: field-level opt-in for meta before it is sent, a region selection for
data residency, a documented retention period, a working deletion path (delete the index, delete
the account), a sub-processor list, and a data processing agreement. *Researched fact:* WordPress.org
guideline 7 requires that data collection be documented in the readme, preferably with a stated
privacy policy, and that external contact be consented to — and notes that installing a SaaS-based
plugin grants that consent when registration is part of the flow.

### 21.4 What not to decide now

The Cloud implementation, its pricing, its infrastructure and its analytics feature set are
deliberately undecided (§29). What must be true now is only this: **the contracts exist, the
WordPress layer never sees Solr syntax, and the document model is serialisable.** Those three
properties keep the option open at near-zero cost. Anything more is building a product that has no
customers yet.

---

## 22. Free and commercial strategy

### 22.1 What the guidelines actually say

*Researched facts, quoted from the Detailed Plugin Guidelines:*

- **Guideline 5:** "Plugins may not contain functionality that is restricted or locked, only to be
  made available by payment or upgrade." Functionality may not be disabled after a trial or quota.
  Sandbox-only API access counts as trialware and is not permitted. The same guideline explicitly
  permits "add-on plugins, hosted outside of WordPress.org", and states that upselling is
  acceptable within the bounds of guideline 11.
- **Guideline 6:** Plugins acting as an interface to an external third-party service "are allowed,
  even for paid services", provided the service offers functionality of substance and is clearly
  documented. "A service that exists for the sole purpose of validating licenses or keys while all
  functional aspects of the plugin are included locally is not permitted."
- **Guideline 7:** No contacting external servers without explicit consent, normally opt-in or
  registration, with data use documented in the readme.
- **Guideline 8:** No executing outside code, no serving updates or installing plugins from servers
  other than WordPress.org's, when not acting as a service.
- **Guideline 11:** Upgrade prompts must be limited in scope, used sparingly, and dismissible.

### 22.2 The models assessed

| Model | WordPress.org compatibility | Verdict |
| --- | --- | --- |
| **A. Free core + paid add-on plugin** | Compatible — guideline 5 permits off-directory add-ons | **Recommended, secondary** |
| **B. Free plugin + paid SolrEngine SaaS** | Compatible — guideline 6 permits paid service interfaces | **Recommended, primary** |
| **C. Free plugin + generic external hosted service** | Compatible; effectively B with less integration | Subsumed by B |
| **D. Free plugin with features supplied by a commercial plugin** | Compatible if the free plugin is complete on its own and the paid one genuinely adds code | Same as A in practice |
| **E. Free plugin + support and services** | Compatible; low leverage | Supplementary at best |
| **Locked features behind a licence key** | **Not permitted** — guideline 5 | ❌ |
| **Key validation service with all code shipped locally** | **Not permitted** — guideline 6 | ❌ |
| **Free plugin that installs the paid version** | **Not permitted** — guideline 8 | ❌ |

### 22.3 Recommendation

**Primary: Model B. Secondary: Model A. Never: locked functionality.**

```
Free plugin (WordPress.org)
  Complete, unrestricted search against a customer's own Solr.
  Includes the Cloud adapter as an alternative backend — a choice, not an unlock.
        │
        ├── SolrEngine Cloud (paid SaaS)
        │     Managed Solr, no infrastructure, analytics, direct-from-browser search.
        │     Guideline 6: an interface to a paid service with functionality of substance.
        │
        └── Add-on plugins (distributed off WordPress.org)
              Advanced analytics, multi-currency, enterprise relevance tooling.
              Each ships its own updater. The free plugin never installs them.
```

The framing that keeps this compliant is precise and worth stating as a rule the team can apply
later: **the Cloud adapter is a backend choice, not a paywall.** Selecting "SolrEngine Cloud" in a
dropdown next to "Self-hosted Solr" is an interface to a service. Greying out "Faceted search"
with an "Upgrade to Pro" badge is locked functionality. The two look similar in a mock-up and are
on opposite sides of guideline 5.

Three architectural consequences, which is why this section belongs in a technical plan:

1. The **backend adapter registry must be public API from v1.0**, because the Cloud adapter and any
   future add-on depend on it.
2. The **admin tab registry and facet registry must be public API**, so add-ons extend rather than
   fork.
3. **No code path may branch on a licence state** inside the free plugin. There is no licence state
   in the free plugin.

Upsell discipline, per guideline 11: at most one dismissible pointer, on SolrEngine's own screens,
never network-wide, never on unrelated admin pages, and permanently dismissible.

*Assumption (§29):* this plan assumes the owner wants a genuine free product rather than a funnel.
A funnel-shaped plugin will be rejected, and the rejection is usually expensive because it arrives
after the code is written.

### 22.4 Operating cost, honestly

A managed Solr service is not a low-cost side business. Solr 10 requires Java 21 and real memory;
per-tenant isolation costs either collections at scale or careful multi-tenancy; indexing traffic is
bursty and driven by customers' bulk imports. Margins depend on tenant density, which depends on
the shared-collection multi-tenancy design in §10.2. *Recommendation:* prove demand with the free
plugin and self-hosted users before building any of it, and treat the Cloud phase as a separate
product decision with its own business case rather than as a plugin release.

---

## 23. Testing strategy

A realistic matrix, not an exhaustive one. The goal is that a contributor can change field mapping
or query construction and know within a minute whether they broke something.

### 23.1 Layers

| Layer | Scope | Requires | Speed |
| --- | --- | --- | --- |
| **Unit** | Domain objects, field mapping, query construction, response parsing, configuration validation, endpoint validation, escaping | Nothing. No WordPress, no Solr | Milliseconds |
| **WordPress integration** | Hook wiring, dirty-set coalescing, `posts_pre_query` substitution, REST routing and permissions, admin capability checks | WordPress test suite; backend mocked | Seconds |
| **Backend integration** | Real indexing, searching, deleting, faceting, schema application | Solr in Docker | Tens of seconds |
| **WooCommerce** | Product and variation mapping, price ranges, stock, visibility, attribute facets | WordPress + WooCommerce + Solr | Tens of seconds |
| **Security** | SSRF rejection cases, injection attempts, capability and nonce enforcement, private-content exclusion | WordPress; backend mocked | Seconds |
| **End-to-end** | Setup flow, search on a block theme and a classic theme, instant search keyboard behaviour | Playwright | Minutes |

The unit layer is the one that must be fast and comprehensive, which is why §11.1 insists the
domain layer runs without WordPress. If `SearchQuery` needs `get_option()`, this whole strategy
collapses into slow integration tests.

### 23.2 Cases that must exist

These encode the defects found in §2.3, so they cannot recur:

- A product save with WooCommerce active does not raise a `TypeError`.
- Indexing the same object twice does not mutate the endpoint (defect #2 made structurally
  impossible and verified).
- Deleting a post removes its document; trashing removes it; untrashing restores it.
- Changing a post's status from `publish` to `draft` removes it from the index.
- A search containing `{!lucene}` or `*:* OR x` returns results for that literal text and does not
  alter the query.
- With the backend unreachable, `is_search()` still renders results from WordPress and emits no
  fatal error and no PHP notice.
- A meta key not on the allowlist never appears in an indexed document.
- A private meta key (`_`-prefixed) never appears in an indexed document by default.
- `wp_safe_remote_*` semantics: an endpoint of `http://169.254.169.254/` is rejected under every
  configuration, including with the private-network acknowledgement enabled.
- The REST search endpoint rejects an unknown `filters` key rather than ignoring it.
- A full sync interrupted mid-run resumes from its cursor without duplicating or skipping.
- Facet counts with a multi-select filter applied match the tag/exclude expectation.

### 23.3 CI matrix

| Axis | Values |
| --- | --- |
| PHP | 7.4, 8.1, 8.3, 8.5 |
| WordPress | Minimum supported, current stable, trunk (trunk allowed to fail) |
| WooCommerce | Current stable, and the previous minor |
| Solr | 9.10, 10.x |
| Multisite | One job on the current stable WordPress |

Solr integration jobs run against Docker images. *Recommendation:* run the full matrix on pull
requests to the main branch and nightly, and run only unit plus one integration job on every push,
so the feedback loop stays usable.

### 23.4 Static and standards gates

PHPCS with WordPress Coding Standards, PHPStan at a level the codebase can actually hold (start at
5, ratchet upward, never add baseline entries silently), Plugin Check in CI as a **blocking** gate,
and `composer audit`. *Researched fact:* WordPress.org now runs automated security analysis on
every plugin release, blocking high-risk releases from distribution, so shipping a release that
fails a static-analysis check is no longer merely embarrassing — it stops the release.

---

## 24. Repository architecture

```
solrengine/
├── solrengine.php              bootstrap only: guards, constants, hook registration
├── uninstall.php               remove options and tables on explicit deletion
├── readme.txt                  WordPress.org manifest — mandatory
├── README.md                   developer-facing
├── LICENSE                     GPLv2 or later
├── composer.json / composer.lock          lock file tracked
├── package.json / package-lock.json
├── phpcs.xml.dist · phpstan.neon.dist · phpunit.xml.dist · .editorconfig
├── .github/workflows/          ci.yml · release.yml · plugin-check.yml
│
├── src/
│   ├── Plugin.php              composition root
│   ├── Contracts/              PUBLIC API. Interfaces only. Semantically versioned
│   ├── Support/                Container · Config · Logger · Encryption · Result
│   ├── Backend/
│   │   ├── BackendRegistry.php
│   │   └── Solr/               HttpTransport · EndpointValidator · QueryTranslator
│   │                           · ResponseParser · SchemaClient
│   ├── Schema/                 Contract · Planner · Applier · Migrations
│   ├── Documents/              DocumentBuilder · FieldMap · Transformers · TypeSuffix
│   ├── Indexing/               DirtySet · HookListeners · JobQueue · Jobs
│   │                           · FullSync · Reconciler
│   ├── Search/                 SearchService · QueryBuilder · Relevance
│   │                           · FacetRegistry · ResultHydrator
│   ├── Integration/
│   │   ├── WordPress/          QueryIntegration · ContentProviders
│   │   └── WooCommerce/        ProductMapper · VariationAggregator · QueryIntegration
│   ├── Rest/                   Controllers · Schemas · RateLimiter
│   ├── Admin/                  Screens · Settings · Notices · Assets
│   ├── Cli/                    Commands
│   └── Multisite/              NetworkConfig · SiteScope
│
├── configsets/solrengine/      schema.xml · solrconfig.xml · stopwords · synonyms
├── assets/                     src/ (JS, CSS) and build/ (compiled, committed for release)
├── languages/                  solrengine.pot
├── tests/                      Unit · Integration · WooCommerce · Security · E2E
├── docs/                       architecture · indexing · search · schema · solr
│                               · woocommerce · rest-api · cli · security · hooks
│                               · development · compatibility
└── bin/                        install-wp-tests.sh · build-release.sh
```

Why each non-obvious directory exists:

- **`src/Contracts/` separate from everything.** It is the public API surface that add-ons and the
  Cloud adapter bind to. Isolating it makes "is this a breaking change?" answerable by looking at
  one directory's diff.
- **`src/Backend/Solr/` rather than `src/Solr/`.** The plural intent is visible in the path. The
  Cloud adapter lands beside it without restructuring.
- **`configsets/` as a first-class directory.** A self-hosted user needs a real, shippable Solr
  configuration. This is a deliverable, not a documentation snippet.
- **`src/Integration/` grouping.** Everything that knows about a specific host system lives here,
  so the boundary in §11.1 is visible in the filesystem rather than only in prose.
- **`assets/src` and `assets/build` split.** WordPress.org requires access to source and build
  tooling for anything compiled (guideline 4). Shipping only minified output is a rejection.

*Recommendation on `vendor/`:* keep it out of git; build the release artifact in CI with
`composer install --no-dev --optimize-autoloader` and publish that to the directory's SVN. Track
`composer.lock` — currently ignored and untracked, which makes builds irreproducible.

*Recommendation against `includes/`:* a single `src/` with PSR-4 is unambiguous. Splitting between
`src/` and `includes/` invites the question "which one does this go in?" on every new file, and the
answer is never stable.

### 24.1 Documentation strategy

Documentation written when the phase ships, not retrospectively. Each document has one audience,
because a document serving two serves neither.

| Document | Audience | Written in |
| --- | --- | --- |
| `readme.txt` | Directory users and reviewers. **Must disclose every external service** | P0, updated every phase |
| `docs/architecture.md` | Contributors. Layering, contracts, why the seams sit where they do | P0 |
| `docs/development.md` | Contributors. Local setup, Solr in Docker, running the test layers | P0 |
| `docs/solr.md` | Site administrators. Provisioning, the shipped configset, authentication, TLS | P1 |
| `docs/schema.md` | Administrators and integrators. The contract, dynamic field conventions, migration | P1 |
| `docs/indexing.md` | Administrators. Full and incremental sync, queue behaviour, troubleshooting staleness | P2 |
| `docs/search.md` | Administrators. Relevance, boosting, fallback behaviour | P3 |
| `docs/hooks.md` | Integrators. Every public action and filter with signature and `@since` | P3, updated per hook added |
| `docs/rest-api.md` | Integrators. Endpoints, parameters, response shapes, versioning policy | P3 |
| `docs/cli.md` | Developers and operators | P2 |
| `docs/security.md` | Reviewers and security-conscious administrators. Threat model, credential handling, honest residual risks | P1 |
| `docs/compatibility.md` | Everyone. Supported Solr, PHP, WordPress and WooCommerce versions; bundled library licences | P1 |
| `docs/woocommerce.md` | Store owners. Indexed fields, the price limitation, visibility rules | P6 |
| `docs/privacy.md` | Administrators. What is indexed, what is sent where, what Cloud mode changes | P1, expanded at P9 |
| `.claude/plans/` | The team. Planning artefacts and ADRs — not shipped in the release package | Ongoing |

Two rules worth stating because they are usually broken: the hook reference is generated from
PHPDoc rather than hand-maintained, so it cannot drift; and `docs/security.md` documents what each
mitigation does *not* cover, because a security document that only lists strengths misleads the
reader it is written for.

---

## 25. Phased roadmap

Each phase ends in something demonstrable. No phase is "refactoring".

| Phase | Outcome | Key deliverables | Risk |
| --- | --- | --- | --- |
| **P0 — Foundation** ✅ | A skeleton that is empty but correct | Bootstrap with guards, contracts, container, config and secret storage, PHPCS/PHPStan/PHPUnit/Plugin Check in CI, `readme.txt`, i18n scaffolding | Low |
| **P1 — Connection and schema** ✅ | Admin connects to Solr and sees an honest diagnosis | Endpoint validator, HTTP transport, auth, version and mode detection, connection test, schema contract v1, shipped configset, schema comparison and confirmed apply | Medium — §18.1, Solr version variance |
| **P2 — Indexing** ✅ | Content reliably reaches Solr and stays correct | Document model, field map, meta allowlist, dirty-set coalescing, job queue, full sync with cursor and resume, incremental sync including deletes, failure handling, reconciliation, WP-CLI | **High — the hardest phase** |
| **P3 — Search** ✅ | WordPress search is Solr-powered, and safe when it is not | `QueryIntegration`, relevance profile, injection-safe query construction, fallback, REST `search` and `status`, admin dashboard | Medium |
| **→ v1.0 to WordPress.org** | | Security review, Plugin Check clean, documentation, disclosure of external services | |
| **P4 — Suggest and instant search** | Autocomplete in the theme's search box | Suggester configuration and scheduled build, `suggest` endpoint, accessible front-end component, caching | Medium — §19.4 |
| **P5 — Facets** | Generic faceted search | Facet registry, JSON Facet translation, multi-select, hierarchical taxonomy facets, `facets` endpoint, filter blocks and shortcodes | Medium |
| **P6 — WooCommerce** | Fast product search and catalogue filtering | Product and variation mapping, visibility rules, price and attribute facets, Product Collection block integration, classic shop loop integration | **High — §15.4, §15.6** |
| **P7 — Advanced administration** | Power users stop needing the filesystem | Field mapping UI, relevance tuning, schema migration UX, alias-based zero-downtime reindex, index history | Medium |
| **P8 — Multisite and multilingual** | Networks and non-English sites | Network configuration, per-site scope UI, language fields and analyzers, WPML and Polylang integration | Medium |
| **P9 — Cloud backend** | A second adapter proves the abstraction | Cloud adapter, sign-up and key management, privacy disclosure, migration between backends | High — a product, not a feature |
| **P10 — Commercial ecosystem** | Add-ons and analytics | Add-on SDK, analytics add-on, semantic search exploration | Deliberately unplanned |

### 25.1 Phase 0 status — complete, 2026-09-19

Delivered and verified. Every gate passes: coding standards, static analysis,
29 unit tests, dependency audit, and the version-consistency check.

| Deliverable | State |
| --- | --- |
| Bootstrap with PHP, WordPress and autoloader guards | Done. An unmet requirement shows an admin notice; nothing fatals |
| Activation that contacts nothing | Done |
| Backend contracts (`SearchBackend`, `IndexBackend`, `SchemaBackend`) | Done, with the later-phase surface documented but not frozen |
| Supporting contracts (`Result`, `Capabilities`, `DocumentRef`, `WriteResult`, `IndexStatus`, `JobQueue`, `Logger`) | Done |
| Lazy service container | Done, with a test asserting registration constructs nothing |
| Configuration (defaults, options, constants) | Done. Reads the option the settings screen will write, fixing defect #6 by construction |
| Credential storage | Done. Constants first, sodium-encrypted option second, redaction tested |
| Backend registry | Done. Public API, so Cloud is a backend choice rather than a paywall |
| `readme.txt` with external-service disclosure | Done |
| `uninstall.php` | Done. Removes options and tables; deliberately does not delete remote documents |
| `LICENSE` (GPLv2) and corrected headers | Done |
| PHPCS, PHPStan, PHPUnit, `.distignore`, release build | Done |
| CI workflow | Done. Static gates, PHP matrix 7.4–8.4, WordPress matrix, Solr 9.10 and 10, Plugin Check |
| Version-drift check | Done. Header, constant and stable tag verified in CI |

**Two defects fixed as a side effect**, both found by the new tooling rather
than by reading: PHPStan caught `add_option()` and `update_option()` being
passed the string `'no'` where a boolean is expected, and the test suite caught
`phpcbf` silently rewriting a string literal inside an assertion. The second is
recorded in `docs/development.md` as a standing warning, and the sniff
responsible is now disabled.

**The distributable is 220 KB** and contains no runtime dependency beyond
Composer's autoloader, which is ADR-001 working as intended.

**The 0.1.0 prototype was removed on 2026-09-19**, once the foundation was in
place and green. Twenty-one tracked files went: `src/Plugin.php`,
`src/Config.php`, `src/Container.php`, `src/Admin/SettingsPage.php`, the
`Solr/`, `Index/`, `Interfaces/`, `Utils/`, `Front/` and `WooCommerce/`
directories, `tests/test-solarium.php`, `examples/`, `docs/SOLARIUM.md`, and the
leftover `phpcs.xml`. Every exclusion that had been carrying them was then
deleted from the PHPCS, PHPStan, PHPUnit and `.distignore` configuration, so
there is no longer any exempt code: everything the project owns is linted,
analysed and covered.

Removing the exclusions immediately found one more defect. With `bin/` brought
into scope, PHP_CodeSniffer flagged that `bin/check-version.php` used `$plugin`,
a real WordPress global. Fixed. That is the argument for having no exempt paths
stated as evidence rather than as principle.

### 25.2 Phase 1 status — complete, 2026-09-19, shipped as 0.2.0

An administrator can enter a Solr address, get an honest per-check diagnosis,
see exactly which schema changes SolrEngine needs, and apply them on
confirmation. Gates: 87 unit tests, coding standards, static analysis and
version consistency all pass.

| Deliverable | State |
| --- | --- |
| Endpoint validator | Done. 43 dedicated security tests |
| HTTP transport | Done. No redirects, capped bodies, JSON only, classified failures |
| Basic authentication | Done. Constants preferred over stored credentials |
| Version and mode detection | Done, memoised per request |
| Connection diagnostic | Done. Eight independent checks, shared by admin and CLI |
| Schema contract v1 | Done. 13 dynamic patterns, 3 concrete fields, 6 copy rules |
| Schema planner | Done. Additive only, `se_` only, pure and unit tested |
| Schema apply | Done, gated twice on being additive |
| Schema export | Done. The fallback for a Solr we may not modify |
| Shipped configset | Done, generated from the same contract |
| Connection admin screen | Done |
| WP-CLI `test`, `status`, `schema` | Done |
| Documentation | `docs/security.md`, `docs/solr.md` |

**The phase found a design error in the plan before it reached any code that
mattered.** Section 10.1 specified fields as `se_title_txt`, which implies
dynamic patterns like `se_*_txt` with the wildcard in the middle. Solr's
copy-fields documentation states that source and destination "can contain
either leading or trailing asterisks", so the entire naming scheme was
unimplementable. The type marker moved to the front — `se_txt_title`,
pattern `se_txt_*` — and a second constraint surfaced alongside it: copy chains
are prohibited, so the taxonomy aggregate declares the same source twice
instead of chaining. Both are recorded in the Phase 1 addendum to ADR-002, and
both are now guarded by unit tests that validate the shipped contract against
Solr's rules.

**Three more defects were caught by tooling rather than by review.** PHPStan
found `add_option()` being passed a string where a boolean belongs. The
endpoint tests found that PHP's `FILTER_FLAG_NO_RES_RANGE` does not cover
multicast, so `224.0.0.1` would have been classified as a public address. The
same tests found that collection names were being sanitised rather than
rejected, so `../../admin/collections` became `....admincollections` — a
valid-looking name that does not exist, which would have produced a confusing
"collection not found" rather than a clear error.

**Not done, deliberately.** No REST endpoints yet: the admin screen uses
`admin-post.php`, and a public REST surface belongs with search in Phase 3
where the parameter-allowlisting rules in §16.2 can be built in from the start
rather than retrofitted. No integration or backend test suites are populated
yet; both directories and the CI jobs that drive them exist, and Docker was not
available in this environment to verify against a live Solr.

### 25.3 Phase 2 status — mostly complete, 2026-09-19, shipped as 0.3.0

Content now reaches Solr and stays correct. 135 unit tests and 14 backend tests
against a real Solr 10 pass, alongside coding standards, static analysis and the
version check.

| Deliverable | State |
| --- | --- |
| Document model and field map | Done, verified against real Solr |
| Value coercion | Done. A bad value drops one field rather than failing a document |
| Meta allowlist | Done, with field-collision detection |
| Dirty-set coalescing | Done. Many hooks per post become one job |
| Hook listeners | Done, including deletes, trash, untrash and status transitions |
| Job queue | Done. Action Scheduler driver plus a WP-Cron fallback, both behind the contract |
| Batch writes and selector deletes | Done, with `commitWithin` rather than per-document commits |
| Failure isolation | Done. A rejected batch retries one document at a time |
| Full sync | Done. ID cursor, resumable, cancellable, adaptive batch size |
| WP-CLI `index` | Done, with `resume`, `cancel` and `progress` |
| Reconciliation | **Not done.** See below |
| Admin indexing screen | **Not done.** CLI only for now |

**Phase 1's schema decision is now verified rather than reasoned.** Docker was
available for this phase, so the contract was applied to a real Solr 10 and the
naming correction was confirmed empirically. Solr's own message on the original
scheme: *"Dynamic field name 'se_*_txt' should have either a leading or a
trailing asterisk, and no others."* Both that and the copyField equivalent are
now permanent regression tests, so if a future change reintroduces a mid-name
wildcard, a test fails rather than a customer's first schema call.

A third finding came out of the same session and corrected a plan assumption:
**Solr accepts a copyField chain rather than rejecting it.** The documentation
says "no copy feeds into another copy", and the practical consequence is worse
than a rejection would be — the rule is declared successfully and silently does
nothing at index time. The contract avoids chains, and the unit tests enforce
that, but the plan's wording implied Solr would stop you. It will not.

**Two pieces are deliberately unfinished.** Reconciliation (F-27) detects drift
between WordPress and the index, and it is most valuable once search is live and
a stale index is visible to users, so it moves to Phase 3. An admin indexing
screen is the other; the CLI covers the operator case that matters now, and the
screen belongs with the dashboard work already scheduled for Phase 3.

**Action Scheduler is now a real dependency**, at 764 KB and GPL-3.0-or-later.
The combined distributed package is therefore effectively GPLv3, which ADR-004
anticipated and readme.txt now discloses.

### 25.4 Phase 3 status — complete, 2026-09-19, shipped as 0.4.0

Search works end to end, and the injection defences are verified against a real
Solr rather than reasoned about. 168 unit tests and 33 backend tests pass,
alongside coding standards, static analysis and the version check.

| Deliverable | State |
| --- | --- |
| `SearchQuery`, `SearchResults`, `SearchHit` contracts | Done. No route for engine syntax exists on any of them |
| Query translator | Done. Parameter substitution, server-built filters, escaped values |
| Relevance profile | Done. Field and phrase weighting, minimum match, optional recency |
| Filter registry | Done. Unknown keys rejected, not ignored |
| Response parser | Done, defensive against malformed responses |
| `SearchService` | Done, with caching and a circuit breaker |
| `QueryIntegration` | Done. `posts_pre_query`, `null` on failure |
| REST search and status | Done, with declarative argument validation |
| Rate limiter | Done, with its own ineffectiveness surfaced |
| Admin dashboard | Done |
| Reconciliation (carried from P2) | Done |

**Two defects that only a live Solr could have found**, both of which failed
silently with a plausible-looking response:

1. **Filter queries were being dropped entirely.** PHP's `http_build_query()`
   encodes an array as `fq[0]=` and `fq[1]=`, which Solr does not recognise as
   `fq` at all. Since the filters SolrEngine adds include the site scope and
   the public-visibility restriction, every query was running with no security
   filtering on it. The transport now builds repeated parameters itself.
2. **A stray `defType=edismax` made every search match nothing.** With
   `defType` set, edismax parses the `q` string itself and treats the
   `{!edismax v=$se_terms}` local parameter as literal text, so the query
   searched for that literal string. No error, just zero results.

Both are now regression tests. The second is the reason the injection suite
opens with a control assertion: without one, every "the attack returned
nothing" test would have passed on a query that simply never matched anything.

**A third defect was caught by reading.** The reconciler's expected count
excluded password-protected posts, while the document builder indexes them as
metadata. Every site with one protected post would have reported permanent
drift.

**The plan's §25 ordering held up.** Reconciliation moved here from Phase 2 for
the stated reason and proved easier to specify with search live, because
"drift" is only meaningful once someone is querying the index.

Ordering rationale worth recording: **P5 precedes P6 deliberately.** WooCommerce filtering is the
strongest commercial signal (§6, Dana), but it is built out of generic faceting. Building facets
inside the WooCommerce integration would produce a facet engine that only works for products, and
then a second one later.

---

## 26. MVP definition

The MVP is P0 through P3. It proves the ten things the brief asks it to prove.

| # | Proof | Delivered by |
| --- | --- | --- |
| 1 | Connect to Solr | Connection screen, endpoint validator, auth |
| 2 | Validate the connection | Multi-step diagnostic with independent results |
| 3 | Index WordPress content | Posts, pages, public CPTs, taxonomy terms, allowlisted meta |
| 4 | Incrementally update | Coalesced, queued, asynchronous indexing on every content event |
| 5 | Remove deleted content | Delete, trash and status-transition handling |
| 6 | Search the index | `posts_pre_query` substitution into the main query |
| 7 | Relevance and ranking | `edismax` with a configurable field-boost profile |
| 8 | Fall back when Solr is down | Return `null`; WordPress searches normally; circuit breaker |
| 9 | A clean search API | REST `search` and `status`, strictly parameterised |
| 10 | Maintainable and testable | Contracts, unit-testable domain, CI, Plugin Check clean |

**Explicitly excluded from the MVP**, with reasons:

| Excluded | Why |
| --- | --- |
| Faceted search | Large surface; the MVP must prove the indexing and fallback foundations first |
| Instant search | Depends on suggester configuration and has the §19.4 latency question unresolved |
| WooCommerce | Deserves a dedicated phase; a half-correct product index is worse than none |
| Gutenberg blocks | Needs a JS build pipeline that does not exist yet |
| Multisite UI | The data model supports it; the interface can wait |
| Schema editing UI | §14 argues this is a trap before the contract has stabilised |
| Analytics, SaaS, semantic search | §27 |

*Recommendation on sequencing within the MVP:* build P2 before P3. Search against an index that is
subtly wrong produces bug reports that look like relevance problems and are actually indexing
problems, and that confusion is expensive to unpick.

### 26.1 Feature prioritisation

Risk is the chance of getting it wrong in a way that is expensive to discover. Complexity is the
implementation effort. They are not the same axis, and features high on both deserve their own
phase.

| Feature | MVP | v1.x | Future | Risk | Complexity |
| --- | :---: | :---: | :---: | --- | --- |
| Solr connection and validation | ● | | | Medium — SSRF, version variance | Medium |
| Schema contract and guided apply | ● | | | Medium — customer infrastructure | Medium |
| Shipped configset | ● | | | Low | Low |
| Document model and field mapping | ● | | | **High** — expensive to change later | Medium |
| Meta allowlist | ● | | | Medium — privacy | Low |
| Full sync, resumable | ● | | | **High** — large datasets | **High** |
| Incremental sync incl. deletions | ● | | | **High** — silent staleness | **High** |
| Search replacement with fallback | ● | | | Medium | Medium |
| Relevance profile and boosting | ● | | | Low | Medium |
| REST search and status | ● | | | Medium — abuse surface | Low |
| WP-CLI core commands | ● | | | Low | Low |
| Admin dashboard | ● | | | Low | Medium |
| Reconciliation job | ● | | | Medium | Medium |
| Highlighting | | ● | | Low — password-protected content | Low |
| Typo tolerance (no-results retry) | | ● | | Low | Low |
| Suggest and instant search | | ● | | Medium — §19.4 latency | Medium |
| Generic faceting | | ● | | Medium — count correctness | **High** |
| Multi-select facets | | ● | | Medium | Medium |
| Hierarchical taxonomy facets | | ● | | Low | Medium |
| Gutenberg blocks and shortcodes | | ● | | Low | Medium |
| WooCommerce product indexing | | ● | | **High** — price, visibility, variations | **High** |
| WooCommerce filtering integration | | ● | | **High** — evolving block APIs | **High** |
| Field mapping UI | | ● | | Medium | **High** |
| Alias-based zero-downtime reindex | | ● | | Medium — SolrCloud only | Medium |
| Multisite network configuration | | ● | | Medium — tenant isolation | Medium |
| Multilingual analyzers | | ● | | Medium | **High** |
| Schema editing UI | | | ● | **High** — configset reload, not Schema API | **High** |
| SaaS backend | | | ● | **High** — a business, not a feature | **High** |
| Search analytics | | | ● | Medium — privacy, storage | Medium |
| Semantic / vector search | | | ● | Medium — embedding cost and privacy | **High** |
| Alternative engines (Elastic, etc.) | | | ● | Low technically, high strategically | Medium |

---

## 27. Future roadmap

Beyond P10, and deliberately undecided:

**Search analytics.** Popular queries, zero-result queries, click-through, search-to-conversion.
Belongs in the **commercial tier**: it carries storage cost, it carries privacy obligations
(queries are user data), and it is exactly the kind of value businesses pay for. Keeping it out of
the free plugin also avoids guideline 7 consent complexity for every free user.

**Semantic and hybrid search.** Solr 10 ships vector search, so the storage side is solved. The
unsolved parts are generating embeddings — cost, latency, and sending content to a third-party
model — and evaluating whether hybrid retrieval actually beats a well-tuned `edismax` profile for
typical WordPress content. *Recommendation:* the architecture must not preclude it (a vector field
is another dynamic field; a hybrid query is another query strategy behind the same contract), and
the roadmap must not promise it. Principle 12 applies directly.

**Other backends.** Elasticsearch, OpenSearch or Typesense behind the same contracts. Technically
open, strategically questionable: the product's identity is Solr expertise. Listed because the
abstraction makes it possible, not because it is planned.

**Personalisation and recommendations.** More-like-this is nearly free with Solr. Personalised
ranking is not, and it carries privacy weight. Future possibility only.

### 27.1 Decisions deliberately left open

Areas where committing early would cost more than it buys. Each names the property the
architecture must preserve so the decision stays cheap to make later — that property is the actual
deliverable here, not the deferral.

| Deferred | Preserve | Why defer |
| --- | --- | --- |
| The Cloud API's concrete shape and implementation | Domain types are serialisable; no Solr syntax above the adapter | Designing an API for customers who do not exist yet produces an API nobody wanted |
| Vector and semantic search | A vector field is one more dynamic field; a hybrid query is one more strategy behind the same contract | Embedding cost, latency and third-party data exposure are unresolved, and the retrieval benefit for typical WordPress content is unproven |
| Search analytics schema and retention | Query execution already passes through one `SearchService` chokepoint where instrumentation can attach | Privacy obligations and storage cost differ sharply between free, add-on and SaaS delivery |
| Premium packaging — what is Cloud-only, what is an add-on | Backend, facet and admin-tab registries are public API | Depends on which persona converts, which the free plugin will reveal |
| The front-end framework for search UI | Components are consumed through REST, so the renderer is replaceable | A search dropdown does not justify a framework decision in v1 |
| Advanced schema and analyzer UI | The schema contract is data, so any UI is a view over it | Analyzer changes need a configset reload rather than a Schema API call; the UI is large and the audience small |
| Alternative search engines | Contracts are engine-neutral by construction | Strategically questionable while the product's identity is Solr expertise |
| Shared versus per-site collections as a network default | Every document carries `se_site_id_i` and every query filters on it | The right answer depends on real network topologies, which we have not seen yet |

The rule these share: **build the seam, not the feature.** A seam costs an interface; a feature
costs maintenance for as long as the product exists.

---

## 28. Risks

Ranked by expected impact. No numerical scores, because there is no methodology that would make
them meaningful.

| # | Risk | Severity | Mitigation |
| --- | --- | --- | --- |
| 1 | **Most WordPress hosts do not offer Solr.** The addressable market for a self-hosted-only plugin is a fraction of WordPress | **Critical (product)** | This is what SolrEngine Cloud is for. Until then, target agencies and self-hosters (§6, Marek) and be honest in the readme about the requirement. Ship a Docker quickstart |
| 2 | **Indexing correctness at scale.** Missed deletions, drift, partial failures, silent staleness | **Critical** | Coalesced dirty tracking, resumable cursors, dead-letter queue, reconciliation job (§12.7), and the regression tests in §23.2 |
| 3 | **Schema divergence across customer installs.** Every Solr is configured differently | High | Dynamic fields eliminate most of it; a versioned contract detects the rest; additive-only migrations; a shipped configset |
| 4 | **WooCommerce correctness.** Prices, tax, visibility and variations are genuinely hard | High | CRUD-only reads, parent-centric denormalisation, documented price limitations, a dedicated phase, a dedicated test layer |
| 5 | **SSRF and credential handling** | High | §18.1 validator, constants-first secrets, rate-limited connection testing, honest documentation of residual risk |
| 6 | **Query injection through the public endpoints** | High | Parameter substitution, server-declared filters and facets, no raw pass-through anywhere |
| 7 | **WordPress.org rejection over packaging or undisclosed services** | High | §22 designs the business model as an architectural constraint; Plugin Check blocking in CI; external services disclosed in the readme from the first submission |
| 8 | **Solr availability turning into site downtime** | High | Principle 1, implemented as a `null` return; circuit breaker; nothing on the render path throws |
| 9 | **Page builder and block theme incompatibility** | Medium | Hook at the query layer, never the template layer. This is the direct fix for defect #17 |
| 10 | **Large-site full sync never completing** | Medium | Action Scheduler plus WP-CLI, adaptive batches, resumable cursors, progress visible in admin |
| 11 | **Multilingual relevance** | Medium | Language fields from day one; per-language analyzed fields as a later phase; integrate with WPML and Polylang rather than inventing a language model |
| 12 | **Dependency conflicts with other plugins** | Medium | ADR-001 removes the largest source. Action Scheduler is loaded defensively |
| 13 | **Solr version drift.** Solr 10 removed request parameters, changed defaults and requires Java 21 | Medium | Feature detection, a published support policy (§10.6), CI against 9.10 and 10.x |
| 14 | **Instant search failing to feel instant** | Medium | §19.4: set expectations honestly in v1; treat browser-direct querying as a Cloud differentiator |
| 15 | **Maintenance surface growth** | Medium | Small MVP, deliberate hooks, add-ons instead of core features, decisions recorded as ADRs |
| ~~16~~ | ~~Provenance of existing code~~ | **Closed** | Resolved 2026-09-19: the stray names were leftover strings, not lifted code. Renamed. See §2.6 |

---

## 29. Open questions

Genuine product decisions, not implementation details. Each is blocking for the phase named.

| # | Question | Blocks | Assumption used meanwhile |
| --- | --- | --- | --- |
| Q1 | Is the free plugin a genuine standalone product, or a funnel to Cloud? | P0 architecture and §22 | Genuine standalone. A funnel will be rejected by the directory |
| Q2 | Who is the priority customer for v1 — agencies with their own Solr, or WooCommerce stores? | Roadmap ordering after P3 | Agencies first; the phasing follows from it |
| Q3 | Should SolrEngine own a search UI, or only power existing themes? | P3 and P5 scope | Power existing themes first; UI components only where none exist |
| Q4 | One collection per site, or shared with a site filter, as the multisite default? | P8 | Per-site default; the document model supports both regardless |
| Q5 | How much Solr expertise is assumed of a v1 user? | Admin UX depth | Assume none for setup, expose everything for those who want it |
| Q6 | Is exact stock quantity indexing required, or is bucketed availability enough? | P6 | Bucketed by default, exact opt-in |
| Q7 | Is the minimum PHP 7.4 or 8.1? | P0, and the CI matrix | 7.4, rising to 8.1 when WooCommerce does in January 2027 |
| Q8 | Which features are Cloud-exclusive rather than merely Cloud-easier? | P9 and pricing | Analytics and browser-direct search. Everything else works on both |
| Q9 | Does SolrEngine Cloud store customer content, or only derived index data? | P9, and the privacy policy | Stores content, because Solr needs it for highlighting. This must be disclosed prominently |
| Q10 | What is the support commitment for self-hosted Solr misconfiguration? | Readme and business model | Best effort, documented plainly. Solr operations are not our support surface |
| Q11 | Should the plugin ever create a Solr collection, or always require a provisioned one? | P1 | Require a provisioned one in v1; offer guided creation later, mode-aware and opt-in |
| ~~Q12~~ | ~~Is the existing code's provenance clean?~~ | — | **Resolved 2026-09-19.** The project name is SolrEngine; the stray names were leftover strings, now renamed. See §2.6 |

---

## 30. Architecture decision records

Eleven decisions expensive enough to reverse that they are recorded separately, each with context,
decision, alternatives, consequences and reasoning.

| ADR | Decision | Summary |
| --- | --- | --- |
| [ADR-001](adr/ADR-001-solr-client-strategy.md) | Solr client strategy | Drop Solarium; a thin internal transport on the WordPress HTTP API |
| [ADR-002](adr/ADR-002-document-model.md) | Index document model | Namespaced `se_*` fields, composite IDs, search-oriented shape |
| [ADR-003](adr/ADR-003-schema-management.md) | Schema management | Hybrid: owned namespace, dynamic fields, versioned contract, confirmed additive migrations |
| [ADR-004](adr/ADR-004-background-indexing.md) | Background indexing | Action Scheduler behind a `JobQueue` contract; coalesced dirty tracking |
| [ADR-005](adr/ADR-005-wordpress-search-integration.md) | Search integration | `posts_pre_query`, with `null` as the fallback |
| [ADR-006](adr/ADR-006-woocommerce-indexing.md) | WooCommerce indexing | Parent-centric documents with denormalised variation data; CRUD reads only |
| [ADR-007](adr/ADR-007-backend-abstraction.md) | Backend abstraction | Split search, index and schema contracts; no Solr syntax above the adapter |
| [ADR-008](adr/ADR-008-facet-api.md) | Facet API | JSON Facet API only; facets and filters declared server-side |
| [ADR-009](adr/ADR-009-configuration-and-secrets.md) | Configuration and secrets | Separated options, two custom tables, constants-first credentials |
| [ADR-010](adr/ADR-010-commercialisation.md) | Commercialisation | Free core, Cloud as an alternative backend, add-ons off-directory |
| [ADR-011](adr/ADR-011-version-support-policy.md) | Version support | Solr 9.10+ and 10.x, PHP 7.4+, WordPress 6.5+ |

---

## 31. Research sources

All consulted on 2026-09-19. Primary sources preferred throughout; where only secondary sources
were available, the claim is marked in the text as unverified or is not relied upon.

### Apache Solr

- Downloads and version support policy — https://solr.apache.org/downloads.html
- Major Changes in Solr 10 — https://solr.apache.org/guide/solr/latest/upgrade-notes/major-changes-in-solr-10.html
- Schema API — https://solr.apache.org/guide/solr/latest/indexing-guide/schema-api.html
- Schemaless Mode — https://solr.apache.org/guide/solr/latest/indexing-guide/schemaless-mode.html
- JSON Facet API — https://solr.apache.org/guide/solr/latest/query-guide/json-facet-api.html
- JSON Faceting Domain Changes — https://solr.apache.org/guide/solr/latest/query-guide/json-faceting-domain-changes.html
- Suggester — https://solr.apache.org/guide/solr/latest/query-guide/suggester.html
- Alias Management — https://solr.apache.org/guide/solr/latest/deployment-guide/alias-management.html
- CoreAdmin API — https://solr.apache.org/guide/solr/latest/configuration-guide/coreadmin-api.html
- Basic Authentication Plugin — https://solr.apache.org/guide/solr/latest/deployment-guide/basic-authentication-plugin.html
- Solr news and releases — https://solr.apache.org/news.html

### WordPress

- Detailed Plugin Guidelines — https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Plugin review checklist — https://make.wordpress.org/plugins/handbook/performing-reviews/review-checklist/
- Automated security review for plugin releases (2026-09-09) — https://make.wordpress.org/plugins/2026/09/09/automated-security-review-for-plugin-releases/
- How your readme.txt works — https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- PHP compatibility and WordPress versions — https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/
- `wp_http_validate_url()` — https://developer.wordpress.org/reference/functions/wp_http_validate_url/
- `posts_pre_query` — https://developer.wordpress.org/reference/hooks/posts_pre_query/
- Plugin Check (PCP) — https://wordpress.org/plugins/plugin-check/

### WooCommerce

- High-Performance Order Storage — https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/
- Product Collection block announcement — https://developer.woocommerce.com/2024/02/22/announcing-the-product-collection-block/
- Product Collection extensibility and query hooks — https://github.com/woocommerce/woocommerce/discussions/51018
- Interactivity-API-driven future for WooCommerce blocks — https://developer.woocommerce.com/2025/10/30/understanding-the-interactivity-api-driven-future-for-woocommerce-blocks/
- Product attributes lookup table optimisation — https://developer.woocommerce.com/2024/06/20/an-optimization-for-the-product-attributes-lookup-table-is-coming/
- Proposed PHP 8.1 minimum for WooCommerce 11.5 (2026-09-08) — https://developer.woocommerce.com/2026/09/08/from-php-7-4-to-8-1/
- Server requirements — https://woocommerce.com/document/server-requirements/

### Libraries

- Action Scheduler — https://actionscheduler.org/ and https://github.com/woocommerce/action-scheduler
- Solarium on Packagist (7.0.0, BSD-3-Clause, PHP ^8.1) — https://packagist.org/packages/solarium/solarium
- Solarium releases — https://github.com/solariumphp/solarium/releases
- WordPress stubs for PHPStan — https://github.com/php-stubs/wordpress-stubs

---

## Appendix A — WordPress.org compliance requirements

Turning the guidelines into concrete, checkable project requirements. Every line is a CI check, a
review checklist item, or a file that must exist.

| # | Requirement | Where it is enforced |
| --- | --- | --- |
| A1 | Header `License: GPLv2 or later` with a `License URI`; a `LICENSE` file at the root | Currently `GPL2`. Fix in P0 |
| A2 | Every bundled library GPL-compatible, with its licence recorded. Action Scheduler is GPLv3, making the distributed whole GPLv3 | `docs/compatibility.md`, recorded deliberately |
| A3 | `readme.txt` with `Requires at least`, `Tested up to`, `Requires PHP`, `Stable tag`, `License`, and at most five tags | P0; Plugin Check verifies it |
| A4 | Plugin headers `Requires at least` and `Requires PHP` matching the readme | P0 |
| A5 | **Every external service disclosed in the readme**: what is contacted, when, why, what data is sent, with links to terms and privacy policy | P1 for self-hosted Solr; P9 for Cloud. Not optional even for a customer's own server |
| A6 | No outbound request without explicit configuration or consent. No telemetry in v1 | Architectural: the only outbound host is the one the administrator typed |
| A7 | No code served or executed from an external server; no update mechanism outside WordPress.org for the free plugin | Architectural |
| A8 | No locked, restricted or trial functionality in the free plugin | §22; ADR-010 |
| A9 | Upsell limited, sparing, dismissible, on SolrEngine's own screens only | §22.3 |
| A10 | Unique prefixing of every public symbol — functions, classes, options, meta keys, hooks, transients, tables | Namespaced PHP; `solrengine_` for every WordPress-level identifier |
| A11 | All input sanitised, all output escaped, all queries prepared | PHPCS plus code review |
| A12 | Nonces plus capability checks on every state-changing action | §18.5; security test layer |
| A13 | Human-readable code; source and build tooling available for anything compiled | `assets/src` committed alongside `assets/build` |
| A14 | Full internationalisation with a consistent `solrengine` text domain; text domain loaded on `init` | Plugin Check verifies it |
| A15 | No unexpected files in the distributed package — no tests, no examples, no debug pages | `build-release.sh` plus a `.distignore`. Removes §2.3 defects #25 and #26 |
| A16 | Accessibility: keyboard operability, ARIA combobox semantics, visible focus, sufficient contrast | E2E test layer |
| A17 | Plugin Check passes with no errors and no unexplained warnings | Blocking CI gate |
| A18 | Passes the automated security analysis applied to every release | Consequence of A11, A12 and static analysis |
| A19 | `uninstall.php` removing options and tables on deletion | P0 |
| A20 | No `error_log()`, `var_dump()` or debug output in shipped code | PHPCS. Currently violated in `SolariumClient` |

---

## Appendix B — Coding standards, tooling and dependencies

### Standards

*Recommendation.* WordPress Coding Standards for formatting and safety rules, with PSR-4
autoloading and namespaces — the combination the modern WordPress ecosystem has converged on.

| Element | Convention |
| --- | --- |
| Namespaces | `SolrEngine\{Layer}\{Component}`, PSR-4 under `src/` |
| Classes | `StudlyCase`, one class per file, filename matching the class |
| Methods and variables | `snake_case`, following WordPress rather than PSR-1 |
| WordPress-level identifiers | `solrengine_` prefix: hooks, options, meta keys, transients, tables, CLI commands |
| Type declarations | Parameter and return types everywhere the PHP floor allows |
| Documentation | PHPDoc on every public method; every hook documented with `@since` and parameters |

*Note on the current `phpcs.xml`:* it excludes `ValidVariableName`, `ValidFunctionName`,
`ValidHookName` and `CamelCapsMethodName` to permit camelCase throughout. That is a legitimate
choice, but it puts the codebase at odds with what a WordPress contributor expects and with
Principle 9. *Recommendation:* adopt `snake_case` for methods and variables, keep `StudlyCase` for
classes, and re-enable `ValidHookName` — hook names in particular are public API, and
`solrengine_document` must never become `solrengineDocument`.

### Tooling

| Tool | Purpose | Gate |
| --- | --- | --- |
| Composer | Autoloading, dev dependencies, scripts | — |
| PHPCS + WPCS | Formatting and safety | Blocking |
| PHPStan + `wordpress-stubs` | Static analysis, level 5 rising | Blocking |
| PHPUnit + WordPress test suite | Unit and integration | Blocking |
| Plugin Check | Directory compliance | Blocking |
| `composer audit` | Dependency vulnerabilities | Blocking |
| `@wordpress/scripts` | JS and CSS build, from P4 | Blocking |
| ESLint + Prettier (`@wordpress/eslint-plugin`) | JS standards | Blocking |
| Playwright | End-to-end, from P3 | Non-blocking initially |
| Docker Compose | Solr 9.10 and 10.x for integration tests | — |

### Dependency policy

*Recommendation: near-zero runtime dependencies.*

| Dependency | Decision | Reason |
| --- | --- | --- |
| Solarium | **Removed** | ADR-001. Forces a PHP 8.1 floor sourced from the dependency rather than the product, pulls Symfony packages into a shared global namespace, and the existing no-op event-dispatcher workaround already disables the features that justify it |
| Guzzle | **Not used** | The WordPress HTTP API is what reviewers expect and what hosts have configured for proxies and TLS |
| Action Scheduler | **Bundled** | ADR-004. Mature, built for plugin distribution, already present on WooCommerce sites |
| A JS framework | **Not used in v1** | A search dropdown does not justify a runtime. `@wordpress/*` packages when blocks arrive in P4 |
| PHP extensions | `json` (always present); `sodium` preferred for credential encryption with a documented fallback | Avoid hard requirements that fail on shared hosting |

Every future dependency must clear four gates before being added: GPL-compatible licence, active
maintenance, a real problem it solves that is larger than the code it replaces, and no namespace
collision risk with other plugins. Popularity is not one of the gates.

---

## Appendix C — Self-review log

The plan was reviewed once against itself after writing, checking for contradictions, premature
decisions, missing security considerations, WordPress.org problems, and anything that would make
future SaaS support unnecessarily difficult. What the pass found, and what changed.

### Corrected

| Finding | Change |
| --- | --- |
| §13.3 specified `qf` as `se_tax_*_ss^1.5`. The `edismax` `qf` parameter takes explicit field names and does not accept wildcards, so the relevance profile as written would not have worked | Added `se_tax_terms_txt` to the document model (§10.1) as a copyField aggregate of every taxonomy's term names, and pointed `qf` at it |
| §8 set the WordPress floor at 6.5 without acknowledging that WooCommerce requires 6.9 | Noted that the WooCommerce integration raises the effective floor on stores while the core plugin does not need to |
| §18.4 covered which content is indexed but not the two ways non-public content leaks without being returned | Added facet-count disclosure and shared-collection cross-site leakage, with `se_site_id_i` reclassified as a security control rather than a scoping convenience |
| Action Scheduler's licence was asserted as GPLv3 from memory | Verified against the repository. GPL-3.0 confirmed; the consequence for the distributed whole is recorded in ADR-004 and Appendix A |
| The brief asked for a feature prioritisation table and a list of deliberately deferred decisions; neither existed | Added §26.1 and §27.1. §27.1 names the property the architecture must preserve for each deferral, which is the part that actually costs something |
| No documentation strategy, though the brief asked for one | Added §24.1, with each document tied to a phase and one audience |

### Checked and found consistent

- **Principle 1 throughout.** §13.2 (`posts_pre_query` returning `null`), §13.6 (failure table),
  §12.6 (no synchronous mode) and §28 risk 8 agree. The fallback is one return statement, which is
  why it is credible.
- **Indexing and search independence** (Principle 2). ADR-007 splits `SearchBackend` from
  `IndexBackend`; §12 and §13 share no component but the document model.
- **No Solr syntax above the adapter** (Principles 3 and 4). §11.3 deliberately omits any hook
  exposing a raw backend request; §16.2 forbids raw parameters on public endpoints; ADR-008
  requires server-declared facets. These three would each individually break SaaS portability.
- **Free/pro compliance.** §22, ADR-010 and Appendix A all rest on the same quoted guideline text,
  and none of them assumes a licence-key model is acceptable.
- **No premature implementation.** The plan recommends a Cloud API surface but explicitly defers
  its implementation (§21.4, §27.1); recommends vector-search compatibility but builds nothing
  (§27); recommends multisite data modelling but defers the UI (§20).

### Accepted tensions, not resolved

- **PHP 7.4 costs modern language features.** Accepted deliberately in ADR-011, pinned to an
  external schedule rather than to preference, so it is revisited when WooCommerce moves rather
  than whenever someone wants enums.
- **Dropping Solarium means writing escaping and parsing ourselves.** ADR-001 accepts this and
  answers it with a test layer rather than with confidence.
- **Instant search will not feel instant in v1.** §19.4 states this rather than engineering around
  it, and converts it into an argument for the Cloud product.
- **Encrypted credentials are obfuscation against database disclosure, not against code
  execution.** ADR-009 says so in those words rather than claiming encryption at rest solves it.
- **DNS rebinding is not fully closed.** §18.1 documents the residual gap and the attacker
  precondition instead of implying completeness.

### Raised, and since answered

The provenance of the existing code (§2.3 defect #27, §29 Q12) could not be resolved by reading the
repository: three product names appeared in one codebase, one belonging to no part of this project.

**Answered by the owner on 2026-09-19: the project name is SolrEngine, and the other names were
leftover strings.** The stray names were renamed (§2.6), Q12 is closed, and risk 16 is closed. This
was the only blocking question the plan carried, and it is no longer blocking.
