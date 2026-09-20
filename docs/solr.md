# Setting up Solr

## Supported versions

| Version | Support |
| --- | --- |
| Solr 10.x | Recommended. The primary test target |
| Solr 9.10+ | Supported |
| Solr 9.0 to 9.9 | Best effort, untested, not blocked |
| Solr 8.x and older | Unsupported. Warned at connection test |

Everything below 9.10 is End of Life upstream and receives no security fixes.
SolrEngine warns rather than refusing, because refusing would strand someone
mid-upgrade with no way to see what else needs doing.

Both SolrCloud and user-managed (standalone) deployments work. The connection
diagnostic detects which you have and adapts.

## Creating a collection

SolrEngine does not create collections for you. Creating one is an operation on
your infrastructure with consequences for disk and cluster state, and doing it
as a side effect of a page load — which the 0.1.0 prototype did — is not
acceptable.

The shipped configset in [`configsets/solrengine`](../configsets/solrengine)
has ready-made instructions for SolrCloud, single node, and Docker.

## Connecting

1. Go to **SolrEngine** in wp-admin.
2. Enter the base address, ending in `/solr`. A collection name pasted along
   with it is ignored; there is a separate field.
3. Enter the collection name.
4. If Solr is on this machine or a private network, tick the network
   confirmation. This is required, and it exists because a plugin that fetches
   any address an administrator types can otherwise be used to probe internal
   infrastructure.
5. Save. The diagnosis appears below the form.

From the command line:

```bash
wp solrengine test
wp solrengine test --format=json
```

## Reading the diagnosis

Each line is independent, because each failure has a different remedy.

| Check | What a failure means |
| --- | --- |
| Address | The URL itself was rejected. The message names the rule |
| Transport security | Warning only. The connection is not encrypted |
| Server | Nothing answered. Firewall, wrong port, or Solr is not running |
| Solr version | Warning only. Below the recommended version |
| Authentication | Credentials were rejected |
| Collection | The collection does not exist, or is not responding |
| Schema | Fields are missing, or the schema cannot be changed from here |
| Faceted search | The JSON Facet API was not detected |

## Credentials

The most secure option is `wp-config.php`:

```php
define( 'SOLRENGINE_SOLR_USER', 'solr' );
define( 'SOLRENGINE_SOLR_PASSWORD', 'your-password' );
```

SolrEngine detects these and disables the admin fields. Credentials set this
way are never written to the database. See [security.md](security.md) for what
the alternative does and does not protect.

Solr's Basic Authentication sends credentials in plain text, so use TLS for any
connection that is not loopback.

## Schema

Run `wp solrengine schema check` to see what is missing, or read the Schema
section of the admin screen.

Three routes, in order of preference:

1. **Let SolrEngine apply it.** `wp solrengine schema apply`, or the admin
   screen. Only additive changes, only `se_`-prefixed fields, only after you
   confirm.
2. **Use the shipped configset** when creating the collection.
3. **Paste the export.** `wp solrengine schema export`, or copy it from the
   admin screen. This is the route for a managed Solr you cannot modify from
   here.

All three come from the same contract definition, so they cannot drift.

### Language analysers

The shipped configset also defines a `text_<language>` field type for
twenty-four languages. They are field *types* only: nothing uses them until a
multilingual site switches a language on, and a site that never does pays
nothing for them.

If you created your collection from Solr's own `_default` configset, it defines
`text_general` and `text_en` and no others, and SolrEngine's language list will
be nearly empty. Applying the shipped configset fills it in. Content in a
language with no analyser is still indexed and still searchable; only stemming is
missing. See [multilingual](multilingual.md).

### After a schema change

Solr does not update documents that are already indexed. A new field stays
empty until each document is written again. SolrEngine tells you when a change
needs a reindex.

## Widening the port allowlist

SolrEngine connects only to ports 80, 443, 8443 and 8983. If your Solr genuinely
listens elsewhere, widen it in code rather than in a setting, because widening
it widens the SSRF surface:

```php
add_filter( 'solrengine_allowed_ports', function ( $ports ) {
	$ports[] = 9983;
	return $ports;
} );
```
