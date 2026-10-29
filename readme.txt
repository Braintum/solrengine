=== SolrEngine ===
Contributors: braintum
Tags: search, solr, relevance, faceted search, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Apache Solr powered search for WordPress. Faster, more relevant results, with WordPress search as an automatic fallback.

== Description ==

SolrEngine replaces the built-in WordPress search with Apache Solr, so visitors
get results that are fast and actually relevant, on sites where the default
search has stopped coping.

It connects to a Solr server that you run and control. Your content is indexed
into that server, and search queries are answered from it.

**SolrEngine never becomes a single point of failure.** If your Solr server is
unreachable, slow, or misconfigured, WordPress quietly falls back to its own
search and the site keeps working. Nothing white-screens, and no visitor sees an
error.

= What it does =

Connect to your Solr server, index your content in the background, answer
searches, filter a catalogue with facets, suggest results as visitors type, and
administer all of it from the dashboard or WP-CLI, without touching the
filesystem.

= Requirements =

* PHP 7.4 or newer
* WordPress 6.5 or newer
* An Apache Solr server you can reach from your WordPress host, version 9.10 or
  newer. Solr 10 is recommended.

Most shared hosting does not provide Solr. You will usually need a VPS, a
container, or a managed Solr service.

== External services ==

SolrEngine contacts **one** external service, and only the one you configure.

**Your Apache Solr server**

* **What it is:** the Solr server whose address you enter on the plugin's
  connection screen. SolrEngine does not choose, suggest or default to any
  particular server, and it contacts nothing until you enter an address.
* **What is sent:** the content you choose to index (such as post titles, post
  content, excerpts, URLs, publication dates, taxonomy terms, and any post meta
  fields you explicitly add to the allowlist), and the search terms and filters
  used when someone searches your site.
* **When:** when you run an index, when indexed content changes, and when a
  search is performed.
* **Who runs it:** you do, or whoever you obtained it from. It is not operated
  by SolrEngine.
* **Terms and privacy:** because this server is yours, its terms and privacy
  policy are yours. Apache Solr itself is documented at
  https://solr.apache.org/ and licensed under the Apache License 2.0.

SolrEngine sends **no** analytics, telemetry, usage statistics, licence checks
or error reports anywhere. It does not contact solrengine.com or any other
server operated by us.

== Bundled libraries ==

