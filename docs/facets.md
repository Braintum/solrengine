# Facets and filters

A facet is a count: "Guides (31)". A filter is what happens when a visitor
clicks it. SolrEngine computes both in the same Solr request, which is the only
way the number beside a choice can be trusted to match the results that choice
produces.

## The rule that shapes everything here

**Facets are declared on the server. A caller names one; it never describes
one.**

A request says `facets=category,post_type`. It cannot send facet JSON, a field
name, a limit, or a filter query. What "category" means — which field, how many
buckets, how they sort, what values may filter by it — is decided by the facet
registry inside WordPress.

That single constraint is what lets the search endpoint be public at all.
Accepting raw facet or filter syntax from a client would expose stored fields
that are not meant to be read, permit expensive queries, and allow the index to
be enumerated. See ADR-008.

## Putting filters on a page

Two front doors to the same renderer:

- The **Search filters** block, for block themes. Add it to a search results
  template, or to any template that shows Solr results.
- `[solrengine_filters]`, for classic themes, widget areas and page builders.

```
[solrengine_filters facets="category,post_type" heading="Narrow these results" counts="yes"]
```

| Attribute | Default | What it does |
| --- | --- | --- |
| `facets` | every registered facet | Comma-separated facet keys, in the order to show them |
| `heading` | none | A heading above the panel |
| `counts` | `yes` | Show the number beside each value |
| `class` | none | Extra class names on the form |

The panel renders nothing when Solr search replacement is switched off, because
filters that narrow a Solr result set cannot narrow a WordPress one, and a panel
of controls that changed the URL and nothing else would be a lie.

### No JavaScript, deliberately

The panel is a form of checkboxes with a submit button. Every filtered view is
therefore a real URL:

```
/?s=laptop&se_f[category][]=1/12/34&se_f[post_type][]=post
```

That URL can be bookmarked, shared, linked to, crawled, and reached with the
browser's back button, and it works in a page cache and without scripting. An
interactive version can be added later without changing these URLs, because the
URLs are the API.

Changing a filter drops the page number. A visitor left on page four of a result
set that now has two pages sees nothing, and reads that as a broken filter.

## What ships registered

| Key | Kind | Notes |
| --- | --- | --- |
| `post_type` | terms | Indexed post types |
| Each public flat taxonomy | terms | Values are term identifiers |
| Each public hierarchical taxonomy | hierarchy | Values are materialised paths |

## Hierarchical taxonomies

A category tree is browsed one level at a time. The panel shows the top level;
selecting a category shows its children next, with counts that include
everything below each child.

This works because every document carries the ancestor paths of its terms:

```
Guides (12) > Beginner (34) > Deep (56)

se_ss_taxpath_category: [ "0/12", "1/12/34", "2/12/34/56" ]
```

The leading number is the depth. Faceting with a prefix of `1/12/` returns
exactly the children of 12, so a tree of ten thousand categories costs the same
as a tree of ten: only the level being looked at is ever counted.

Two consequences worth knowing:

- **Filtering by a category includes everything below it.** "In Guides" means
  "in Guides or anything filed under Guides", which is what a visitor means by
  it, and it is what makes the count agree with the result page.
- **The path format changed in 0.6.0** and documents indexed by an earlier
  version carry the old form. Run a full reindex once — from the dashboard or
  `wp solrengine index full` — or hierarchical facets will find nothing.

## Multi-select

Ticking "Guides" does not make every other category read zero. Each facet's own
filter is tagged and excluded from that facet's own domain, so the alternatives
stay countable and a visitor can always widen a search they have narrowed.

Selecting two values in one facet means "either": two ticked categories return
documents in one or the other.

A facet can opt out, if a single choice is what it means:

```php
add_filter( 'solrengine_facet_definitions', function ( $registry ) {
	$registry->register(
		FacetDefinition::terms( 'format', 'se_s_meta_format', 'Format' )
			->with_multi_select( false )
	);

	return $registry;
} );
```

## Registering your own

```php
use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FieldType;

add_filter( 'solrengine_facet_definitions', function ( $registry ) {
	$registry->register(
		FacetDefinition::terms( 'brand', 'se_ss_meta_brand', 'Brand', FieldType::STRING )
			->with_limit( 30 )
			->with_sort( 'index' )
	);

	$registry->register(
		FacetDefinition::range( 'price', 'se_f_meta_price', 'Price', 0.0, 2000.0, 250.0 )
	);

	return $registry;
} );

add_action( 'solrengine_register_filters', function ( $filters ) {
	$filters->register( 'brand', 'se_ss_meta_brand', 'ss', 'Brand' );
	$filters->register_bands( 'price', 'se_f_meta_price', 250.0, 'Price' );
} );
```

Both halves are needed, and they must share a key: the facet counts, the filter
narrows, and the shared key is what pairs them so multi-select can exclude the
right filter from the right facet.

The field must be one SolrEngine owns — it starts with `se_` — and the meta key
must already be on the indexing allowlist. A definition that could not produce a
sane Solr request is dropped at registration rather than at query time, so a
mistake shows up as a missing facet on the next page load instead of as an empty
response nobody can account for.

### Range facets

A range facet's buckets are keyed by their lower bound, and `register_bands()`
turns the value a visitor ticked back into the band it came from, using the same
gap the facet was declared with. The upper bound is exclusive, so a value of
exactly 250 falls in one band rather than two and the counts still add up.

A client never sends bounds of its own. That is what stops a caller asking Solr
for an arbitrary range over an arbitrary field.

## The REST API

```
GET /wp-json/solrengine/v1/facets
```

| Parameter | Type | Notes |
| --- | --- | --- |
| `q` | string | Search text |
| `post_type` | array | Restricted to indexed types |
| `filters` | object | Registered keys only; unknown keys are rejected |
| `facets` | array | Registered keys only, up to ten. Empty means all |
| `facet_parent` | object | Where to sit in a tree, per facet: a term id or a path |

```json
{
  "facets": {
    "category": {
      "label": "Categories",
      "type": "hierarchy",
      "parent": "0/12",
      "selected": [ "0/12" ],
      "buckets": [
        { "key": "1/12/34", "label": "Beginner", "count": 2, "selected": false, "has_children": true }
      ]
    }
  },
  "total": 3,
  "took_ms": 4
}
```

`/search` accepts `facets` and `facet_parent` too, and adds the same `facets`
object to its response. The difference is that `/facets` asks Solr for no
documents at all — which is most of the cost of the request a filter panel
repeats as a visitor works through it.

For hierarchical facets a filter value may be a term identifier or a path.
Sending `filters[category][]=34` works and means "34 or anything below it"; the
path is resolved on the server from the term itself. Clients never have to
construct one.

## Counts and privacy

A count is a disclosure. Facet counts are computed after the site scope and the
public-visibility filter are applied, so a count can never tell an anonymous
visitor how much private content exists, or what another site in a shared
collection holds. The backend test suite asserts both against a real Solr.

## Performance notes

- Each facet is work Solr does over the whole result set. A request may name at
  most ten, and each facet's bucket limit is capped.
- A filter panel costs one extra Solr request on the pages that show one, and
  nothing on the pages that do not. Two panels on one page still cost one
  request.
- Facet fields carry `docValues`, which the dynamic field naming conventions
  enforce; faceting without them is slow and memory-hungry.
- Exact counts across many shards need `refine`, which costs a second round of
  requests. It is off by default — a single-shard collection is exact anyway —
  and switched on per facet with `->with_refine()`.

## Developer hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `solrengine_facet_definitions` | filter | Register, change or remove facets |
| `solrengine_register_filters` | action | Register the filter that pairs with a facet |
