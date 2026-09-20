# Multilingual sites

SolrEngine reads languages from the plugin you already use. It does not have a
language model of its own, and that is deliberate: WordPress has a locale and
nothing more, everything above it is a plugin, and a search plugin inventing a
third answer would be wrong on every site that already had one.

## Where languages come from

| Source | Used when |
| --- | --- |
| WPML | The plugin is active and configured |
| Polylang | The plugin is active |
| WordPress site language | Always, as the fallback |

The first that answers wins. The fallback reports exactly one language, so a
site with no multilingual plugin is not "multilingual with one language" — every
behaviour below stays switched off, and the only cost is one short string per
document.

Both integrations go through the plugin's own public API — `pll_get_post_language()`,
`wpml_post_language_details` — never through its tables or its taxonomy terms.
Those are implementation details that move between major versions, and reaching
for them is how an integration acquires a dependency on a release nobody runs.

To support a plugin SolrEngine does not know about, implement
`SolrEngine\Contracts\LanguageProvider` and return it from the
`solrengine_language_provider` filter.

## What gets indexed

Every document carries `se_s_lang`, a BCP-47 tag such as `en-US` or `pt-BR`, or
`und` when there is nothing to go on.

The tag is normalised, because the four systems involved spell a language four
ways: WordPress says `en_US`, Polylang says `en`, WPML reports both, and the
index holds `en-US`. A document indexed under one spelling and a query filtered
on another simply do not match, and search returns nothing with no error
anywhere.

WordPress's `de_DE_formal` becomes `de-DE`. "Formal" describes how the interface
addresses the reader, not how German is written, and the two analyse identically.

## How searching changes

A visitor searching from the German site gets German results. The English
translation of the same page is not offered as a second hit, because it is the
same page and they cannot read it.

Turn that off under **SolrEngine → Search** if most of your pages exist in one
language only — a documentation site where the English page is often the only
one there is.

The filter is written as *"not (has a language that is not this one)"* rather
than as *"has this language"*. That matters more than it sounds: every document
indexed before 0.9.0 carries no language field, so the obvious positive filter
would delete the entire existing index from the results the moment a
multilingual plugin was activated, and keep doing so until somebody reindexed.

A `language` filter and a `language` facet appear on multilingual sites, so a
theme can offer "search this in French instead" over the same result set.

## Language-aware analysis

Optional, and worth having. Analysing text with an analyser that knows the
language means a German search for *Häuser* finds *Haus*, an English search for
*running* finds *run*, and Turkish is lower-cased by the rules Turkish actually
uses rather than the ones everyone else does. None of that can be done by the
language-neutral analyser, which has to be right for every site at once.

Switch a language on under **SolrEngine → Search**. Each one adds a dynamic
field to your Solr schema and then indexes the title, excerpt and content a
second time through that analyser.

Three things to know.

**SolrEngine will not create the analyser.** A field type is a much larger
commitment than a field and a wrong one is not recoverable by adding another, so
only languages your Solr already defines a `text_<language>` for are offered.
The configset SolrEngine ships defines twenty-four of them; Solr's own
`_default` defines one.

**A language with no analyser is not broken.** Its content is indexed and
searchable through the neutral fields. The only thing missing is stemming.

**Existing content is not re-analysed.** Solr never rewrites documents that are
already indexed, so run a full index after switching a language on.

Regional variants share an analyser: `pt-BR` and `pt-PT` are both Portuguese to
a stemmer, and are still kept apart by their full tag when filtering.

## What is not supported

Japanese, Chinese, Korean and Thai need Solr modules that are not loaded by
default. They are absent from the shipped configset rather than half-configured,
because a schema referencing a filter class that is not there does not degrade —
the core fails to load. Define those field types yourself if your Solr has the
modules, and they will appear in the list.

Per-language relevance weighting is not offered: one set of boosts applies to
every language. The analysed fields inherit the weight of the neutral field they
duplicate, so a match in the document's own language already scores higher than
a neutral one.
