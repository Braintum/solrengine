# SolrEngine

WordPress plugin that answers search from Apache Solr and falls back to WordPress when Solr is down. Overview: [README.md](README.md). Admin-facing copy is `readme.txt`.

## Rules

- Stay compatible with PHP 7.4. `solrengine.php` is the exception: it must parse on older PHP, so no type declarations, short closures, or namespaced code there.
- Layers are WordPress surfaces, then integration, then domain, then backend adapters. Domain code must unit-test with no WordPress loaded. If a unit test needs `get_option()`, the seam has leaked.
- Backend calls return a `Result`. Do not throw across the integration boundary. Search fallback is returning `null` from `posts_pre_query`.
- Do not add a hook that exposes a raw backend request or lets a caller inject query syntax.
- Never log, return, or store credentials in plaintext. Redact before logging.
- New public API (hook, contract method, REST parameter) needs an `@since` docblock, a test, and a line in the matching doc.

## Do not edit by hand

- `docs/hooks.md` is generated. Change the hook docblock, then run `composer hooks`.
- `assets/build/` is compiled from `assets/src/` and committed.

## Checks

```bash
composer check
```

Read the diff after `composer lint:fix`. `phpcbf` rewrites string literals, not just whitespace.

## Docs

Start here, then follow the link for the area you are changing. The full index is in [README.md](README.md).

| | |
| --- | --- |
| [docs/architecture.md](docs/architecture.md) | Layers, contracts, seams |
| [docs/development.md](docs/development.md) | Setup, tests, layout, conventions |
| [docs/hooks.md](docs/hooks.md) | Every action and filter |
| [docs/schema.md](docs/schema.md) | Field contract and dynamic `se_*` fields |
| [docs/indexing.md](docs/indexing.md) | Sync, rebuilds, staleness |
| [docs/search.md](docs/search.md) | Relevance and fallback |
| [docs/security.md](docs/security.md) | Threat model |

Plans and ADRs live in `.claude/plans/` and are not shipped. Where the repo and a plan disagree, the plan's phase status is the record of which is right.
