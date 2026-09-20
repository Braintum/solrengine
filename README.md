# SolrEngine

Apache Solr powered search for WordPress. Faster, more relevant results, with
WordPress search as an automatic fallback.

This file is for developers. Site administrators want `readme.txt`, which is what
the WordPress.org plugin page renders.

## What it is

SolrEngine replaces WordPress's built-in search with Apache Solr on sites where
the default has stopped coping, and it is built so that **WordPress never depends
on it**. If Solr is unreachable, slow or misconfigured, WordPress answers searches
itself and the site keeps working. Nothing white-screens and no visitor sees an
error.

Three decisions shape everything else:

**It hooks the query layer, not the template layer.** One `posts_pre_query`
substitution puts Solr results into an ordinary `WP_Query`. Classic themes, block
themes, Elementor, Bricks and WooCommerce loops keep working because none of them
can tell the difference — and returning `null` from that same filter is the entire
fallback story.

**Arbitrary metadata is indexed through dynamic fields in a reserved `se_*`
namespace.** Indexing a new custom field needs no schema migration, which turns
the hardest problem in the product into a naming convention.

**Failure is a value, not an exception.** Every backend call returns a `Result`.
Nothing throws across the integration boundary, because an unreachable Solr is an
expected condition.

## Getting started

```bash
composer install
composer check      # coding standards, static analysis, unit tests, hook reference
```

The unit suite needs nothing else — no WordPress, no Solr, no database. If a unit
test ever needs a WordPress function, a seam has leaked.

To work on the WordPress side without a Solr running, add
`define( 'SOLRENGINE_MEMORY_BACKEND', true );` to `wp-config.php` and pick the
in-memory backend on the connection screen.

Everything else — the integration suite, a real Solr in Docker, the test fixtures
— is in [docs/development.md](docs/development.md).

## Documentation

**For contributors**

| | |
| --- | --- |
| [architecture.md](docs/architecture.md) | Layers, contracts, and why the seams sit where they do |
| [development.md](docs/development.md) | Local setup, the test layers, conventions |
| [hooks.md](docs/hooks.md) | Every action and filter. **Generated** — see below |

**For integrators**

| | |
| --- | --- |
| [rest-api.md](docs/rest-api.md) | Endpoints, parameters, response shapes, versioning |
| [cli.md](docs/cli.md) | Every command |
| [schema.md](docs/schema.md) | The field contract, dynamic field conventions, migration |

**For administrators**

| | |
| --- | --- |
| [solr.md](docs/solr.md) | Provisioning Solr, the shipped configset, TLS, authentication |
| [indexing.md](docs/indexing.md) | Full and incremental sync, rebuilds, troubleshooting staleness |
| [search.md](docs/search.md) | Relevance, fallback behaviour, instant search |
| [facets.md](docs/facets.md) | Faceted search and the filter blocks |
| [woocommerce.md](docs/woocommerce.md) | Indexed product fields, the price limitation, visibility |
| [multisite.md](docs/multisite.md) | Networks, topologies, per-site opt-in |
| [multilingual.md](docs/multilingual.md) | WPML, Polylang, per-language analysis |
| [compatibility.md](docs/compatibility.md) | Supported versions and bundled licences |
| [security.md](docs/security.md) | Threat model, and what each mitigation does **not** cover |
| [privacy.md](docs/privacy.md) | What is indexed, what is sent where, what is never touched |

The planning artefacts and the eleven architecture decision records live in
[`.claude/plans/`](.claude/plans/). They are not shipped in the release package.
Where this repository and the plan disagree, the phase status sections in the plan
record which is right and why.

## Two files that are generated

`docs/hooks.md` is written from the docblocks above each hook by
`bin/generate-hooks.php`, so a signature and its documentation cannot drift apart.
Change the docblock, then:

```bash
composer hooks
```

CI runs it with `--check` and fails if the committed file is stale — or if a hook
has no docblock, no `@since`, or documents a different number of parameters than it
actually passes. Hooks are public API from 1.0, so an undocumented one has no
contract.

`assets/build/` is compiled from `assets/src/` and committed, because
WordPress.org requires access to the source of anything compiled. CI fails if the
committed output does not match a fresh build.

## Contributing

Run `composer check` before opening a pull request. Read the diff after
`composer lint:fix` — `phpcbf` rewrites string literals, not just whitespace, and
has silently changed a test's expected value here before.

New public API — a hook, a contract method, a REST parameter — needs a docblock
with an `@since`, a test, and a line in the relevant document. That is not
ceremony: three of the defects found in later phases were contract methods and
filters that nothing exercised.

## Licence

GPLv2 or later. SolrEngine bundles Action Scheduler, which is GPLv3, so the
package as distributed is effectively GPLv3. See
[compatibility.md](docs/compatibility.md).
