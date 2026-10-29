# Search popup

Clicking the theme's search field, typing into it or pressing its search button
opens a popup that searches as the visitor types. Results appear as cards, with a
row of tabs — All, Posts, Pages, Products, whatever the site indexes — each
carrying its own count. Enter, or "View all results", goes to the theme's normal
results page.

Enable it under **SolrEngine → Settings → Instant search**, and choose *Search
popup*. The other choice, *Suggestion list*, is the dropdown of titles described
in [search.md](search.md#instant-search). It works whether or not search
replacement is switched on, like the suggestion list.

Every piece of markup can be overridden from a theme. See
[Overriding the markup](#overriding-the-markup).

## How it opens

SolrEngine does not add a search box. It attaches to the forms the theme already
renders, matched by `solrengine_instant_search_selector` (by default
`input[type="search"]`, `input[name="s"]` and the block search input).

| The visitor | What happens |
| --- | --- |
| Clicks or taps the field | Opens, carrying over anything already typed |
| Types or pastes into the field | Opens, carrying over the text |
| Presses Enter or ↓ in the field | Opens |
| Presses the form's search button | Opens instead of submitting |
| Clicks an element matching `solrengine_search_modal_triggers` | Opens empty |
| Tabs onto the field | **Nothing.** Focus alone never opens a dialog (WCAG 3.2.1) |

The default trigger selector is `.solrengine-search-trigger,
[data-solrengine-search], a[href="#solrengine-search"]`, so a menu item linking to
`#solrengine-search` opens the popup with no code at all.

Without JavaScript, or in a browser without `<dialog>`, nothing is attached and
the theme's form submits as it always did.

## Inside the popup

| Key | Does |
| --- | --- |
| Tab / Shift+Tab | Move through everything in the popup, every result card included, in reading order |
| ↓ in the field | Moves focus to the first result |
| ↓ / → and ↑ / ← on a result | Next and previous result. ↑ on the first goes back to the field. On a type tab, ↓ on the last loads the next page and moves to its first result |
| Home / End on a result | First and last result |
| Typing on a result | Goes on typing in the field |
| Enter in the field | The full results page for the text |
| Enter on a result | Opens it, like any link; Ctrl/⌘+Enter opens it in a new tab |
| Escape | Closes, and returns focus to where it was |
| ← / → / Home / End on the tabs | Move between tabs and select |

The tabs show every type with a match, in the order the site indexes them. A
selected tab stays visible even at zero, so a visitor is never stranded on a tab
that disappeared. Selecting a tab does not change the other tabs' counts.

**All** shows one section per type, in the same order, headed with the type's
name and count. Each section is one row of cards — as many as fit across the
popup, at most four, and two rows of two on a phone — and a "See all N" link
that selects that type's tab. A section whose matches all fit has no link.

**A type tab** shows its matches as a grid, 24 at a time. Scrolling near the end
loads the next 24, until every match is shown. The trigger is a real "Load more
results" button at the end of the grid, so a keyboard or screen-reader user, or a
browser without `IntersectionObserver`, can load the next page by pressing it; a
page that fails to load turns it into "Could not load more. Try again" rather
than retrying on its own. After each page a polite announcement says how many of
the total are shown.

Paging stops at the 1,000th result. A visitor who has scrolled that far is better
served by the results page, and Solr's deep paging grows more expensive with
every page.

## Where results come from

```
GET /wp-json/solrengine/v1/instant?q=lamp&post_type=product&page=2
```

If the route ever answers with something that is not SolrEngine's JSON — a
security plugin's block page, say — the popup shows its error state rather than
trying to read it.

Measured on the development site (WooCommerce active, OPcache warm, Solr local),
a popup request takes about 900 ms, almost all of it other plugins booting during
`rest_api_init`. See [search.md](search.md#where-the-time-goes) for why, and for
the lighter path deferred until after the first WordPress.org release.

The route is answered by one class, `Search\InstantResults`, and the route adds
nothing to it. The query is an ordinary `SearchQuery`, so the site scope, the
public-visibility filter and the relevance profile are exactly those of a results
page. The counts are the registered `post_type` facet, and the selected tab is the
registered `post_type` filter; because a facet's own filter is tagged and excluded
from its counts, narrowing to one tab leaves the others' numbers intact.

**Matching as you type.** The last word is matched as a prefix — "hoo" finds
"Hoodie", "beanie lo" finds "Beanie with Logo" — while every earlier word must
match as written. Whole words still rank as they would on the results page. See
[search.md](search.md#completing-a-search-query) for how, and for what does not
complete: one-letter words and words with punctuation. The results page completes
the same way, so pressing Enter shows what the popup showed.

**WooCommerce fields.** On a shop, SKUs and attribute values are searched as
well as titles and content.

**What All costs.** One query for the counts, which fetches no documents, then
one small query per type with a match, for that type's first few cards. On a site
indexing posts, pages and products that is at most four Solr queries per
keystroke pause, measured at about 130–260 ms in total on the development site
against 10–70 ms for a type tab's single query. A single query cannot do it: the
first few results overall are usually all one type. If any section fails, the
whole answer fails, so the popup never shows a section missing without saying
why.

### Parameters

| Parameter | Type | Notes |
| --- | --- | --- |
| `q` | string | At most 200 characters. Fewer than 2 answers an empty body without asking Solr |
| `post_type` | string | One indexed post type, or empty for all. An unknown type reads as all |
| `page` | integer | Since 0.11.0. A type tab's page, from 1. Ignored on All. Below 1 reads as 1; past the 1,000th result reads as the last page served |

That is the whole surface. No sorting or arbitrary filters: the popup has no use
for them, and every parameter is one a lighter surface could later honour
identically.

### Response

```json
{
  "query": "lamp",
  "post_type": "product",
  "total": 2,
  "all": 5,
  "counts": { "product": 2, "page": 2, "post": 1 },
  "page": 1,
  "per_page": 24,
  "has_more": false,
  "results": [
    {
      "id": 12,
      "type": "post",
      "post_type": "product",
      "title": "Form desk lamp — Olive",
      "excerpt": "…",
      "url": "https://example.com/product/form-desk-lamp/",
      "thumbnail": "https://example.com/wp-content/uploads/lamp-300x300.jpg",
      "date": "2026-09-26T10:42:42Z",
      "author": "Dana Okonjo",
      "price": { "current": 89, "regular": 110 }
    }
  ],
  "sections": [],
  "took_ms": 14,
  "strategy": "standard"
}
```

On All, `results` is empty and `sections` carries the cards instead, one entry
per type with a match, in the site's order:

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
    { "post_type": "product", "total": 2, "results": [ { "id": 12, "…": "…" } ] },
    { "post_type": "page", "total": 2, "results": [ "…" ] },
    { "post_type": "post", "total": 1, "results": [ "…" ] }
  ],
  "took_ms": 41,
  "strategy": "standard"
}
```

- `total` is the number of matches in the selected tab, `all` across every tab.
- `has_more` is whether another page follows this one, so the popup never asks
  for a page that would come back empty. Always false on All.
- `per_page` is 24 on a type tab and 4 per section on All.
- A section's `total` is that type's count, so "See all" can say how many.
- `took_ms` on All is the sum of its queries.
- `counts` lists only indexed types with at least one match, in the site's order.
  It is always an object, `{}` when empty.
- `excerpt` is shortened to about 180 characters at a word boundary.
- `price` appears only on documents that have one:
  `current` always; `regular` only when it is above `current` (something to strike
  through); `min` and `max` only when they differ (a range to show);
  `current_incl_tax` when the index has it.
- `strategy` is `fuzzy` or `loosened` when the exact search found nothing and the
  results are the closest matches; the popup says so.

Errors use the REST API's shape on both surfaces: `429` with
`solrengine_rate_limited`, `503` with `solrengine_backend_unavailable`, `400` for a
`q` that is too long. On a 503 the popup tells the visitor and offers the site's
own results page, which falls back to WordPress search — "nothing matched" and
"search is down" are different facts, and only one of them is true.

Both surfaces are rate limited under the same bucket, `instant`. See
[security.md](security.md#the-instant-search-entry-point).

## Overriding the markup

The popup's markup is three PHP templates, located the way WooCommerce's are:

```
yourtheme/solrengine/search-modal.php                    the dialog
yourtheme/solrengine/search-modal/result.php             a result card
yourtheme/solrengine/search-modal/result-{post_type}.php a card for one type
```

Copy a file from the plugin's `templates/` directory into the theme, keeping its
path under `solrengine/`, and edit the copy. A child theme wins over its parent;
both win over the plugin. The plugin ships `result-product.php`; any other type
uses `result.php` unless the theme adds its own.

**Why templates, if results are rendered in the browser?** So that the JSON
carries data and never markup, and a theme's PHP runs where the theme is
certainly loaded. Every template is rendered once per page, on a normal page load
with the theme active, into `<template>` elements; the script fills a copy for
each result. Theme overrides
are therefore real PHP — conditionals, translation functions, theme helpers — run
once per page rather than once per result.

### The dialog

The script finds each part by its `data-solrengine-*` attribute and never by
class, so classes and structure are free to change. The attributes are listed at
the top of `templates/search-modal.php`. Removing one disables the part it names;
the popup still works without, for example, a clear button or a "View all" link.

### Sections

On All, each section is built from the
`<template data-solrengine-template="section">` in `search-modal.php`. Copy it with
the dialog and change it freely, keeping these:

| Attribute | Does |
| --- | --- |
| `data-solrengine-section-header` | The section's heading. Left out on a type tab, where the tab already names the type. Its `id` is set by the script and used to label the list |
| `data-solrengine-field="label"` | The type's plural name |
| `data-solrengine-field="count"` | The type's count, formatted for the site's language |
| `data-solrengine-see-all` | The button that selects the type's tab. Hidden when every match is already shown |
| `data-solrengine-field="see_all"` | The button's text, "See all N" |
| `data-solrengine-section-results` | The list cards are added to. The script gives it `role="list"`, which Safari needs to announce a list styled without bullets |

Each section carries `data-solrengine-section="<post type>"` and
`data-solrengine-total`, for styling one type differently. Without the template
the script builds a plain heading and list, so an older copy of `search-modal.php`
keeps working; it only looks plainer.

`data-solrengine-results` is now the container the sections go in rather than the
list itself, and `data-solrengine-more` marks the "Load more results" button. The
script writes "Load more results" or "Could not load more. Try again" into the
button's `.solrengine-modal__more-label`; without that element the button keeps
the text the template gave it. Without the button, scrolling loads nothing more
and further pages are reached only with ↓ past the last card.

### Result cards

The outermost element is a list item and must be the template's only root. The
element with `data-solrengine-href` is the card's link and its only focusable
element: the script names it with the result's title (`aria-label`) and
describes it with whichever of type, price, date and author the card shows
(`aria-describedby`), so a screen reader says "Hoodie, link, Product, 42,00 € –
45,00 €" rather than every word in the card run together. Arrow keys move
between cards by their links.

Before 0.11.0 was released, cards were listbox options with `role="option"` on
the item and `tabindex="-1"` on the link. The script removes both from an older
copy, so it keeps working; take them out of your copy anyway.

Slots are attributes:

| Attribute | Fills |
| --- | --- |
| `data-solrengine-field="title"` | The title, with searched words wrapped in `<mark class="solrengine-mark">` |
| `data-solrengine-field="type"` | The post type's singular name |
| `data-solrengine-field="excerpt"` | The excerpt |
| `data-solrengine-field="date"` | The date, formatted for the site's language |
| `data-solrengine-field="author"` | The author's display name |
| `data-solrengine-field="price"` | The price, marked up as WooCommerce marks one up |
| `data-solrengine-href="url"` | Sets `href` |
| `data-solrengine-src="thumbnail"` | Sets `src`, or removes the element when there is none |
| `data-solrengine-if="<field>"` | Removes the element when that field is empty |

Values are inserted as text, never as markup, and URLs are accepted only when
they are `http` or `https`.

The bundled cards put `data-solrengine-if="thumbnail"` on the image box, so a
result without an image — most pages — is a text-only card with a longer excerpt
rather than a card with an empty picture. Keep that attribute in a copy that
should behave the same way.

For anything a template cannot express, listen for the card event:

```js
document.addEventListener( 'solrengine:result', ( event ) => {
	const { result, element } = event.detail;

	if ( result.post_type === 'event' ) {
		element.classList.add( 'is-event' );
	}
} );
```

It fires once per card, after the card is in the document, including cards that
arrive with a later page. On All a section shows only the cards that fit one row;
the rest are in the document with `hidden` set.

### Hooks

| Hook | Use |
| --- | --- |
| `solrengine_search_modal_args` | Heading, tagline, placeholder, results URL, and the opt-in "Powered by" line |
| `solrengine_template` | Supply a template from a plugin, or return `''` to suppress one |
| `solrengine_search_modal_triggers` | Elements that open the popup when clicked |
| `solrengine_search_modal_width` | The width the popup grows to, when the theme does not say or says something narrower than its layout |
| `solrengine_instant_search_selector` | The theme's search fields (shared with the suggestion list) |

```php
add_filter( 'solrengine_search_modal_args', function ( $args ) {
	$args['tagline']     = __( 'Find something good.', 'my-theme' );
	$args['show_credit'] = true; // Off by default, per WordPress.org guideline 10.
	return $args;
} );
```

Full signatures are in [hooks.md](hooks.md). `solrengine_search_query` and
`solrengine_search_results` fire for the popup's queries as for any other
search.

## Styling

Every colour, radius and size a theme is likely to change is a custom property on
`.solrengine-modal`:

```css
.solrengine-modal {
	--solrengine-modal-accent: #b4232a;
	--solrengine-modal-radius: 8px;
	--solrengine-modal-columns: 3;
}
```

The full list is at the top of `assets/src/search-modal.css`. The popup takes the
theme's font.

**Width.** The popup is as wide as the site it opens over, never narrower than
44rem and never wider than the screen. The width comes from, in order:

1. `--solrengine-modal-width`, if the theme sets it.
2. `solrengine_search_modal_width`, if something filters it.
3. The theme's `theme.json` wide size, then its content size.
4. A classic theme's `$content_width`, as the theme declared it during
   `after_setup_theme`. Some themes narrow the global later, per page, to the
   column beside a sidebar — GeneratePress does — and that narrower value is
   ignored.
5. 75rem.

Steps 2 to 4 are printed as `--solrengine-content-width` beside the stylesheet,
and only when the value is a plain length or `calc()`, `min()`, `max()`,
`clamp()` or `var()` over lengths; anything else is dropped.

```php
// A classic theme with no $content_width.
add_filter( 'solrengine_search_modal_width', function () {
	return '1140px';
} );
```

**The grid.** Cards are at least `--solrengine-modal-card-min` (15rem) wide, at
most `--solrengine-modal-columns` (4) to a row, `--solrengine-modal-gap` (1rem)
apart, and two to a row below 600px. At the default width that is four on a
desktop and three on a tablet. Its selectors carry two classes, which is enough to win over theme
rules for bare `button`, `input`, `a` and `mark` without `!important`. Below
600px wide the panel fills the screen.

## Prices

**The popup's prices are indexed base prices, formatted in the browser.** This is
a deliberate exception to the rule in [woocommerce.md](woocommerce.md) that a
displayed price is rendered by WooCommerce: it keeps the response free of markup
and keeps one result shape whatever process serves it. The store's currency symbol, position, separators and decimals are read from
WooCommerce when the page loads, so the format matches; where the shop displays
prices including tax, the amount is scaled by the tax ratio recorded at index
time.

It can still differ from the product page for a customer in another tax
jurisdiction, under role-based or multi-currency pricing, or while a scheduled
sale starts or ends before the product is reindexed. A store where that matters
removes the price element from its copy of `result-product.php`.

## Accessibility

- A native modal `<dialog>`: the page behind is inert, focus is contained, and
  focus returns to where it was on close.
- The field is a plain search field, described by a hidden hint saying how to
  reach the results. Results are lists of ordinary links, each list labelled by
  its section's heading, so Tab reaches every visible result, a screen reader's
  links list finds them, and a result opens in a new tab like any link.
- Not a combobox. A combobox's listbox is for choosing a value and its options
  may not contain links; a result is a link to a page. Arrow keys still move
  through the results, moving real focus, and ↑ from the first returns to the
  field.
- Each result's link is named by its title and described by its type and price.
  "See all" buttons name their type: "See all 20: Posts".
- More results load from a real button, so no one depends on scrolling to reach
  them. Pressing it, or ↓ past the last result, moves focus to the first new
  result, and each page is announced. The button is never `disabled` while it
  loads — disabling the focused element sends focus to the top of the page.
- The tabs follow the ARIA tabs pattern, with the results as their tab panel.
- A polite live region announces each result count, and the "no results" and
  "unavailable" messages.
- Opening is never triggered by focus alone.
- A focused result has a 3px outline as well as its hover lift, visible in
  forced-colours mode too; the selected tab stays marked there, and
  reduced-motion preferences turn the animation off.
- Text meets 4.5:1 contrast on every surface it sits on, the count pills
  included.
- Checked with axe-core (WCAG 2.2 AA and best practices) and a keyboard-only
  run: `SOLRENGINE_A11Y_URL=http://example.test/ npm run test:a11y`. See
  `tests/a11y/search-modal.js`.

## Settings

| Key | Default | Effect |
| --- | --- | --- |
| `search.instant_enabled` | `false` | Whether instant search is attached at all |
| `search.instant_mode` | `modal` | `modal` for the popup, `suggest` for the suggestion list |
| `search.instant_limit` | `8` | Suggestions in the suggestion list. The popup does not read it: it pages a type tab 24 at a time and shows one row per section on All |
