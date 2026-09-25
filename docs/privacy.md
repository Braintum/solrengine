# Privacy

What SolrEngine indexes, what leaves your server, and what it never touches.

## The short version

**SolrEngine sends your content to exactly one place: the Solr server whose
address you typed.** It is not operated by us, we cannot see it, and nothing is
sent anywhere else. There is no telemetry, no analytics, no licence check and no
phone-home.

If that server is on your own machine or in your own network, no content leaves
your infrastructure at all.

## What is indexed

| Indexed | Not indexed |
| --- | --- |
| Post titles, excerpts and content | Drafts, pending, scheduled and trashed content |
| Permalinks, slugs, publication and modification dates | Private posts, and posts with a non-public status |
| Author ID and display name | The body of a password-protected post |
| Taxonomy terms and their paths | Any custom field you have not explicitly listed |
| Custom fields you add to the allowlist | User accounts, emails, passwords, roles |
| Post type, comment count, menu order, thumbnail URL | Comments |
| The site ID and the content's language | Order data, customer records, payment information |
| On a shop: SKUs, prices, stock status, attributes, ratings | `wp-config.php`, database credentials, API keys |

**Custom fields are opt-in, one key at a time.** Nothing in post meta is indexed
until you list it under SolrEngine → Content. That is the single most important
privacy decision in the plugin: custom fields routinely hold licence keys, API
tokens, internal editorial notes and personal data, and the 0.1.0 prototype
indexed all of them. Private keys — the ones beginning with `_` — are hidden in
that list until you deliberately ask for them.

An author's display name is indexed because search results show it. An author's
email address is not, and there is no setting that would index it.

## What is sent when somebody searches

The search text, the filters selected, and the page number. These reach the same
Solr server and nowhere else.

**SolrEngine does not log search queries.** There is no table of what visitors
searched for, no click tracking, and no per-query analytics. The plan places
those in a future commercial tier specifically so that the free plugin does not
collect them by default. The run history records what was *indexed* and what
failed — post IDs and error messages — never what anybody searched for.

Search requests are cached for a few minutes, keyed by a hash of the query. The
cache holds results, not who asked.

## What is stored in your own database

| Stored | Where |
| --- | --- |
| Connection settings and preferences | Options |
| Your Solr password, encrypted | An option, or better, `wp-config.php` |
| The last fifty indexing runs and their failures | Two custom tables, pruned automatically |
| The list of custom field keys your site uses | A transient, for an hour |

Uninstalling the plugin removes all of it. It deliberately does **not** delete
your documents from Solr: that index is on your infrastructure, the server may be
unreachable at that moment, and deleting someone's data from a remote server
during an uninstall is not a decision an uninstall script should make silently.

## Multisite

Under one collection per site, a site's documents are in its own collection.
Under a shared collection, every document carries its site ID and every query
filters on it, so one site's content never appears in another's results.

Network credentials are encrypted with the keys in `wp-config.php`, which a
network shares by definition. Any site administrator in the network can therefore
cause them to be used. A network is a trust boundary; where that is not
acceptable, give each site its own credentials.

## For your privacy policy

If you need wording, this is accurate for a standard installation:

> This site uses SolrEngine to provide search. Content published on this site,
> and the words visitors type into the search box, are sent to a search server
> operated by [who operates it]. Search queries are not logged or retained by the
> plugin.

Fill in who operates the server. If it is you, say so — it is the honest answer
and it is usually the reassuring one.

## Other backends

SolrEngine can talk to more than one kind of search backend. Today it ships two:
Apache Solr, and an in-memory backend used only for development, which stores
nothing beyond a single request.

**A backend that sent your content to a service we operate would be a different
privacy proposition, and is not something this plugin does today.** If one is
ever added, these rules apply to it and are recorded here in advance so they are
not decided under pressure later:

- It is a **choice in a dropdown beside the others**, never a requirement and
  never an unlock. No feature is withheld from the self-hosted backend.
- Consent is taken **at the moment of choosing it**, on that screen, stating in
  plain words that content will leave this server — not buried in a readme.
- What is sent, where it is stored, for how long, and who the sub-processors are
  must be documented before it ships, along with a working path to delete it.
- Nothing is sent until it is selected. Installing the plugin must never cause
  content to leave the server.

Until such a backend exists, the first section of this document is the whole
truth: one server, the one you configured.
