# Architecture

The reasoning lives in [the plan](../.claude/plans/solrengine-plan.md) and
[the ADRs](../.claude/plans/adr/). This file describes what exists in the code
today.

## Layers

```
WordPress surfaces          themes, blocks, REST, WP-CLI, WooCommerce
          |
Integration layer           knows WordPress, not the search engine
          |  contracts only
Domain layer                knows neither
          |  contracts only
Backend adapters            knows the engine, not WordPress
```

The rule that makes the separation real: **the domain layer must be
unit-testable with no WordPress loaded**. The unit suite enforces it, because it
never loads WordPress — `tests/bootstrap.php` loads the shims *only* when
WordPress is absent, so a domain class that starts calling `get_option()` fails
immediately rather than quietly acquiring a dependency.

### Telling the layers apart

The boundary is not "does it mention WordPress" — it is **does it read or write
WordPress state**. A domain class may call a pure helper (`__()`,
`wp_json_encode()`, `wp_parse_url()`) and may fire a hook. It may not touch an
option, a post, a term, a user or the database.

`tests/wp-shims.php` is where that line is drawn in practice. Six functions are
shimmed — `__()`, `esc_html__()`, `wp_parse_url()`, `wp_json_encode()`,
`do_action()` and `apply_filters()` — because shimming those is honest, and the
file's own comment says that needing a shim "with real behaviour — an option, a
hook, a query" means a seam is leaking.

**The mechanical way to find the domain layer is to read the `@covers`
annotations in `tests/Unit/`.** If a class is in the unit suite, it is domain, by
construction — the suite could not run otherwise.

| Layer | Where | How to recognise it |
| --- | --- | --- |
| Domain | `Contracts/`, `Schema/`, `Language/`, most of `Search/`, the pure half of `Documents/` | In the unit suite. Constructor takes values or interfaces. No `get_option`, no `WP_Post`, no `$wpdb` |
| Integration | `Integration/`, `Admin/`, `Rest/`, `Cli/`, most of `Indexing/`, `Support/Config`, `Support/Credentials`, `Support/Tables`, and the WordPress-backed seams `Support/WpViewer`, `Support/ObjectCache`, `Support/OptionBreaker`, `Support/Upgrade` | Registers hooks, reads options, handles `WP_Post`/`WP_Query`, knows WooCommerce |
| Backend adapters | `Backend/Solr/`, `Backend/Memory/` | Implements the four backend contracts. Only place that speaks Solr |

Three borderline cases are worth knowing, because each one marks where a seam
was deliberately placed:

- **`Documents/` is split.** `DocumentFields`, `MetaAllowlist` and `TermPaths`
  are pure and unit-tested; `PostDocumentBuilder` and `MetaKeys` read posts and
  the database. The mapping rules are domain; reading WordPress is not.
- **`Search/` is split.** Eleven of thirteen classes are pure. The two that are
  not both resolve WordPress terms: `FacetLabeller` (turns term ids into names)
  and `FacetSelection` (interprets a selected term). `SearchService` is in the
  unit suite despite owning the cache and the circuit breaker, because it holds
  neither: it is handed a `Cache`, a `Breaker` and a `Viewer`, and calls no
  WordPress function of its own beyond firing its hooks.
- **`Schema/AppliedSchema` is domain despite storing state**, because it is
  handed a `Config` rather than calling `get_option()` itself, and its verdict
  logic is a pure static function with its own tests. That is what the seam looks
  like when it works.

## What the code contains

