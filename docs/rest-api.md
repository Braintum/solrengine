# REST API

Five endpoints under `solrengine/v1`. Four are public and serve content a
visitor can already read; one requires a capability.

```
GET /wp-json/solrengine/v1/search      documents, totals and optional facet counts
GET /wp-json/solrengine/v1/facets      facet counts only, no documents
GET /wp-json/solrengine/v1/suggest     autocomplete
GET /wp-json/solrengine/v1/instant     the search popup: results and per-type counts
GET /wp-json/solrengine/v1/status      health and configuration (manage_options)
```

## The rule that defines this API

**A caller names what it wants; it never describes how to get it.**

Filters and facets are named by *key* — `category`, `post_type` — and what a key
means is decided on the server from a registry. A caller cannot supply a field
name, a query fragment, a sort field, a range, a facet definition or anything else
that Solr would interpret. There is no parameter that reaches Solr as syntax, and
no hook that exposes one.

That single constraint is what keeps these endpoints from being an unauthenticated
Solr proxy. It is why `sort` is an enum rather than a field name, why `facets`
takes keys rather than JSON, and why an unknown key is a 400 rather than something
ignored.

## `GET /search`

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `q` | string | `''` | The search text. Maximum 512 characters. Empty means browse. Words match whole; unlike the popup and the results page, the last word is not completed |
| `page` | integer | `1` | |
| `per_page` | integer | `10` | Maximum 50 |
| `sort` | string | `relevance` | One of the enum below |
| `post_type` | string[] | `[]` | Restrict to these types |
| `filters` | object | `{}` | `{ "category": [12, 14] }`. Keys must be registered |
| `facets` | string[] | `[]` | Facet keys to count |
| `facet_parent` | object | `{}` | `{ "category": "1/12" }` — the node whose children to count |
| `highlight` | boolean | `false` | Ask for matched snippets |

`sort` accepts `relevance`, and the date, title and menu-order keys the query
translator declares. Send an unrecognised value and WordPress rejects the request
from the argument schema before any code runs.

### Response

```json
{
  "results": [
    {
      "id": 123,
      "type": "post",
      "post_type": "post",
      "title": "Choosing a laptop",
      "excerpt": "…",
      "url": "https://example.com/choosing-a-laptop/",
      "date": "2026-03-04T11:20:00Z",
      "author": "Dana Okonjo",
      "thumbnail": "https://example.com/wp-content/uploads/laptop.jpg",
      "score": 8.1412,
      "highlights": { "se_txt_content": ["… a <mark>laptop</mark> for …"] }
    }
  ],
  "total": 482,
  "page": 1,
  "per_page": 10,
  "pages": 49,
  "took_ms": 14,
  "strategy": "standard"
}
```

`highlights` is present only when `highlight=true` and Solr produced snippets.
Snippet markup is stripped to `<mark>` and nothing else, at the last point before
it reaches a browser.

`strategy` says which attempt produced these results: `standard`, `fuzzy` when the
exact search found nothing and spelling was allowed for, or `loosened` when any
single word was accepted. A theme can use it to say "no exact matches, showing
similar results" rather than presenting an approximation as though it were what
was asked for.

`facets` is present whenever `facets` was requested, even when every count came
back empty — a client that has to tell "no counts" from "my key was wrong" has
already been told about the second by a 400.

### Facet shape

```json
{
  "facets": {
    "category": {
      "label": "Categories",
      "type": "hierarchy",
      "parent": "1/12",
      "selected": ["1/12/34"],
      "buckets": [
        { "key": "1/12/34", "label": "Beginner", "count": 31, "selected": true, "has_children": true }
      ]
    }
  }
}
```

`has_children` appears only where it is true, so its absence means "leaf" without
a client needing to know which facet types nest.

## `GET /facets`

The same parameters as `/search` minus `page`, `per_page`, `sort` and
`highlight` — none of which changes a count. It returns the `facets` object and no
documents.

Use it to render a filter panel without paying for documents nobody will show.
That is the request a visitor repeats most, once per tick of a checkbox.

## `GET /suggest`

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `q` | string | `''` | Maximum 128 characters. Fewer than 2 returns an empty list without asking Solr |
| `limit` | integer | `8` | Maximum 20 |
| `post_type` | string[] | `[]` | |

The limit is deliberately lower than `/search`: a list longer than a screenful is
not a list anybody reads, and every row costs latency on a request that fires while
someone is still typing.

