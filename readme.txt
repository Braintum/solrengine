=== SolrEngine ===
Contributors: solrengine
Tags: search, solr, relevance, faceted search, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.0
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

SolrEngine now does the whole job: connect, index, answer searches, filter a
catalogue, and administer all of it without touching the filesystem. It is still
a development release and has not been through a full security review, so treat a
production site with care.

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

It can be used per site. Network-level configuration is planned.

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
logged. See docs/privacy.md for the full list, including what is never indexed.

= Something is missing from search. How do I find out why? =

SolrEngine → Tools keeps the last fifty indexing runs and the posts that failed
inside each one, with a link to each. `wp solrengine history --failures` shows
the same list.

== Changelog ==

= 0.10.0 =
* SolrEngine can now use more than one kind of search backend, chosen from a
  dropdown on the connection screen when more than one is available. Each
  backend keeps its own credentials, so switching back does not mean typing
  them again.
* Move between backends without search going wrong while you do: indexing is
  pointed at the new one while the old one keeps answering visitors, and
  searches only move once the new index is full. "wp solrengine backends".
* A backend that manages its own schema is now recognised as such, and the
  schema screens say there is nothing to do rather than offering changes that
  cannot be applied.
* A new in-memory backend for development, so the WordPress side can be worked
  on without a Solr running. Enabled with SOLRENGINE_MEMORY_BACKEND in
  wp-config.php, and not for production use.
* A privacy document, docs/privacy.md, covering exactly what is indexed, what
  is sent where, and what is never touched.
* Fixed: a search query's cache key was built from a hand-written list of
  properties, so a property added later could have made two different searches
  share one cached result.

= 0.9.0 =
* Multilingual sites. SolrEngine reads languages from WPML or Polylang, records
  the language of every post, and answers a search in the language the visitor
  is reading. A site with no multilingual plugin is unaffected.
* Language-aware analysis. Text can be indexed a second time through an
  analyser that knows the language, so a German search for "Häuser" finds
  "Haus" and an English one for "running" finds "run". Switch a language on
  under SolrEngine, Search.
* The shipped Solr configset now defines analysers for twenty-four languages.
* A language filter and a language facet appear on multilingual sites, so a
  visitor can widen a search to another language and a theme can build a
  language switcher over the search results.
* Multisite. A network administrator sets the Solr connection once under
  Network Admin, Settings, SolrEngine, and can lock any of it so individual
  sites cannot change it. Credentials are stored once for the whole network.
* Sites are off until switched on. Activating SolrEngine across a network does
  nothing until a network administrator enables each site, so four hundred
  sites cannot start four hundred indexes at once.
* Choose one collection per site or one shared collection for the network.
  Results never cross between sites either way.
* New command: "wp solrengine network" to see, switch on, index and migrate
  sites across a network, one at a time and with an optional pause between
  them.
* Fixed: uninstalling did not remove the run state, the rebuild state or the
  discovered custom-field list.

= 0.8.0 =
* A Content screen. Choose which post types are indexed, and map custom fields
  into the index by picking from the fields your site already uses. The index
  field name each choice produces is shown beside it.
* A Search screen. Weight titles, excerpts, content, categories, SKUs and
  product attributes against each other, adjust how many words a search must
  match, and switch on a nudge for newer content. An invalid minimum-match
  expression is refused when you save it rather than breaking every search.
* A Tools screen. See what your Solr schema is missing, apply it with one
  button, reindex, and read the history of what has been indexed.
* Build a replacement index alongside the live one and switch to it when it has
  been checked, rather than reindexing over the index that is answering
  searches. In SolrCloud the switch is an alias swap; elsewhere SolrEngine
  repoints itself at the new collection. The previous index is never deleted for
  you.
* Index history. The last fifty runs and the objects that failed inside them are
  kept in their own tables, pruned automatically, and readable from the Tools
  screen or "wp solrengine history".
* SolrEngine now notices when somebody else changes the fields it owns in your
  schema, and says so rather than letting indexing fail unexplained.
* Settings that change what a document should contain now say that content
  already indexed does not reflect them, because changing a setting never
  rewrites a document that is already there.
* New commands: "wp solrengine rebuild" and "wp solrengine history".
* Fixed: a store that lowered the weighting of product SKUs had it silently put
  back on every request, so the setting appeared to do nothing.
* Fixed: on a WooCommerce site the index-accuracy check compared against a count
  that excluded products, so it reported the index as out of step permanently.
* Fixed: a full index did not commit when it finished, so the last batch could
  stay invisible to searches for up to a minute and be counted as missing.

= 0.7.0 =
* WooCommerce. Products are indexed with their SKUs, prices, stock, attributes,
  categories, brands and ratings as soon as WooCommerce is active.
* Variations are folded into their parent product, so filtering by "16 GB" and
  "in stock" finds products that have such a variation, and counts stay per
  product rather than per variation.
* The shop page, category and brand archives, product search and the Product
  Collection block can all be answered by Solr. Switch it on from the settings
  screen.
* Sorting by price, popularity, rating, date or title, layered navigation, and
  the price slider all work as they do now.
* A query SolrEngine cannot express exactly — a rating filter, or one an
  extension added — is handed back to WooCommerce rather than answered
  approximately.
* Catalogue visibility is respected everywhere: a product hidden from search
  stays out of search results, the REST API and the suggestions dropdown. A
  fully hidden product is never indexed.
* Price, availability, on-sale and attribute facets, with price bands derived
  from the catalogue's own prices.
* Fixed: a full reindex ignored post types that an integration had added, so
  content indexed on save could disappear on the next full sync.
* Fixed: the search endpoint ignored a post type it had not been configured
  with, and answered with everything instead.

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

= 0.10.0 =
No action needed. Adds backend selection and a safe path for moving between
backends.

= 0.9.0 =
Adds multilingual and multisite support. On a multilingual site, run a full
index so every post records its language. On a network, switch each site on
under Network Admin before it will index.

= 0.8.0 =
Adds screens for indexed content, relevance and tools, and a way to rebuild the
index without taking search down while it runs. No reindex is required.

= 0.7.0 =
Adds WooCommerce support. Products are indexed automatically; run a full index
to bring an existing catalogue in.

= 0.6.0 =
Adds faceted search. Category filters need a reindex: run a full index from the
SolrEngine dashboard, or "wp solrengine index full", before using them.

= 0.5.0 =
Adds instant search. Switch it on from the SolrEngine settings screen.