```
solrengine.php               Plugin entry point and requirement gate. Parses on
                             PHP 5.6 by necessity: it is what reports that PHP
                             is too old
templates/                   Front-end templates a theme overrides from
                             yourtheme/solrengine/. Today: the search popup
constants.php                Version, paths and schema contract version, in
                             their own file so no entry point redefines them
src/Bootstrap.php            Composition root. The only class that knows how
                             anything is constructed. Start reading here

src/Contracts/               PUBLIC API. Interfaces and immutable values
  Result                     Outcome of anything that can fail
  Capabilities               What a backend can do, detected not assumed
  Document / DocumentRef     A document, and the stable identity of one object
  SearchQuery / SearchResults Structured query and its answer. Serialisable
  Selector                   Which documents a bulk delete matches
  Facet* / Schema*           Facet and schema value objects
  SearchBackend              Reads
  IndexBackend               Writes
  SchemaBackend              Inspects and, where permitted, changes the schema
  LifecycleBackend           Builds a replacement index and switches to it
  LanguageProvider           Where a site's languages come from
  JobQueue / Logger          Defers work; records what happened
  Viewer                     Whether the request belongs to a signed-in user
  Cache                      Where a result set is kept for a while
  Breaker                    Whether the backend is currently worth calling

src/Support/                 Container, Config, Credentials, Encryption, Tables,
                             Upgrade, and the implementations of Viewer, Cache
                             and Breaker
src/Backend/BackendRegistry  Which backends exist, which indexes, which searches
src/Backend/Solr/            Endpoint validator, HTTP transport and its
                             sender, the adapter
src/Backend/Memory/          Second adapter. Proves the contracts hold without
                             Solr; reports itself managed. Development only
src/Documents/               WordPress object -> Document
src/Indexing/                The write path, plus full sync, rebuild and history
src/Schema/                  Contract definition, diff planner, exporter
src/Search/                  The read path: translation, relevance, facets, cache,
                             and InstantResults, which answers the search popup
src/Integration/WordPress/   Query substitution, hydration, filter UI
src/Integration/WooCommerce/ Products, shop queries, catalogue facets
src/Integration/Multilingual/ WPML, Polylang, core locale
src/Language/                Language tags; the site's languages
src/Multisite/               Network configuration and this site's scope
src/Rest/                    search, facets, suggest, instant, status
src/Admin/                   Connection, Dashboard, Content, Search, Tools, Network
src/Cli/                     Every WP-CLI command
```

## The three paths through it

Everything else is detail around these.

**Writing.** A content change marks a reference and returns; nothing expensive
happens in the request that caused it.

```
save_post / term / WooCommerce events
  -> Indexing\HookListeners        marks a reference, nothing more
  -> Indexing\DirtySet             coalesces duplicates within the request
  -> 'shutdown'                    QueueWorker::flush()
  -> Contracts\JobQueue            Action Scheduler, or WP-Cron
  -> Indexing\QueueWorker
  -> Indexing\Indexer
  -> Documents\PostDocumentBuilder WP_Post -> Document
  -> IndexBackend::write_batch
  -> Backend\Solr\SolrBackend -> HttpTransport
```

**Reading.** Two hooks: one marks the query cheaply, the other answers it.

```
'pre_get_posts'    Integration\WordPress\QueryIntegration::mark_query()
                   flags the query. Builds nothing, because this fires for
                   every menu and widget query on the page
'posts_pre_query'  QueryIntegration::maybe_substitute()
  -> Search\SearchService          cache, circuit breaker
  -> SearchBackend::search
  -> Search\QueryTranslator        the ONLY place Solr syntax is produced
  -> HttpTransport -> Search\ResponseParser
  -> Integration\WordPress\ResultHydration   SearchHit[] -> WP_Post[]
```

`return null` from `posts_pre_query` is the entire fallback story: WordPress runs
its own query and the visitor never knows.

Hooking the query layer rather than the template layer is what keeps themes,
block themes, page builders and WooCommerce loops working — none of them can tell
the difference.

**Autocomplete** goes through `GET /solrengine/v1/suggest`.

```
theme search field
  -> assets/src/instant-search.js    debounce, abort, cache
  -> Rest\SearchController::handle_suggest
  -> Search\SearchService            cache, circuit breaker, hooks
  -> SearchBackend::suggest
  -> Search\SuggestTranslator        the site scope and visibility filter
  -> HttpTransport -> WpHttpSender
  -> Search\SuggestionParser         documents -> Suggestions
  -> Search\SuggestPayload           the JSON the field reads
```

**The search popup** goes through `GET /solrengine/v1/instant`, which calls
`Search\InstantResults::answer()`. That class builds an ordinary `SearchQuery`
and owns the response shape, so the route holds no search logic. Its markup is
PHP templates rendered once per page — on a full boot, where the theme can
override them — into `<template>` elements the script fills; see
[search-modal.md](search-modal.md).

