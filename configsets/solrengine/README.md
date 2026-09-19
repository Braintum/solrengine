# SolrEngine configset

A ready-made Solr configuration for a collection SolrEngine will use.

Use this when you are creating a new collection. If you already have one, you
do not need it: run the connection diagnostic in wp-admin, and SolrEngine will
either offer to add its fields through the Schema API or show you exactly what
to paste into your existing schema.

## What is here

| File | Purpose |
| --- | --- |
| `conf/managed-schema.xml` | Field types and SolrEngine's fields |
| `conf/solrconfig.xml` | Request handlers, commit behaviour, caches |
| `conf/stopwords.txt` | Empty. Yours to fill |
| `conf/synonyms.txt` | Empty. Yours to fill |

## SolrCloud

```bash
solr zk upconfig -n solrengine -d ./configsets/solrengine -z localhost:9983
curl "http://localhost:8983/solr/admin/collections?action=CREATE&name=wordpress&numShards=1&collection.configName=solrengine"
```

## Single node (user-managed)

```bash
cp -r configsets/solrengine /var/solr/data/wordpress
touch /var/solr/data/wordpress/core.properties
curl "http://localhost:8983/solr/admin/cores?action=CREATE&name=wordpress&instanceDir=wordpress"
```

## Docker, for development

```bash
docker run --rm -p 8983:8983 \
  -v "$PWD/configsets/solrengine:/opt/solr/server/solr/configsets/solrengine:ro" \
  solr:10 \
  solr-precreate wordpress /opt/solr/server/solr/configsets/solrengine
```

## Keeping it current

`conf/managed-schema.xml` is generated from the plugin's schema contract, which
is also what the Schema API plan and the wp-admin export are built from. All
three therefore agree by construction. To regenerate after a contract change:

```bash
wp solrengine schema export
```

## A note on what SolrEngine will and will not change

SolrEngine only ever adds fields whose names begin with `se_`, and only after
you confirm. It never replaces or deletes a field, including its own, and it
never creates field types — an analyser is a much larger commitment than a
field, and a wrong one is not fixable by adding another field. If a field type
this configset defines is missing from your schema, the diagnostic will tell
you rather than trying to create it.
