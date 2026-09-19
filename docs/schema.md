# Schema

SolrEngine needs a small set of fields in your Solr schema. It can add them for
you, show you what to add by hand, or tell you why it will not touch anything.
This document explains which, and why the answer is never "it applied silently".

## The contract

The fields SolrEngine needs are a *contract*, with a version number of its own:

```
plugin 0.8.0  ──declares──►  schema contract v1
```

The two are deliberately independent. Most releases change nothing about the
schema, and tying the numbers together would force a meaningless migration on
every update.

Most of the contract is dynamic field patterns rather than named fields:

| Pattern | Type | Holds |
| --- | --- | --- |
| `se_txt_*`, `se_txts_*` | text | Analysed text |
| `se_s_*`, `se_ss_*` | string | Exact values, sorting, faceting |
| `se_i_*`, `se_is_*`, `se_l_*`, `se_ls_*` | int, long | Whole numbers |
| `se_f_*`, `se_d_*` | float, double | Decimals, such as prices |
| `se_b_*` | boolean | Flags |
| `se_dt_*`, `se_dts_*` | date | Timestamps |

That is the highest-leverage decision in the whole design: because the patterns
exist, allowlisting a custom field, adding a taxonomy, or installing WooCommerce
needs **no schema change at all**. Their fields land in a pattern that is already
there. Three named fields are declared explicitly — `se_all`, `se_taxterms` and
`se_suggest` — because each has a job a generic pattern would not describe.

Every name begins with `se_`. SolrEngine reads, plans against and writes nothing
outside that namespace, so it can share a collection with another application.

## The three rules

1. **Additive only.** SolrEngine emits `add-field`, `add-dynamic-field` and
   `add-copy-field`, and nothing else. It never replaces or deletes a field —
   not even one of its own. A field that already exists and is wrong is reported
   for a human to resolve, because replacing it would discard whatever you
   changed it to.
2. **Never field types.** An analyser definition is a far larger commitment than
   a field, and a wrong one is not recoverable by adding another field. A missing
   type is reported as a blocker, and the shipped configset in `configsets/` is
   the answer.
3. **Capability is not consent.** The plan is computed, shown in full, and
   applied only when you press the button. There is no code path that decides
   for itself to modify your schema.

## Checking and applying

**SolrEngine → Tools** shows the comparison, and one of four verdicts:

| Verdict | Means |
| --- | --- |
| Up to date | The schema has what is needed and is unchanged since SolrEngine last looked |
| Not yet complete | Fields are missing. The plan below lists exactly which |
| Applied elsewhere | The schema is adequate, but this site did not apply it — by hand, or before the plugin was installed |
| Changed | The fields SolrEngine owns are not the ones it last saw |

That last one is why a fingerprint is stored alongside the version. A version
number alone cannot tell "as we left it" from "somebody deleted a field last
week", and the first symptom of the second is indexing failing for reasons the
error messages do not explain.

From the command line:

```bash
wp solrengine schema          # show the comparison
wp solrengine schema apply    # apply it, with a confirmation
wp solrengine schema export   # print the XML and change nothing
```

## When SolrEngine will not apply it

Two honest cases, and both leave you a route:

- **The schema is not mutable.** Solr only accepts Schema API changes with
  `ManagedIndexSchemaFactory`, and in Solr 10 administrative access also needs
  authorisation, so credentials that can read may still not write.
- **A field type is missing.** See rule 2.

In both, the Tools screen shows the exact XML to paste into your
`managed-schema` file. It is the same set of changes SolrEngine would have made.

## After a schema change

**Solr never rewrites documents that are already indexed.** Adding a field today
does not populate it in a document written yesterday; a new `copyField` rule
stays empty until each document is written again.

SolrEngine says so rather than letting the setting look as though it took
effect. Applying a plan that needs one records an outstanding reindex, shown on
the Tools and Content screens until a full run finishes.

| Change | Additive? | Reindex? |
| --- | --- | --- |
| New dynamic field pattern | Yes | No |
| New named field | Yes | Only for documents that need it |
| New copyField rule | Yes | Yes |
| Change a field's type | **No** | Yes — build a new index |
| Change analysis of a field | **No** | Yes — build a new index |
| Remove a field | Technically | No. Leave it |

That last row is a deliberate stance. An orphaned `se_` field costs almost
nothing, and deleting a field that a `copyField` rule references fails in ways
that are tedious to unpick.

The two non-additive rows are what [the rebuild](indexing.md#building-a-replacement-index)
exists for.

## Analysers

Stemming, stopwords and synonyms are not settings. They are analyser
configuration, they live in the shipped configset, and changing one invalidates
every document already indexed. Exposing them in the admin screens is
deliberately deferred: in practice it needs a configset upload and a reload
rather than an API call, and the audience is small while the cost of getting it
wrong is not.
