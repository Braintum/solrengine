# Indexing

## How content reaches the index

```
WordPress event  →  dirty set  →  queued job  →  document built  →  Solr
   (many hooks)     (one entry)    (one per      (fields mapped)
                                    25 objects)
```

Nothing in that chain runs while a post is being saved. A save marks an object
dirty, which costs an array write, and the request ends. Everything else happens
in a background job.

## Why saving stays fast

A single `wp_update_post()` fires `save_post`, `wp_after_insert_post`, several
meta hooks and `set_object_terms` once per taxonomy. Listening to each of those
and queueing work would produce six or more jobs for one change, and a bulk edit
of five hundred posts would produce thousands.

Instead every listener does the same trivial thing: add the object to a
request-scoped set, deduplicated. One handler on `shutdown` turns that set into
one job per changed object.

Deletions win over updates in that set. Trashing a post fires both a save-ish
hook and the trash hook, and indexing it would resurrect a document that should
be gone.

## What gets indexed

| Group | Fields |
| --- | --- |
| Identity | site, object type, object ID, post type, status, visibility |
| Text | title, excerpt, content |
| Display | URL, slug, author, dates, menu order, comment count, featured image |
| Taxonomy | term names, term IDs, and ancestor paths for hierarchical taxonomies |
| Meta | only keys you allowlist, with the type you declare |

Ancestor paths carry their depth — a term at 12 > 34 is indexed as `0/12` and
`1/12/34` — which is what lets a facet return one level of a tree in one
request. **The depth prefix was added in 0.6.0**, so documents indexed by an
earlier version need one full reindex before hierarchical filters find them.

Content is stripped of blocks, shortcodes and tags before indexing. Shortcodes
are removed rather than executed: running `the_content` inside a background job
would execute arbitrary plugin code once per post, slowly and unpredictably.

Products are indexed too, as soon as WooCommerce is active, with everything a
shop filters by. See [woocommerce.md](woocommerce.md).

## What does not get indexed

Drafts, pending posts, auto-drafts, trashed posts and any post whose status is
not registered as public. Scheduled posts are indexed when they publish, through
the status transition.

Password-protected posts are indexed as metadata only. Their content is the
thing the password protects.

Post meta is never indexed unless you add the key to the allowlist. Meta
routinely holds internal notes, API keys and personal data.

## Custom fields

**SolrEngine → Content** lists the custom fields your site actually uses, most
used first, so you can pick one rather than remembering what your events plugin
calls its date. Private keys — the ones beginning with `_` — are hidden until
you ask for them, because a field holding a licence key looks exactly like a
field holding a price.

Each allowlisted key needs a declared type, which decides the index field. The
screen shows the field name each choice produces, which is the name you will
need if you later write a filter or a facet against it:

| Type | Field | Good for |
| --- | --- | --- |
| `f` / `d` | `se_f_meta_price` | Prices, ratings, ranges |
| `i` / `l` | `se_i_meta_stock` | Counts, IDs |
| `b` | `se_b_meta_featured` | Flags |
| `dt` | `se_dt_meta_event` | Dates |
| `s` / `ss` | `se_s_meta_sku` | Exact match, faceting |
| `txt` | `se_txt_meta_notes` | Searchable prose |

Declaring the type is the one decision you have to make, and it is what Solr's
schemaless mode gets wrong by guessing from the first value it sees. A key that
usually holds a number and once holds `N/A` would break indexing permanently
under schemaless. Here the bad value is dropped and everything else carries on.

Two keys that would produce the same field name are refused when you add the
second, rather than silently overwriting each other.

Changing this list — or the indexed post types — changes what a document *should*
contain and changes nothing about the documents already in the index. SolrEngine
records that a reindex is outstanding and says so on screen, rather than letting
the setting look as though it took effect.

## Full synchronisation

```bash
wp solrengine index
wp solrengine index --post-type=post,page
wp solrengine index progress
wp solrengine index resume
wp solrengine index cancel
```

Resumable by design. Progress is a stored cursor, so an interrupted run
continues from the last ID it finished rather than starting over.

The walk is ordered by ID rather than paginated by offset. Offset pagination
degrades as the offset grows and, worse, silently skips rows when content
changes mid-run: delete a post early in the list and everything after it shifts
back one position.

Batch size adapts to how long each batch took and how much memory it used. A
fixed size is wrong on a 256 MB shared host and wrong again on a 4 GB machine.