```
theme search field / button
  -> assets/src/search-modal.js       <dialog>, debounce, abort, cache
  -> Rest\InstantResultsController    reads q, post_type, page; RateLimiter
  -> Search\InstantResults            post_type facet + tagged post_type filter
  -> Search\SearchService             cache, circuit breaker, hooks
  -> SearchBackend::search -> QueryTranslator -> ... as for a results page
```

Both routes pay for `rest_api_init`, where every active plugin registers its
routes, before their callback runs. Development builds answered both from
`instant.php` on a `SHORTINIT` boot to avoid that; it was removed for the first
WordPress.org release (see [security.md](security.md#entry-points)). The seams
it used are still here, because they keep any future lighter surface on the same
code rather than a copy:

- `Bootstrap::services()` registers every factory without attaching hooks, and
  lets a reduced environment override the few whose definition assumes a full
  boot (`viewer`, `rest.rate_limiter.viewer`, `woocommerce.active`).
- `Backend\Solr\Sender` isolates putting bytes on the wire. `WpHttpSender`,
  through the WordPress HTTP API, is the only production implementation.
  Everything that decides what to send and what a reply means lives in
  `HttpTransport`, once.
- `SuggestPayload` and `InstantResults` own their response shapes, so a second
  surface could return them unchanged.

## Three decisions visible in the code

**Failure is a value, not an exception.** Every backend call returns `Result`.
Nothing throws across the integration boundary, because an unreachable Solr is
an expected condition and WordPress must keep rendering. `QueryIntegration`
returns `null` from `posts_pre_query` on failure, which hands the query straight
back to WordPress.

**Identity is composite.** `DocumentRef::document_id()` produces
`se:{site_id}:{object_type}:{object_id}`. The prototype used the bare post ID,
which collides across post types, across sites and across object types. The
site identifier is written even on single-site installs so that the document
model works unchanged under either multisite topology.

**Construction is lazy.** Registering a service builds nothing. A request that
never searches and never saves a post pays for `Bootstrap` and nothing else.
`ContainerTest::test_registering_does_not_construct()` guards this: registering
every factory and resolving one of them is measured in tenths of a millisecond.

## Extension points

**[docs/hooks.md](hooks.md) is the reference, and it is generated** from the
docblock above each hook by `bin/generate-hooks.php`. It is not duplicated here,
because a second hand-written list is a list that drifts — which is the whole
reason the generated one exists.

CI runs the generator with `--check`, so the reference cannot fall behind the
code. The same step fails when a hook has no docblock, no `@since`, or documents
a different number of parameters than it passes: hooks are public API from 1.0,
and an undocumented one has no contract.

The four worth knowing before reading anything else:

| Hook | Type | Purpose |
| --- | --- | --- |
| `solrengine_booted` | action | Register a backend, facet or admin screen. Receives the container, before anything is constructed |
| `solrengine_document` | filter | The primary customisation point: a document immediately before it is queued |
| `solrengine_register_filters` | action | Register a filter a caller may narrow by |
| `solrengine_facet_definitions` | filter | Register a facet a caller may count by |

Two hooks that will deliberately never exist: anything exposing a raw backend
request, and anything letting a third party inject query syntax. Both would make
the injection guarantees unenforceable and would freeze Solr's wire format as
public API.

## Credentials

Preference order, per ADR-009:

1. `SOLRENGINE_SOLR_USER` and `SOLRENGINE_SOLR_PASSWORD` in `wp-config.php`.
2. An encrypted, non-autoloaded option.
3. Never plaintext, never returned to the browser, never logged.

The encryption defends against a leaked database dump and nothing more. The key
is derived from the site salts, which sit next to the code, so code execution on
the host defeats it. `Encryption`'s class comment says so, and so does the
readme. Overstating a mitigation is worse than omitting it.

`Credentials::redact()` cleans context before it reaches a log, including
credentials embedded in URLs. It has its own test file, because a password in a
log is a disclosure whether or not anyone reads it.
