# WooCommerce

Products are indexed as soon as WooCommerce is present. Answering the shop's
own queries from Solr is a separate switch, on the SolrEngine settings screen,
and it starts off.

## What is indexed

One document per **product**. Variations are folded into their parent rather
than indexed themselves: a catalogue with 40,000 products and 300,000
variations produces 40,000 documents, and a shopper filtering "16 GB" and "in
stock" gets products that *have* such a variation — which is what they meant,
and what makes a facet count mean what a merchant thinks it means. See ADR-006.

| Group | Fields |
| --- | --- |
| Identity | SKU, every variation SKU, product type, variation count |
| Price | active, regular, sale, minimum and maximum across variations, on-sale flag, sale dates |
| Stock | stock status, an availability bucket, whether backorders are allowed |
| Attributes | every global attribute (`pa_*`) as term identifiers, and all attribute values as searchable text |
| Taxonomy | categories with hierarchy, tags, brands — through the ordinary taxonomy indexing |
| Signals | average rating, review count, total sales, featured flag |
| Visibility | the contexts a product is kept out of |

Custom per-product attributes — the ones typed into a product rather than
chosen from a global list — are indexed as searchable text but cannot be
faceted or filtered on. They have no term to count and no stable identifier to
filter by.

### Everything is read through WooCommerce

Never from post meta. Reading `_price` directly is faster today and empty after
the next storage migration, which is not hypothetical: it is what
High-Performance Order Storage already did to code that read order meta.

## Prices are base prices

**A WooCommerce price is not a property of a product.** What a customer is shown
depends on the store's tax display setting, their country, their customer role
and any coupon they hold. None of that exists at index time.

So the indexed prices are base prices, used for **filtering and sorting only**.
The price on the page is always rendered by WooCommerce itself. Where a store
displays tax-inclusive prices, a second field holds the price with the store's
base tax rate applied.

**One exception: the [search popup](search-modal.md#prices).** It shows indexed
prices, formatted in the browser in the store's currency format, because the fast
path that answers it loads no WooCommerce. Where the shop displays prices with
tax, the amount is scaled by the ratio recorded at index time. A store that
cannot accept the approximation removes the price element from its copy of
`templates/search-modal/result-product.php`.

The practical limit: price filter boundaries can differ from displayed prices
for customers in another tax jurisdiction. Multi-currency is out of scope, and
`solrengine_document` is where a multi-currency plugin would add its own fields.

## Stock is a bucket, not a number

Availability is indexed as `in`, `low`, `backorder` or `out` rather than as a
quantity. Exact stock is commercially sensitive — a public facet counting
"3 left" publishes inventory to a competitor — and it is the highest-churn
field in a catalogue, since every order rewrites it. Indexing it means
reindexing a product on every purchase, on exactly the stores least able to
absorb that.

Merchants who need the number can switch it on:

```php
add_filter( 'option_solrengine_index_config', function ( $config ) {
	$config['stock_quantity'] = true;

	return $config;
} );
```

## Visibility

Four WooCommerce states map onto two places a product can appear:

| Catalogue visibility | Shop and archives | Search |
| --- | :---: | :---: |
| Visible | ● | ● |
| Catalog | ● | |
| Search | | ● |
| Hidden | | |

A hidden product is **not indexed at all** — a stronger guarantee than
filtering it out, because no future query can forget it. The other two states
are stored as an exclusion and applied on every path that reads the index:
the shop, site search, the REST endpoints and autocomplete alike. A product
hidden from search stays out of the dropdown under the search box.

If the store hides out-of-stock products, that rule is applied the same way.

Both rules are expressed as exclusions rather than as permissions, which is why
they narrow products without touching the posts and pages in the same index.

## Which queries Solr answers

Three entry points: the classic shop and product archives, the Product
Collection block, and ordinary site search.

**A query SolrEngine cannot express exactly is handed back to WooCommerce.**
That includes a rating filter, a hand-picked list of products, a meta query, an
ordering it does not recognise, and anything an extension added. The page is
then answered by the database — slower, and right. A shop page that looks
filtered and is not costs a merchant money.

What is understood:

| Shop feature | Answered from Solr |
| --- | --- |
| Shop page, category, tag and brand archives | ● |
| Product search | ● |
| Sorting by relevance, price, popularity, rating, date, title, menu order | ● |
| Layered navigation (`filter_pa_colour`), in both "and" and "or" modes | ● |
| Price slider (`min_price` / `max_price`) | ● |
| Stock status and on-sale filters | ● |
| Product Collection block | ● |
| Rating filter | Falls back |
| Random ordering | Falls back |
| Collections built from a product list, such as "On sale" or "Best sellers" | Falls back |

Layered navigation defaults to **and** — a shopper choosing two values of one
attribute means a product having both — because that is WooCommerce's own
default. A store that changed it with
`woocommerce_layered_nav_default_query_type` gets the same answer here.

## Facets a shop gets

Registered automatically when WooCommerce is active: `price` (as bands derived
from the catalogue's own prices), `stock_status`, `on_sale`, and one facet per
global attribute. Categories, tags and brands come from the ordinary taxonomy
facets.

Place them with the **Search filters** block or the `[solrengine_filters]`
shortcode — see [facets.md](facets.md). WooCommerce's own filter blocks are
left alone deliberately: they are built on the Interactivity API, which is
still moving, and merchants already know them.

Price bands are derived from the most expensive product in the catalogue and
rounded to numbers a person would have picked. The lookup runs at most twice a
day, after the response rather than during it, so a facet may appear one page
load later than the product that changed it.

The lookup asks WooCommerce for the catalogue's highest price. A store where
that returns nothing — no product has a price yet, or something else is
interfering with product queries — gets no price facet, and the question is
asked again a quarter of an hour later rather than half a day later. Stores
that would rather fix the bands themselves can:

```php
add_filter( 'solrengine_wc_price_bands', function () {
	return array( 'start' => 0.0, 'end' => 2000.0, 'gap' => 250.0 );
} );
```

## Keeping the index current

Beyond ordinary post saves, these events mark a product stale: product create
and update, variation save, delete and trash, stock changes from orders,
variable-product price syncing, and scheduled sales starting or ending.

Every one of them marks the **parent** product, and marking is all it does:
references are coalesced in memory and written in a queued batch at the end of
the request. A bulk price change touching a thousand products costs a thousand
array writes and one batch, not a thousand round trips.

## Limits worth knowing

- **Variation-level results are not available.** Searching returns products.
- **At most 100 variations per product are read** for their SKUs, which is a
  configuration value. The price range comes from WooCommerce's own cached
  computation and covers every variation regardless.
- **Price filtering uses a product's lowest price.** A variable product priced
  from 50 to 500 falls in the band containing 50. This keeps a band's count
  equal to the number of results clicking it produces, which matters more.
- **Multi-currency is not supported** in this version.