## Building a replacement index

A full reindex writes over the index that is answering searches. Results are
wrong for as long as it runs — hours, on a large catalogue — and there is no way
back if the run turns out to have been a mistake.

The alternative builds a second index while the first keeps serving:

```
build into a second index  →  check it against WordPress  →  switch  →  (later) delete the old one
```

Start it from **SolrEngine → Tools**, or:

```bash
wp solrengine rebuild start
wp solrengine rebuild start --target=wordpress_v2
wp solrengine rebuild verify
wp solrengine rebuild switch
wp solrengine rebuild discard
wp solrengine rebuild drop-previous
```

**How the switch happens depends on your Solr**, and SolrEngine reports which
before it starts:

- **SolrCloud, where your collection name is an alias.** SolrEngine creates a
  collection using the same configset as the live one, indexes into it, then
  repoints the alias. The switch is atomic on the server: no request sees a
  half-switched index.
- **Everything else.** SolrEngine switches the collection it is configured with.
  One request may be answered by the old index while the next is answered by the
  new one — a far shorter window than reindexing in place, and reversible from
  WordPress.

Solr refuses to create an alias whose name is already a real collection, so a
site that has never set an alias up gets the second strategy. If you want the
first, create an alias on your server pointing at your current collection and
name the alias in SolrEngine's connection settings.

On a Solr that is not in SolrCloud mode, SolrEngine cannot create a core for you
— that means writing a configuration directory on the server's filesystem, which
is not something a WordPress plugin should reach through HTTP to do. Create an
empty core with the same configuration as your current one and name it when you
start.

**Nothing switches until the new index has been checked**, by comparing its
document count against what WordPress says should be there. A count that does
not match stops the switch, which is the entire point of building alongside. If
the count is short immediately after a run, wait a moment and check again: Solr
makes writes visible on its own commit schedule.

**The previous index is never deleted for you**, on any timer. It is the way back
if something turns out to be wrong two days later. Delete it yourself when you
are sure, from the Tools screen or with `wp solrengine rebuild drop-previous`.

## History

The last fifty runs are kept in `{prefix}solrengine_index_runs`, and the objects
that failed inside them in `{prefix}solrengine_failures`. Both are pruned
automatically as new runs are recorded.

```bash
wp solrengine history
wp solrengine history --failures
```

The Tools screen shows the same thing, with a link to each post that failed.

At most a hundred failures are recorded per run — a Solr rejecting every document
would otherwise write one row per post on the site — but the failure *count* on
the run is the real total.

Custom tables rather than options, deliberately. A log is exactly the shape that
grows without bound, and an autoloaded option that grows without bound is one of
the most common causes of WordPress performance collapse.

## The queue

Action Scheduler is used when it is available, which is the case on every
WooCommerce site and whenever SolrEngine's bundled copy wins the version
negotiation. Otherwise WP-Cron.

The WP-Cron fallback is weaker and the dashboard says so. It has no claim
isolation, so two overlapping requests can run the same job twice, and it only
runs when someone visits the site. Indexing is idempotent — every write is an
upsert keyed on a stable document ID — so a duplicated run costs time rather
than correctness.

## When something fails

A batch Solr rejects is retried one document at a time, so one malformed
document does not discard the ninety-nine around it. The offenders are recorded
individually.

A reference whose post has since been deleted or unpublished is removed from the
index rather than skipped. That makes the queue self-correcting: a job created
while a post was public, and run after it was unpublished, does the right thing.

Set `SOLRENGINE_DEBUG` in `wp-config.php` to see why individual documents
failed.

## Developer hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `solrengine_indexable_post_types` | filter | Which post types are eligible |
| `solrengine_should_index_object` | filter | Veto one object, with a reason |
| `solrengine_document` | filter | The built document, before queueing |
| `solrengine_indexed` | action | After a batch is written |
| `solrengine_index_failed` | action | After one document fails |
| `solrengine_full_sync_complete` | action | After a full run finishes |

Field names added through `solrengine_document` must follow the `se_<type>_`
convention. Anything else has no dynamic field to land in and Solr will reject
the document.

## Synchronous mode

`SOLRENGINE_SYNC_INDEXING` makes indexing happen inline. It exists for tests and
local debugging and is deliberately absent from the settings screen: it puts a
network call with a multi-second timeout inside `wp_insert_post()`, so a slow
Solr makes every editorial save slow and an unreachable one makes saves hang.
