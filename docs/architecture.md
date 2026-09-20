# Architecture

The reasoning lives in [the plan](../.claude/plans/solrengine-plan.md) and
[the ADRs](../.claude/plans/adr/). This file describes what exists in the code
today.

## Layers

```
WordPress surfaces          themes, blocks, REST, WP-CLI, WooCommerce
          |
Integration layer           knows WordPress, not the search engine
          |  Contracts only
Domain layer                knows neither
          |  Contracts only
Backend adapters            knows the engine, not WordPress
```

The rule that makes the separation real: **the domain layer must be
unit-testable with no WordPress loaded**. The unit suite enforces it, because
it never loads WordPress. If a unit test starts needing `get_option()`, the
seam has leaked.

## What exists after Phase 0

```
solrengine.php               Requirement gate and hook registration
src/Bootstrap.php            Composition root
src/Contracts/               Public API
  Result                     Outcome of anything that can fail
  Capabilities               What a backend can do, detected not assumed
  DocumentRef                Stable identity of one indexable object
  WriteResult                Outcome of a batch write, including partial failure
  IndexStatus                Index health snapshot
  SearchBackend              Reads
  IndexBackend               Writes
  SchemaBackend              Inspects and, where permitted, changes the schema
  LifecycleBackend           Builds a replacement index and switches to it
  LanguageProvider           Where a site's languages come from
  JobQueue                   Defers work
  Logger                     Records what happened
src/Support/
  Container                  Lazy service construction
  Config                     Defaults, then options, then constants
  Credentials                Constants first, encrypted option second
  Encryption                 Sodium, keyed from the site salts
  NullLogger / DebugLogger
src/Backend/BackendRegistry  Which backends exist, which is selected
src/Language/                Language tags, and the site's languages
src/Multisite/               Network configuration and this site's scope
```

Nothing connects to Solr yet. That is Phase 1.

## Three decisions visible in the code

**Failure is a value, not an exception.** Every backend call returns `Result`.
Nothing throws across the integration boundary, because an unreachable Solr is
an expected condition and WordPress must keep rendering. The eventual search
integration returns `null` from `posts_pre_query` on failure, which hands the
query straight back to WordPress.

**Identity is composite.** `DocumentRef::document_id()` produces
`se:{site_id}:{object_type}:{object_id}`. The prototype used the bare post ID,
which collides across post types, across sites and across object types. The
site identifier is written even on single-site installs so that the document
model works unchanged under either multisite topology.

**Construction is lazy.** Registering a service builds nothing. A request that
never searches and never saves a post pays for `Bootstrap` and nothing else.
`ContainerTest::test_registering_does_not_construct()` guards this.

## Extension points

| Hook | Type | Purpose |
| --- | --- | --- |
| `solrengine_booted` | action | Register a backend, facet or admin screen. Receives the container. Fires before anything is constructed |
| `solrengine_register_filters` | action | Register a filter a caller may narrow by |
| `solrengine_facet_definitions` | filter | Register a facet a caller may count by |
| `solrengine_indexable_post_types` | filter | Declare a post type as indexable. Honoured by the save listeners and by a full sync |
| `solrengine_suggest_query` | filter | The autocomplete query before it reaches the backend |
| `solrengine_full_sync_complete` | action | A full run finished. Receives the run state; a run with a `target` was building a replacement index rather than the live one |
| `solrengine_rebuild_switched` | action | Searches are now answered by a rebuilt index. Receives the index name and the rebuild state |
| `solrengine_language_provider` | filter | Supply a LanguageProvider for a multilingual plugin SolrEngine does not know about |

More arrive with the phases that need them. The plan's section 11.3 lists the
intended set; each one is public API from 1.0 and needs a test and a docblock
before it ships.

Two hooks that will deliberately never exist: anything exposing a raw backend
request, and anything letting a third party inject query syntax. Both would
make the injection guarantees unenforceable and would freeze Solr's wire format
as public API.

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
