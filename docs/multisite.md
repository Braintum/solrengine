# Multisite networks

One Solr, many sites, configured once.

## Switching sites on

**Nothing happens on a site until a network administrator switches it on**, under
Network Admin → Settings → SolrEngine. A site that is off indexes nothing,
searches through WordPress exactly as it did before, and says so in a notice
rather than looking broken.

This is the one piece of friction in the whole feature and it is there on
purpose. Activating a plugin across four hundred sites must not start four
hundred full indexes against one Solr cluster. Switch sites on in batches, and
index each batch before starting the next.

**It applies only to a network activation.** A site administrator who activated
SolrEngine on their own site has already opted in by doing that, and it works
immediately — the network screen exists only for a plugin activated for the whole
network, so requiring a second consent from it would leave them with an inert
plugin and nowhere to go.

From the command line:

```bash
wp solrengine network status
wp solrengine network enable 2 3 4
wp solrengine network enable --all
wp solrengine network disable 7
```

## Network settings

The network screen sets the Solr address, the collection, the credentials and the
TLS and private-network options for every site.

Each field has a **"sites cannot change this"** checkbox beside it, and the
difference matters:

- Unticked, the value is a **default**. A site that has set nothing gets it; a
  site that sets its own keeps its own.
- Ticked, the value is a **pin**. The site's field is disabled and says where the
  value comes from, rather than accepting input that would be silently
  discarded.

A constant in `wp-config.php` still beats both. The full order, lowest to
highest, is: packaged default, network default, the site's own setting, a
network pin, a PHP constant.

Credentials are stored once for the network, encrypted with the keys in
`wp-config.php` — which a network shares, so one encrypted value is readable from
every site. A site that has entered its own keeps them.

## One collection or many

| Topology | Good for | Costs |
| --- | --- | --- |
| **One collection per site** | Isolated relevance and configuration; deleting a site is dropping a collection | Several hundred sites means several hundred collections, which strains SolrCloud cluster state |
| **One shared collection** | One schema to manage; efficient for many small sites | Sites share relevance statistics, and one site reindexing affects the others |

**Results never cross between sites either way.** Every document carries
`se_i_site` and every query filters on it — on a single-site install too, where
it is always 1. That constant, slightly redundant cost is what makes the topology
a deployment choice rather than an architectural one: moving between the two
changes a collection name and nothing else.

Under one collection per site you can give the network a naming pattern such as
`wordpress_%d`, where `%d` becomes the site ID. The site's own collection field is
then disabled and shows the derived name. Leave the pattern empty to let each
site name its own, which is what a network of a dozen unlike sites usually wants.

**SolrEngine does not create collections for a network.** Create them on your
Solr first — the same rule as for a single site.

## Indexing and migrating a network

```bash
wp solrengine network index --pause=30
wp solrengine network schema
```

Both walk the enabled sites **one at a time, in a separate process per site**.

The separate process is not caution for its own sake: WordPress caches a great
deal per site, and a single process walking four hundred sites through
`switch_to_blog()` accumulates all of it until it runs out of memory somewhere in
the three hundreds — at which point half the network is indexed and nothing says
which half.

`--pause` waits between sites. One Solr cluster serving a whole network has other
work to do, and a pause is the difference between a slow reindex and a cluster
that stops answering searches while it runs.

A site that fails is reported and the walk continues. Stopping would let one
unreachable site block every site after it.

Network-wide work is a command rather than a button because per-site collections
multiply every schema migration across the network, and a per-site button clicked
four hundred times is not an operation.

## What is not supported

Network-wide search — one search box returning results from every site — is not
built. It needs the shared collection, and it needs decisions about permissions
and result presentation that no network answers the same way. The data model
supports it: the site filter is applied by the query builder, so removing it is a
change in one place when there is a design to remove it for.
