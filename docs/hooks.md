# Hooks

Every action and filter SolrEngine fires, with its signature and the release
it arrived in. Each one is public API from 1.0.

<!--
  GENERATED FILE. Do not edit.

  Written from the docblocks above each hook by bin/generate-hooks.php, so
  that a signature and its documentation cannot drift apart. Change the
  docblock and regenerate:

      php bin/generate-hooks.php
-->

26 hooks: 8 actions and 18 filters.

- [Startup](#startup)
- [What gets indexed](#what-gets-indexed)
- [Indexing](#indexing)
- [Searching](#searching)
- [Integration with WordPress](#integration-with-wordpress)
- [Languages](#languages)
- [The REST API](#the-rest-api)

## Startup

### `solrengine_allowed_ports`

Filter · since 0.1.0 · `src/Bootstrap.php:388`

Ports SolrEngine will connect to.

Widening this widens the surface an administrator could use
to probe internal infrastructure, so it is a filter rather
than a setting: a deliberate act by a site owner, in code.

| Parameter | Type | Description |
| --- | --- | --- |
| `$ports` | `int[]` | Permitted ports. |

### `solrengine_booted`

Action · since 0.1.0 · `src/Bootstrap.php:192`

Fires once SolrEngine has registered its services.

The place for an add-on to register a backend, a facet or an admin
screen. Nothing has been constructed yet, so registering here costs
nothing on requests that never use it.

| Parameter | Type | Description |
| --- | --- | --- |
| `$container` | `Container` | SolrEngine's service container. |

## What gets indexed

### `solrengine_document`

Filter · since 0.3.0 · `src/Documents/PostDocumentBuilder.php:190`

Filters a document immediately before it is queued for indexing.

The primary customisation point. Field names must follow SolrEngine's
conventions, since anything else has no dynamic field to land in and
will be rejected by the backend.

| Parameter | Type | Description |
| --- | --- | --- |
| `$document` | `Document` | The built document. |
| `$post` | `WP_Post` | The post it was built from. |

### `solrengine_indexable_post_types`

Filter · since 0.3.0 · `src/Documents/IndexedTypes.php:49`

Filters which post types are eligible for indexing.

Where an integration declares a post type of its own — WooCommerce
adds products here — and where a site removes one.

| Parameter | Type | Description |
| --- | --- | --- |
| `$types` | `string[]` | Configured post types. |

### `solrengine_should_index_object`

Filter · since 0.3.0 · `src/Documents/PostDocumentBuilder.php:115`

Filters whether one object is indexed.

Return a failure Result to veto, with a code explaining why. The
reason is recorded, so a vetoed object shows up as "skipped" rather
than as silently missing.

| Parameter | Type | Description |
| --- | --- | --- |
| `$verdict` | `Result` | Whether to index, and why not if not. |
| `$post` | `WP_Post` | The post being considered. |

## Indexing

### `solrengine_drift_detected`

Action · since 0.4.0 · `src/Indexing/Reconciler.php:145`

Fires when the index and WordPress disagree beyond tolerance.

| Parameter | Type | Description |
| --- | --- | --- |
| `$report` | `array<string,mixed>` | What was found. |

### `solrengine_full_sync_complete`

Action · since 0.3.0 · `src/Indexing/FullSync.php:305`

Fires when a full synchronisation finishes.

| Parameter | Type | Description |
| --- | --- | --- |
| `$run` | `array<string,mixed>` | Final run state. |

### `solrengine_index_failed`

Action · since 0.3.0 · `src/Indexing/Indexer.php:247`

Fires when one document could not be indexed.

| Parameter | Type | Description |
| --- | --- | --- |
| `$ref` | `DocumentRef` | The document that failed. |
| `$error` | `string` | Why it failed. |

### `solrengine_indexed`

Action · since 0.3.0 · `src/Indexing/Indexer.php:135`

Fires after a batch has been written to the index.

| Parameter | Type | Description |
| --- | --- | --- |
| `$refs` | `DocumentRef[]` | References that were processed. |
| `$outcome` | `IndexOutcome` | What happened to them. |

### `solrengine_rebuild_switched`

Action · since 0.8.0 · `src/Indexing/Rebuild.php:524`

Fires when searches have been switched to a rebuilt index.

| Parameter | Type | Description |
| --- | --- | --- |
| `$target` | `string` | Index now serving searches. |
| `$state` | `array<string,mixed>` | Final rebuild state. |

### `solrengine_run_full_sync_batch`

Action · since 0.3.0 · `src/Indexing/QueueWorker.php:225`

Fires when the queue asks for the next full-sync batch.

## Searching

### `solrengine_facet_definitions`

Filter · since 0.6.0 · `src/Search/FacetRegistry.php:120`

Filters the registered facets.

The place to expose an indexed meta field as a facet, to change a
limit, or to remove one. Anything not registered here cannot be
counted through the API, by design: that is the constraint which
keeps the public endpoint from becoming a Solr proxy.

| Parameter | Type | Description |
| --- | --- | --- |
| `$registry` | `FacetRegistry` | The registry. |

### `solrengine_register_filters`

Action · since 0.4.0 · `src/Search/FilterRegistry.php:102`

Filters the registered search filters.

The place to expose an indexed meta field as a filter. Anything not
registered here cannot be filtered on through the API, by design.

| Parameter | Type | Description |
| --- | --- | --- |
| `$registry` | `FilterRegistry` | The registry. |

### `solrengine_search_query`

Filter · since 0.4.0 · `src/Search/SearchService.php:159`

Filters a query before it reaches the backend.

| Parameter | Type | Description |
| --- | --- | --- |
| `$query` | `SearchQuery` | The query about to run. |

### `solrengine_search_results`

Filter · since 0.4.0 · `src/Search/SearchService.php:197`

Filters results before they are returned.

| Parameter | Type | Description |
| --- | --- | --- |
| `$results` | `SearchResults` | The results. |
| `$query` | `SearchQuery` | The query that produced them. |

### `solrengine_suggest_query`

Filter · since 0.7.0 · `src/Search/SearchService.php:240`

Filters an autocomplete query before it reaches the backend.

The counterpart of `solrengine_search_query`, and it exists for the
same reason: rules that narrow what a visitor may see have to reach
every path that reads the index, not only the results page.

| Parameter | Type | Description |
| --- | --- | --- |
| `$query` | `SuggestQuery` | The query about to run. |

### `solrengine_suggestions`

Filter · since 0.5.0 · `src/Search/SearchService.php:281`

Filters suggestions before they are returned.

| Parameter | Type | Description |
| --- | --- | --- |
| `$suggestions` | `Suggestions` | The suggestions. |
| `$query` | `SuggestQuery` | The query that produced them. |

## Integration with WordPress

### `solrengine_instant_search_selector`

Filter · since 0.5.0 · `src/Integration/WordPress/InstantSearch.php:81`

Filters which inputs get instant search.

Defaults cover the block search input and the classic search
form. A theme with an unusual markup can point this at its
own selector rather than being left out. Applies to the
suggestion list and the search popup alike.

| Parameter | Type | Description |
| --- | --- | --- |
| `$selector` | `string` | A CSS selector. |

### `solrengine_search_modal_args`

Filter · since 1.0.0 · `src/Integration/WordPress/SearchModal.php:233`

Filters what the search popup's template receives.

Change the heading, tagline or placeholder, or opt in to the
"Powered by SolrEngine" line, without overriding the template.


}

| Parameter | Type | Description |
| --- | --- | --- |
| `$args` | `array` | { |

### `solrengine_search_modal_triggers`

Filter · since 1.0.0 · `src/Integration/WordPress/SearchModal.php:361`

Filters which elements open the search popup when clicked.

For search icons, menu items and buttons that are not part of a
search form. The theme's search fields and the buttons that
submit them are matched separately, through
`solrengine_instant_search_selector`.

| Parameter | Type | Description |
| --- | --- | --- |
| `$selector` | `string` | A CSS selector. |

### `solrengine_search_modal_width`

Filter · since 1.0.0 · `src/Integration/WordPress/SearchModal.php:314`

Filters the width the search popup grows to.

For a theme that declares no width, or declares a narrower one than
its layout: return the site container's width, such as `'1200px'`.
A plain length, a percentage, or calc(), min(), max(), clamp() or
var() over them; anything else is ignored. Return '' for the
stylesheet's default. A theme can also set
`--solrengine-modal-width` in CSS, which wins over this.


                     `$content_width`, as a CSS length, or ''.

| Parameter | Type | Description |
| --- | --- | --- |
| `$width` | `string` | The theme's wide size, content size or |

### `solrengine_should_handle_query`

Filter · since 0.4.0 · `src/Integration/WordPress/QueryIntegration.php:225`

Filters whether SolrEngine handles a particular query.

The escape hatch for a site where another plugin also filters
posts_pre_query, or where one search form should stay on WordPress.

| Parameter | Type | Description |
| --- | --- | --- |
| `$eligible` | `bool` | Whether to handle it. |
| `$query` | `WP_Query` | The query. |

### `solrengine_template`

Filter · since 1.0.0 · `src/Integration/WordPress/Templates.php:75`

Filters the file a SolrEngine template resolves to.

For a plugin, rather than a theme, that wants to supply a template:
a theme needs no hook, because a file at
`{theme}/solrengine/{name}` is found on its own. Return '' to
suppress a template altogether — a per-post-type card, for instance,
so that type falls back to the default card.

| Parameter | Type | Description |
| --- | --- | --- |
| `$path` | `string` | Absolute path found, or '' when none was. |
| `$name` | `string` | Relative template name. |

### `solrengine_wc_price_bands`

Filter · since 0.7.0 · `src/Integration/WooCommerce/ProductFacets.php:262`

Filters the price bands a shop's price facet offers.

Return start, end and gap. A gap of zero removes the facet, which is
what a catalogue with no priced products gets.

| Parameter | Type | Description |
| --- | --- | --- |
| `$bands` | `array{start:float,end:float,gap:float}` | The derived bands. |
| `$ceiling` | `float` | The catalogue's highest price. |

## Languages

### `solrengine_language_provider`

Filter · since 0.9.0 · `src/Language/Languages.php:95`

Filters which provider SolrEngine reads languages from.

The extension point for a multilingual plugin SolrEngine does not know
about. Return an object implementing the LanguageProvider contract.

| Parameter | Type | Description |
| --- | --- | --- |
| `$provider` | `LanguageProvider|null` | The provider chosen, or null. |
| `$available` | `LanguageProvider[]` | Every provider considered. |

## The REST API

### `solrengine_rate_limit_request`

Filter · since 0.4.0 · `src/Rest/RateLimiter.php:110`

Filters whether a request is subject to rate limiting.

Returning false skips the check, which is how a site exempts its own
front end or a trusted integration.

| Parameter | Type | Description |
| --- | --- | --- |
| `$limited` | `bool` | Whether to apply the limit. |
| `$route` | `string` | Route being called. |
