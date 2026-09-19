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

| Setting | Default | What it does |
| --- | --- | --- |
| Field weights | title 5, taxonomy 1.5, excerpt 2, content 1 | Which fields matter most |
| Phrase boost | title 10, content 2 | Ranks documents with the words together above documents with them apart |
| Minimum match | `2<75%` | One or two words must all match; beyond that, three quarters |
| Tie breaker | 0.1 | Small credit for matches in secondary fields |
| Recency boost | off | Nudges newer content upward |

Taxonomy terms are weighted through a single aggregate field rather than a
wildcard, because Solr's `qf` takes explicit field names.

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
| `sort` | enum | `relevance`, `date_desc`, `date_asc`, `modified`, `title_asc`, `title_desc`, `menu_order` |
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
| `solrengine_rate_limit_request` | filter | Exempt a request from throttling |

---

# Instant search

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
only one still being typed.

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

A backend failure returns an empty list rather than an error. Autocomplete is an
enhancement: a visitor whose suggestions stop appearing can still press enter
and get a results page, and an error banner under a search box on every
keystroke would be worse than showing nothing.

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

## Honest note on speed

Solr answers these in single-digit milliseconds. The round trip a visitor
experiences is dominated by WordPress: a REST request boots PHP, the autoloader
and every active plugin before SolrEngine is reached. On typical shared hosting
that is an order of magnitude more than the search itself.

So this is fast autocomplete, not instant autocomplete, and the gap is not
something tuning Solr closes. A persistent object cache helps most, because
repeated prefixes never reach Solr at all. Removing the WordPress bootstrap
from the path entirely is what a hosted backend would make possible, and that
remains a future option rather than a promise.

## Customising

```php
// Point at a theme's own search markup.
add_filter( 'solrengine_instant_search_selector', function () {
	return '.my-theme-search input';
} );

// Reshape suggestions before they are returned.
add_filter( 'solrengine_suggestions', function ( $suggestions, $query ) {
	return $suggestions;
}, 10, 2 );
```
