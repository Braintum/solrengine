=== SolrEngine ===
Contributors: solrengine
Tags: search, solr, relevance, faceted search, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.0
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

= Status =

SolrEngine now does the whole job: connect, index, and answer searches. It is
still a development release and has not been through a full security review, so
treat a production site with care.

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

Not yet. WooCommerce product indexing and filtering are planned.

= Does it work on multisite? =

It can be used per site. Network-level configuration is planned.

== Changelog ==

= 0.6.0 =
* Faceted search. Visitors can narrow results by content type, category, tag or
  anything else you register, with a count beside each choice.
* A "Search filters" block, and a matching [solrengine_filters] shortcode for
  classic themes and page builders.
* Filters live in the URL, so a filtered search can be bookmarked, shared and
  reached with the back button. The panel needs no JavaScript.
* Ticking one value still shows the counts for the others, so a visitor can
  always widen a search they have narrowed.
* Category filters cover everything below the category, and the count beside a
  category matches the number of results selecting it produces.
* Hierarchical categories are browsed one level at a time, so a large tree costs
  no more than a small one.
* A new REST endpoint at /wp-json/solrengine/v1/facets returns counts without
  documents, and /search now accepts a facets parameter.
* Fixed: the relevance profile configured for a site was ignored, and filters
  for taxonomies were accepted by the REST endpoint and then silently dropped
  before reaching Solr.

= 0.5.0 =
* Instant search. Suggestions appear as visitors type, attached to the search
  field your theme already renders rather than replacing it.
* Suggestions are results, not word completions: picking one goes straight to
  the page instead of asking the visitor to press enter again.
* Fully keyboard operable and announced to screen readers, using the standard
  combobox pattern.
* Suggestions respect the same privacy rules as search. A private or
  another site's title can never appear in the list.
* A new REST endpoint at /wp-json/solrengine/v1/suggest.
* Search replacement and instant search can now be switched on from the
  settings screen, and work independently of each other.

= 0.4.0 =
* WordPress search is now answered by Solr, with relevance ranking, field
  weighting and phrase matching.
* Your theme needs no changes. Results are substituted into WordPress's own
  query, so classic themes, block themes and page builders keep working.
* If Solr is unreachable or slow, WordPress answers the search itself. Visitors
  see ordinary results, not an error.
* After a few failures in a row SolrEngine stops trying for a minute, so an
  outage does not make every visitor wait for a timeout.
* A search that finds nothing is retried with typo tolerance, and then with
  looser matching, before reporting no results.
* REST endpoints: a public search endpoint and an administrator status
  endpoint. The search endpoint accepts only named filters and sorts, never raw
  query syntax.
* An admin dashboard reporting connection, index, queue and accuracy, including
  states that would otherwise fail silently.
* Index accuracy checking, which compares what WordPress holds against what the
  index holds and reports drift.
* Results can be cached for anonymous visitors.

= 0.3.0 =
* Index posts, pages and custom post types, with taxonomy terms, hierarchy,
  authors, dates and featured images.
* Index custom fields you choose. Nothing is indexed unless you list the key
  and say what type it holds.
* Content changes reach the index automatically: creating, editing, trashing,
  restoring, deleting, and publishing a scheduled post.
* Indexing never slows down saving. Everything happens in a background job, so
  a slow or unreachable Solr cannot make the editor wait.
* Many changes to one post become one indexing job, so a bulk edit does not
  flood the queue.
* Full synchronisation is resumable. Interrupt it, restart the server, or hit a
  timeout, and it continues from where it stopped rather than starting again.
* Batch sizes adapt to how much time and memory each batch actually used.
* A document Solr rejects no longer takes the rest of its batch with it.
* Uses Action Scheduler when available, WP-Cron otherwise.
* WP-CLI: `wp solrengine index`, with `resume`, `cancel` and `progress`.

= 0.2.0 =
* Connect to Apache Solr, with a diagnosis that reports each check separately
  so every failure points at its own remedy.
* Detects the Solr version and whether you are running SolrCloud or a single
  node, and adapts rather than assuming.
* Schema management: see exactly which fields SolrEngine needs, and add them
  with one confirmation. Only ever adds fields, only ever ones prefixed "se_",
  and never replaces or deletes anything.
* A shipped Solr configset for new collections, and a copy-and-paste export for
  servers SolrEngine cannot modify.
* HTTP Basic authentication, with credentials preferably set in wp-config.php.
* Address validation that blocks cloud metadata services and link-local
  addresses outright, and requires explicit confirmation before connecting to
  anything on a private network.
* WP-CLI: `wp solrengine test`, `wp solrengine status`, `wp solrengine schema`.

= 0.1.0 =
* Initial development release.
* Plugin foundation: bootstrap with PHP and WordPress requirement checks that
  show a notice instead of causing a fatal error.
* Backend abstraction contracts, service container, configuration and encrypted
  credential storage.
* Coding standards, static analysis and test tooling.

== Upgrade Notice ==

= 0.6.0 =
Adds faceted search. Category filters need a reindex: run a full index from the
SolrEngine dashboard, or "wp solrengine index full", before using them.

= 0.5.0 =
Adds instant search. Switch it on from the SolrEngine settings screen.
