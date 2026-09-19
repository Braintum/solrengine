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

Each allowlisted key needs a declared type, which decides the index field:

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
