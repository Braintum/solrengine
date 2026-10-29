# Search

## How it works

SolrEngine hooks WordPress at the query layer, not the template layer. When a
search runs, `posts_pre_query` returns posts from Solr and WordPress does no
database query of its own.

That is why no theme change is needed. Classic themes, block themes, page
builders and pagination helpers all interact with `WP_Query`, and none of them
can tell where the posts came from.

## When Solr is unavailable

The filter returns `null` and WordPress runs the query it would have run
anyway. Visitors get ordinary WordPress results and see no error.

After three consecutive failures SolrEngine stops trying for a minute. Without
that, an outage of the search backend would make every visitor wait for the
full timeout before falling back, turning a search problem into a site-speed
problem.

The REST endpoint behaves differently on purpose: it returns HTTP 503 rather
than quietly serving WordPress results. A client calling a search API expects
that API's behaviour, and silently substituting something else would make the
outage invisible to it.

## Relevance

Tune these under **SolrEngine → Search**. They apply to the next search; no
reindexing is needed.

| Setting | Default | What it does |
| --- | --- | --- |
| Field weights | title 5, taxonomy 1.5, excerpt 2, content 1 | Which fields matter most |
| Phrase boost | title 10, content 2 | Ranks documents with the words together above documents with them apart |
| Minimum match | `2<75%` | One or two words must all match; beyond that, three quarters |
| Tie breaker | 0.1 | Small credit for matches in secondary fields |
| Recency boost | off | Nudges newer content upward |

Taxonomy terms are weighted through a single aggregate field rather than a
wildcard, because Solr's `qf` takes explicit field names.

Setting a field's weight to `0` stops searching it entirely. Setting every field
to zero is refused: a site that searches nothing returns nothing to every
visitor, and the screen that would undo it is not the one they are using.

The minimum-match expression is validated when you save it rather than when a
visitor searches. An invalid one is not a degraded search — Solr rejects the
whole request, so every search on the site fails until somebody edits the value
back. Valid forms are a number (`2`), a percentage (`75%`), or one or more
`terms<specification` pairs (`2<75% 5<90%`).

On a WooCommerce site, product SKUs and attribute values appear on the list as
well. SolrEngine supplies a sensible weight for each the first time, and never
overrules one you have set.

On a multilingual site, searches are answered in the language the visitor is
reading and text can be analysed per language. See [multilingual](multilingual.md).

## When nothing matches

Three attempts, stopping at the first that finds something:

1. Normal search.
2. Typo tolerance, one edit per word of four letters or more.
3. Loosened matching, where any one word is enough.

Fuzziness is a retry rather than the default. Applying it to every search would
cost precision for every visitor in order to rescue the few queries that need
it, and it is slower.

The results report which attempt produced them, so a theme can say "no exact
matches, showing similar results" instead of presenting an approximation as
though it were what was asked for.

## The REST API

```
GET /wp-json/solrengine/v1/search
```

| Parameter | Type | Notes |
| --- | --- | --- |
| `q` | string | Search text, up to 512 characters |
| `page` | integer | One-based |
| `per_page` | integer | Capped at 50 |
| `sort` | enum | `relevance`, `date_desc`, `date_asc`, `modified`, `title_asc`, `title_desc`, `menu_order`, `price_asc`, `price_desc`, `popularity`, `rating` |
| `post_type` | array | Restricted to indexed types |
| `filters` | object | Keys must be registered. Unknown keys are rejected |
| `facets` | array | Registered facet keys, up to ten. See [facets.md](facets.md) |
| `facet_parent` | object | Where to sit in a taxonomy tree, per facet |
| `highlight` | boolean | Request highlighted snippets |

```json
{
  "results": [
    { "id": 42, "title": "…", "url": "…", "excerpt": "…", "score": 8.1 }
  ],
  "total": 137,
  "page": 1,
  "per_page": 10,
  "pages": 14,
  "took_ms": 6,
  "strategy": "standard"
}
```

The administrator endpoint at `/wp-json/solrengine/v1/status` reports
connection, index, queue and synchronisation state. It requires
`manage_options`.

## What the API will not accept

A caller may **name** things. It may never **describe** them.

- `q` is bound as a request parameter and referenced by the query template, so
  the text is a value rather than syntax.
- `sort` is an enum. A caller cannot name a field, which would leak the schema
  and permit sorting on fields without docValues.
- `filters` keys come from a registry. An unregistered key is **rejected**, not
  ignored, because ignoring one hides a client bug and makes probing free.
- Filter values are escaped even though they come from a registry, since a
  taxonomy term containing a colon or a quote would otherwise change what a
  filter matches.