SolrEngine bundles Action Scheduler (https://actionscheduler.org/), which runs
indexing in the background. It is licensed GPL-3.0-or-later. SolrEngine's own
code is GPLv2 or later, and because the two are distributed together the
combined package is effectively GPLv3. Both licences are GPL-compatible.

By default SolrEngine indexes only content that is already publicly visible.
Drafts, private posts, trashed content, and the contents of password-protected
posts are not indexed. Post meta is never indexed unless you add the specific
key to an allowlist.

== Source code ==

SolrEngine is developed in the open at https://github.com/Braintum/solrengine,
including the uncompiled JavaScript and CSS in `assets/src` that the files in
`assets/build` are built from. To rebuild them:

`npm ci && npm run build`

The documentation for developers — hooks, REST API, WP-CLI, architecture and
security — is in the repository's `docs` folder.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/solrengine`, or install it through
   the Plugins screen.
2. Activate it through the Plugins screen.
3. Go to SolrEngine and enter the address of your Solr server.
4. Run the connection test.
5. Choose which content to index, then run the initial index.
6. Enable search replacement once indexing has finished.

Activation contacts nothing and changes no content. Search replacement stays off
until you turn it on, so an empty index can never produce an empty site.

= Storing credentials securely =

If your Solr server requires a username and password, the most secure place for
them is `wp-config.php`:

`define( 'SOLRENGINE_SOLR_USER', 'your-username' );`
`define( 'SOLRENGINE_SOLR_PASSWORD', 'your-password' );`

SolrEngine detects these automatically. Credentials stored this way are never
written to the database.

If you enter credentials in the admin screen instead, they are encrypted before
being stored. That protects them if your database is exposed, but not if the
server itself is compromised, because the encryption key lives in
`wp-config.php` alongside your code.

== Frequently Asked Questions ==

= Do I need my own Solr server? =

Yes. SolrEngine connects to a Solr server that you provide. It does not include
one, and it does not send your content to us.

= What happens if Solr goes down? =

WordPress falls back to its own search automatically. Visitors still get
results, just the ordinary WordPress ones. Your site does not break.

= Will this work with my theme? =

It should. SolrEngine replaces the search results inside WordPress's own query
rather than taking over your templates, so classic themes, block themes and page
builders keep rendering search exactly as they do now.

= Does it index private or draft content? =

No, not by default. Only publicly visible content is indexed. Private content
can be indexed if you deliberately turn that on.

= Does it index my custom fields? =

Only the ones you list. Post meta is never indexed automatically, because meta
frequently holds internal notes, API keys and personal data that has no business
in a search index.

= What happens if a search finds nothing? =

SolrEngine retries with typo tolerance, then with looser matching, before
reporting no results. The response says which of those produced the results, so
a theme can tell visitors when it is showing approximate matches.

= Does autocomplete work with my theme's search box? =

It attaches to the search field your theme already renders, so in most cases
yes, with no changes. Themes with unusual markup can point SolrEngine at their
own selector with a filter.

= What is the search popup? =

One of two ways instant search can appear, chosen under SolrEngine → Settings.
Clicking your theme's search field, typing into it or pressing its search button
opens a popup, as wide as your site, that shows results as visitors type, with
pictures, product prices and a tab for each kind of content — posts, pages,
products — with how many matches each has. The All tab shows a row of results for
each kind, and each kind's own tab keeps loading more as the visitor scrolls.
Pressing Enter, or "View all results", goes to your theme's normal results page.
The other choice is a plain list of matching titles under the search field.

The popup does not open just because someone tabs onto the field with the
keyboard. It can be used entirely from the keyboard — Tab and the arrow keys
both reach every result — and each result is a link named by its title, so it
works with screen readers. It is checked against WCAG 2.2 AA.

= Can I change how the search popup looks? =

Yes. Its colours, corner radius and width are CSS custom properties your theme
can set. To change the markup, copy a file from the plugin's `templates` folder
into a `solrengine` folder in your theme and edit the copy — the same way
WooCommerce templates are overridden. A child theme's copy wins over its parent's.

Product prices in the popup are formatted in your store's currency format from
the price saved when the product was last indexed. They can differ from the
product page for customers in another tax region or with special pricing. If that
matters for your store, remove the price from your copy of
`search-modal/result-product.php`.

= Does the search popup show "Powered by SolrEngine"? =

No, not unless you turn it on with the `solrengine_search_modal_args` filter.

= Autocomplete feels slow. What should I check? =

Autocomplete and the search popup answer through the WordPress REST API, and a
REST request loads every active plugin before the search is reached. On a
WooCommerce site that measured around 830ms of other plugins booting for 67ms of
actual searching. Two things outside this plugin matter more than anything
inside it.

**PHP OPcache should be enabled.** Without it PHP recompiles every file on every
request. On a WooCommerce site that is over 20MB of code per request and can add
seconds to every page, search or not. Most managed hosts enable it; local
development setups often do not.

**Your database should be on the same machine as your web server**, or as close
to it as you can manage. Every request makes several small database queries, and
a database across a network — especially over wireless — adds its round trip to
each one.

A persistent object cache such as Redis helps on top of both, because visitors
often type the same few prefixes.

= Can I use the search API from my own front end? =

Yes. There is a REST endpoint at `/wp-json/solrengine/v1/search`. It accepts
search text, pagination, a sort key from a fixed list, and named filters. It
does not accept raw query syntax, deliberately.

= How do I add filters to my search results page? =

Add the "Search filters" block to your search results template, or put the
`[solrengine_filters]` shortcode in a classic theme's search template or widget
area. Both show the same panel. By default it offers every filter your site
registers; you can name the ones you want instead.

= Why do my category filters show nothing? =

Category filters read a field that changed shape in 0.6.0. Run a full reindex
once and they will appear.

= Does it support WooCommerce? =

Yes. Products are indexed as soon as WooCommerce is active, with their SKUs,
prices, stock status, attributes, categories and brands. The shop page, product
archives, product search and the Product Collection block can be answered by
Solr once you switch that on from the settings screen.

Prices are indexed as base prices and used for filtering and sorting; the price
a customer sees is still rendered by WooCommerce.

= What happens to a shop filter SolrEngine does not understand? =

The query goes back to WooCommerce and the database answers it. That is slower
and correct. A shop page that looks filtered and is not costs money.

= Does it work on multisite? =

Yes. A network administrator sets the Solr connection once under Network Admin →
Settings → SolrEngine, can lock any of it so sites cannot change it, and switches
each site on individually. Each site can have its own collection, or the network
can share one; on a shared collection every query is filtered to the site asking.

= Can I reindex without search going wrong while it runs? =

Yes. From SolrEngine → Tools you can build a replacement index alongside the one
that is answering searches, check it against what WordPress holds, and switch
over only then. Your previous index is kept afterwards, and SolrEngine never
deletes it for you — it is the way back if something turns out to be wrong.

= Can I move to a different search backend without breaking search? =

Yes. SolrEngine indexes into the new backend while the old one keeps answering
visitors, and moves searches over only once the new index is full. Run
`wp solrengine backends start --to=<backend>`, then a full index, then
`wp solrengine backends finish`.

= What does SolrEngine send, and where? =

To your Solr server: your indexed content, and what visitors search for. Nowhere
else — no analytics, no telemetry, no licence checks. Search queries are not
logged, not even in the debug log. The full list, including what is never
indexed, is in the privacy document:
https://github.com/Braintum/solrengine/blob/master/docs/privacy.md

= How do I report a security issue? =

Privately, please, through GitHub's private vulnerability reporting:
https://github.com/Braintum/solrengine/security/advisories/new. Not in the
support forum or a public issue.

= Something is missing from search. How do I find out why? =

SolrEngine → Tools keeps the last fifty indexing runs and the posts that failed
inside each one, with a link to each. `wp solrengine history --failures` shows
the same list.

== Changelog ==

= 1.0.0 =
* First release in the WordPress.org plugin directory.
* New: a search popup. Clicking the theme's search field or button opens a panel
  that shows results as visitors type, with thumbnails, product prices, the
  matched words highlighted, and a tab for each content type with its count.
  Choose it, or the suggestion list, under Settings → Instant search.
* The popup's markup is three templates a theme can override by copying them
  into a `solrengine` folder, and its colours and sizes are CSS custom
  properties. New REST route `/solrengine/v1/instant` answers it.
* Search matches the word being typed as a prefix: "hoo" finds "Hoodie", in the
  popup, on the results page, in the filter counts and in product search alike.
* New hooks: `solrengine_template`, `solrengine_search_modal_args`,
  `solrengine_search_modal_triggers` and `solrengine_search_modal_width`.
* Privacy: the debug log records which Solr handler and parameters a request
  used, never their values, so search text no longer reaches it.
* Fixed: the bundled Action Scheduler is now used on sites where no other plugin
  provides it. Before, background indexing quietly ran on WP-Cron there.
* Fixed: uninstalling now also removes the circuit breaker's state, the price
  filter's cache, and indexing jobs still queued in WP-Cron or Action Scheduler.
  Deactivating stops queued jobs and marks an unfinished full index as stalled,
  so it can be resumed.
* Autocomplete and the popup answer through the REST API only. The `instant.php`
  fast path from development builds, and its `solrengine_instant_fast_path`
  filter, are removed.

The history of the development releases before 1.0.0 is in changelog.txt.

== Upgrade Notice ==

= 1.0.0 =
First WordPress.org release. Coming from a development build: autocomplete and
the search popup now use the REST API, and the solrengine_instant_fast_path
filter no longer exists.