This is the address the theme's search field calls for the suggestion list. It
runs on a full boot, so `solrengine_suggest_query` and `solrengine_suggestions`
fire and `post_type` narrowing works. See [search.md](search.md#where-the-time-goes)
for where its latency goes.

## `GET /instant`

Since 0.11.0. What the [search popup](search-modal.md) shows: a count per
content type, and either one short section per type (All) or a page of 24
results from one type.

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `q` | string | `''` | Maximum 200 characters. Fewer than 2 returns an empty body without asking Solr |
| `post_type` | string | `''` | One indexed post type, or empty for all. An unknown type reads as all |
| `page` | integer | `1` | A type tab's page. Ignored on All. Below 1 reads as 1, and paging stops at the 1,000th result |

```json
{
  "query": "lamp",
  "post_type": "",
  "total": 5,
  "all": 5,
  "counts": { "product": 2, "page": 2, "post": 1 },
  "page": 1,
  "per_page": 4,
  "has_more": false,
  "results": [],
  "sections": [
    { "post_type": "product", "total": 2, "results": [
      { "id": 12, "type": "post", "post_type": "product", "title": "…", "excerpt": "…",
        "url": "…", "thumbnail": "…", "date": "…", "author": "…",
        "price": { "current": 89, "regular": 110 } }
    ] },
    { "post_type": "page", "total": 2, "results": [ "…" ] },
    { "post_type": "post", "total": 1, "results": [ "…" ] }
  ],
  "took_ms": 41,
  "strategy": "standard"
}
```

The last word of `q` is matched as a prefix, as the popup needs while a visitor
types: `q=hoo` finds "Hoodie". See [search.md](search.md#completing-a-search-query).
With `post_type` set, `sections` is empty and `results` holds the page asked for;
`has_more` says whether another follows. `counts` is unaffected by `post_type`: the tab is applied as the tagged
`post_type` filter, which the `post_type` facet excludes from its own counts.
`price` appears only on documents that carry one; what each of its keys means is
in [search-modal.md](search-modal.md#response).

Deliberately narrower than `/search`: the popup has no use for sorting or
arbitrary filters. Rate limited under its own bucket, `instant`.

## `GET /status`

Requires `manage_options`. Reports the plugin version, the required and applied
schema contract versions, the full connection diagnosis, the document count and
selected backend, the queue driver and depth, and the last full sync's state.

This is the only endpoint that names the backend. The public ones deliberately do
not: §16.5 sketches an envelope carrying `"backend": "solr"`, and it is left out
because telling an anonymous caller which search engine a site runs is the same
category of disclosure as an error message echoing an internal host name, which
the transport layer is careful to avoid. A client that needs to know is a client
that can authenticate.

## Errors

| Code | Status | Means |
| --- | --- | --- |
| `solrengine_invalid_filter` | 400 | A filter key is not registered. The response lists the keys that are |
| `solrengine_invalid_filter_value` | 400 | A filter value is not usable for that filter's type |
| `solrengine_invalid_facet` | 400 | A facet key is not registered |
| `solrengine_rate_limited` | 429 | Too many requests. Carries `retry_after` |
| `solrengine_backend_unavailable` | 503 | Solr could not be reached. Carries `retry_after` |

An unknown key is refused rather than ignored, and the refusal says what is
available. Ignoring it would let a client build against a filter that silently
does nothing.

A 503 rather than an empty result set, because "the search engine is down" and
"nothing matched" are different facts and a client should be able to retry one of
them. Note that this differs from the *theme* path, where an unreachable Solr
falls back to WordPress's own search so a visitor sees results rather than an
error.

## Rate limiting

Off unless a persistent object cache is present, and the dashboard says so rather
than implying protection that is not there. Limits are per IP address per window,
configurable, and applied to the four public endpoints.

Behind a CDN or reverse proxy, limit there instead. This is a backstop, not a
perimeter.

## Caching

Search responses are cached briefly, keyed by a hash of the whole structured
query. The cache holds results, not who asked. See [privacy](privacy.md).

## Versioning

`v1` is frozen on release. Additive changes only — a new optional parameter, a new
key in a response object — and anything breaking gets `v2`, with both served
through a deprecation window.

Two things that follow, and are worth relying on:

- **A new key may appear in a response object.** Parse by key; do not assert the
  shape is exhaustive.
- **A key will not change meaning or disappear within `v1`.** If one has to, the
  namespace changes.