- `facets` keys come from a registry too, and facet JSON is never accepted. A
  caller asks for "category"; what a category facet *is* was decided here.
- There is no route for a filter query, a facet definition, or any other Solr
  syntax. No hook exposes one either, deliberately.

Every case above has a test that runs against a real Solr.

## Adding a filter

```php
add_action( 'solrengine_register_filters', function ( $registry ) {
	$registry->register( 'brand', 'se_ss_meta_brand', 'ss', 'Brand' );
} );
```

The field must be one SolrEngine owns, meaning it starts with `se_`, and the
meta key must already be on the indexing allowlist.

To let visitors *browse* by that filter rather than only request it, register a
facet with the same key as well. See [facets.md](facets.md).

## Changing backend

SolrEngine can use more than one search backend, chosen on the connection screen
when more than one is registered.

Moving between two is done without taking search down, using the same principle
as the index rebuild: fill the new one while the old one still answers visitors.

```bash
wp solrengine backends status
wp solrengine backends start --to=<backend>
wp solrengine index
wp solrengine backends finish
```

`finish` refuses while the new backend holds noticeably fewer documents than
WordPress says it should. `abort` puts indexing back where searches never left,
and deletes nothing.

## Caching

Results are cached for anonymous visitors only. A logged-in user may be able to
see content a visitor cannot, and one shared cache entry would serve one of
them the other's view.

Cache keys include the schema contract version, so a schema change or a reindex
invalidates everything at once.

## Rate limiting

The public endpoint is throttled per IP address, and this is **not** denial-of-
service protection. It discourages casual abuse and accidental request loops.
Without a persistent object cache it does nothing at all, and the dashboard
says so rather than implying protection that is absent. Real protection belongs
at the CDN or web server.

## Developer hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `solrengine_should_handle_query` | filter | Veto SolrEngine handling one `WP_Query` |
| `solrengine_search_query` | filter | The query before it reaches the backend |
| `solrengine_search_results` | filter | The results before they are returned |
| `solrengine_register_filters` | action | Register a filter |
| `solrengine_facet_definitions` | filter | Register a facet |
| `solrengine_suggest_query` | filter | The autocomplete query before it reaches the backend |
| `solrengine_rate_limit_request` | filter | Exempt a request from throttling |

---

# Instant search

Instant search comes in two forms, chosen on the settings screen: a **search
popup** with result cards and content-type tabs, which is the default and is
documented in [search-modal.md](search-modal.md), and the **suggestion list**
described here — a dropdown of matching titles under the field. Both attach to
the theme's own search field, answer through the REST API, and are switched by
`search.instant_mode`.

Suggestions appear as a visitor types, attached to the search field the theme
already renders. SolrEngine does not provide its own search box: matching a
theme's styling on every theme ever written is not a thing a plugin can do, and
being wrong about it is worse than adding nothing.

Enable it on the SolrEngine settings screen. It works independently of search
replacement, so you can try suggestions before switching search over.

## What a suggestion is

A document, not a word. A visitor typing "macb" wants the MacBook review, not
the word "macbook" and a second keypress. Picking a suggestion navigates
straight to the page.

## Why not Solr's Suggester component

The Suggester needs a `searchComponent` and a request handler in
`solrconfig.xml`. SolrEngine cannot apply those: the Schema API reaches fields,
not configuration, and changing a configset means uploading and reloading it.

Measured against a stock Solr 10, the default configset has no `/suggest`
handler at all. A Suggester-based autocomplete would therefore have worked only
for customers who created their collection from SolrEngine's own configset, and
would have been silently broken for everyone else.

A prefix query needs no configuration and works on any collection with the
schema contract applied. Only the last word gets a wildcard, because it is the
only one still being typed, and only when it is a plain word of two or more
letters or digits: punctuation is never turned into syntax, and a one-letter
prefix expands to most of the term dictionary. The rule lives in
`Search\PrefixTerms`, which the search popup uses too.

## Completing a search query

A `SearchQuery` can ask for the same treatment with `with_completion()` (since
1.0.0). Every search a visitor types asks for it: the search popup, the results
page, the filter panel's counts beside it, and WooCommerce's product search. The
last word is then matched as a prefix, so "hoo" finds "Hoodie", and every other
word still has to match as written. The popup and the page therefore agree: what
"hoo" showed in the popup is what pressing Enter shows on the page.

The REST `/search` route does not complete. It is an API for code, which sends
finished words; a caller that wants completion appends the `*` itself.

A wildcard match scores every document alike, which on its own would rank
"Hoodie" level with "Hoodie with Logo" even when the visitor typed the whole
word. So the text exactly as typed is sent alongside as a boost query (`bq`):
it reorders what the prefix query matched and never adds to it. Measured on the
development index, Solr answered completed queries in 8–26 ms.

