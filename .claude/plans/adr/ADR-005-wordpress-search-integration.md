# ADR-005 — WordPress search integration mechanism

**Status:** Proposed · **Date:** 2026-09-19

## Context

SolrEngine must serve search results through WordPress while remaining compatible with classic
themes, block themes, page builders, WooCommerce loops and pagination — and must continue working
when Solr is unavailable.

The current implementation registers a custom rewrite rule, renders results by calling
`get_header()` and `get_footer()`, then calls `exit`. That breaks on block themes, which have no
`header.php`; it bypasses shutdown hooks and caching layers; and it ignores WordPress's own
`/?s=` search entirely, so the plugin's search is a parallel universe that no theme's search form
points at.

Researched fact: `posts_pre_query` was introduced in WordPress 4.6, fires inside
`WP_Query::get_posts()`, and returning a non-null value bypasses the default post query. The
documentation notes that filtering functions requiring pagination are "encouraged to set the
`found_posts` and `max_num_pages` properties", because no database query runs.

## Decision

**Replace the query, not the template. Mark eligible queries in `pre_get_posts`, return results
from `posts_pre_query`, and return `null` to fall back.**

```
pre_get_posts    decide eligibility: main query · is_search() · not admin
                 · not suppress_filters · passes solrengine_should_handle_query
posts_pre_query  query the backend
                 success → return posts (honouring $query->get('fields'))
                           and set found_posts and max_num_pages
                 failure → return null   ← WordPress runs its normal query
```

Additionally, REST endpoints for instant search, facet UIs and headless front ends, sharing the
same `SearchService` so relevance and visibility rules cannot diverge between the two paths.

## Alternatives considered

**`posts_request`** — replacing the SQL string. Rejected. It forces a database round trip to fetch
rows already held from Solr, and it competes with every other plugin filtering the same clause.

**`pre_get_posts` alone**, reordering by `post__in`. Rejected. Combining an ID list with pagination
and accurate totals is fragile, and it caps result sets at whatever fits in a query variable.

**A dedicated search endpoint and template only** — the current approach. Rejected as the primary
path. It abandons every existing theme search template and every search form already on the site.

**Front-end JavaScript replacing results after render.** Rejected as the primary path. It breaks
without JavaScript, is invisible to crawlers, and produces a visible flash of the original results.

## Consequences

**Positive.** Themes, block themes, page builders and WooCommerce loops work unchanged because none
of them can tell the difference — they interact with `WP_Query`, not with the database. Pagination,
result counts and template hierarchy behave normally. **The fallback costs one `return null`**,
which is what makes Principle 1 survive contact with maintenance: a fallback that is cheap stays
correct, and one that is expensive rots.

**Negative.** SolrEngine must set `found_posts` and `max_num_pages` itself. It must honour
`$query->get('fields')` so `ids` and `id=>parent` requests are not handed full objects. It must
prime the post cache with `_prime_post_caches()` to avoid one database query per rendered result.
All three are well-defined and testable.

**Neutral.** Some plugins filter `posts_pre_query` too. Priority ordering and the
`solrengine_should_handle_query` veto give site owners a way to resolve conflicts without patching.

## Why

Hooking at the query layer rather than the template layer is what makes the compatibility matrix in
§16 of the brief tractable. Every mechanism that touches templates has to be re-verified against
each theme, builder and block release; a mechanism that substitutes results into `WP_Query` is
verified once. That the same choice makes graceful degradation nearly free is the second, decisive
argument.