Prefixes match the neutral `se_txt_*` fields, which are not stemmed. They do not
match a language's stemmed fields — `hoodie*` cannot match the stem `hoodi` —
but the neutral fields are always searched, so nothing is lost. The fuzzy retry
is not completed: a word already carrying `~1` is not a plain word.

To match whole words only, everywhere, turn it off on the way in:

```php
add_filter( 'solrengine_search_query', function ( $query ) {
	return $query->with_completion( false );
} );
```

With search replacement off, the results page is WordPress's own search, which
matches any part of a word already; completion only matters where SolrEngine
answers.

## Privacy

Suggestions go through the same filters as search. Site scope and public
visibility are applied server-side and cannot be removed by a caller or a hook.
An autocomplete leaking a private title leaks it as completely as a results page
does, so it is tested the same way.

## The endpoint

```
GET /wp-json/solrengine/v1/suggest?q=macb&limit=8
```

```json
{
  "suggestions": [
    { "text": "MacBook Pro review", "url": "…", "id": 42, "post_type": "post" }
  ],
  "prefix": "macb",
  "took_ms": 3
}
```

The response shape is produced by one class, `Search\SuggestPayload`, so any
other surface that ever answers autocomplete returns exactly this body.

A backend failure returns an empty list rather than an error. Autocomplete is an
enhancement: a visitor whose suggestions stop appearing can still press enter
and get a results page, and an error banner under a search box on every
keystroke would be worse than showing nothing. An unconfigured backend and an
open circuit breaker read the same way.

## In the browser

| Behaviour | Why |
| --- | --- |
| 150 ms debounce | One request per pause, not one per keystroke |
| Two-character minimum | One character matches most of the index and says nothing |
| In-flight requests cancelled | Otherwise a slow response for an old prefix can land last and overwrite a newer one |
| Response prefix checked | Second line of defence against the same race |
| 30-second private browser cache | Visitors type the same prefixes, and backspacing returns to one just sent |

## Accessibility

Implemented as an ARIA combobox. Focus stays in the input throughout; the
active option is conveyed with `aria-activedescendant` rather than by moving
focus, because moving focus would break typing. Arrow keys move, Enter follows
the highlighted option, Escape closes, Tab leaves. A live region announces how
many suggestions appeared, since otherwise a screen reader user types and hears
nothing change. Keyboard highlighting survives forced-colours mode.

Enter with nothing highlighted submits the form normally, so pressing Enter
always does something predictable.

## Where the time goes

Solr answers a prefix query in single-digit milliseconds. What a visitor
experiences is dominated by everything WordPress does before the query runs.

A REST request is dispatched through `rest_api_init`, which is where every active
plugin registers its own routes. Measured on a WooCommerce site with OPcache
warm, a suggest request spent about 830ms reaching the callback and 67ms
answering it — 92% of the latency was other plugins booting, on the one request
type that fires on every keystroke. No amount of tuning inside the callback
reaches a cost paid before it runs.

Earlier development builds answered autocomplete from a separate file that
booted WordPress with `SHORTINIT` and skipped that cost. It has been removed for
the first WordPress.org release, because the directory does not accept a plugin
file that loads WordPress itself. A lighter path may return once it can be done
in a way the directory accepts.

Two settings outside the plugin matter more than anything inside it. **OPcache**
must be enabled: without it PHP recompiles every file on every request, which on
a WooCommerce site measured over 20MB of source and several seconds. And the
**database should be local to the web server** — a database on another host adds
its round trip to each query WordPress makes during the boot.

A persistent object cache helps on top of both, because repeated prefixes are
served from the cache without reaching Solr. The 150 ms debounce and the browser
cache above keep the number of requests down.

## Customising

```php
// Point at a theme's own search markup. Applies to the popup too.
add_filter( 'solrengine_instant_search_selector', function () {
	return '.my-theme-search input';
} );

// Reshape suggestions before they are returned.
add_filter( 'solrengine_suggestions', function ( $suggestions, $query ) {
	return $suggestions;
}, 10, 2 );
```

## Settings

| Key | Default | Effect |
| --- | --- | --- |
| `search.instant_enabled` | `false` | Whether autocomplete is attached at all |
| `search.instant_mode` | `modal` | `modal` for the [search popup](search-modal.md), `suggest` for the suggestion list |
| `search.instant_limit` | `8` | Suggestions requested. The search popup pages its own results |
| `search.instant_timeout_ms` | `1500` | How long to wait for Solr. Deliberately far below the 10s a search allows: a suggestion arriving after two more keystrokes is not a suggestion |
