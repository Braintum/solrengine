# SolrEngine

WordPress plugin that answers search from Apache Solr and falls back to WordPress when Solr is down. Overview: [README.md](README.md). Admin-facing copy is `readme.txt`.

## Rules

- Stay compatible with PHP 7.4. `solrengine.php` is the exception: it must parse on older PHP, so no type declarations, short closures, or namespaced code there.
- Layers are WordPress surfaces, then integration, then domain, then backend adapters. Domain code must unit-test with no WordPress loaded. If a unit test needs `get_option()`, the seam has leaked.
- Backend calls return a `Result`. Do not throw across the integration boundary. Search fallback is returning `null` from `posts_pre_query`.
- Do not add a hook that exposes a raw backend request or lets a caller inject query syntax.
- Never log, return, or store credentials in plaintext. Redact before logging.
- New public API (hook, contract method, REST parameter) needs an `@since` docblock, a test, and a line in the matching doc.
- Reuse before you add. Before writing anything that talks to a backend, builds a
  query, or shapes a response, find the class that already does it. A second
  implementation of an existing responsibility is a defect, not a feature — including
  when the first one cannot run in your context.
- An environment difference gets a seam, not a fork. When an existing class cannot run
  where you need it (no HTTP API under `SHORTINIT`, no WordPress in CLI), extract the
  part that varies behind an interface and inject it. Never copy the class and edit the
  copy. `HttpTransport` with `Sender` is the worked example: all policy in one place,
  only the wire call swapped.
- One place per rule. Exactly one file builds a Solr request and interprets a reply.
  Exactly one file defines each response body's shape. If the same protocol or
  wire-format rule is written in two files, one will keep an older, laxer copy — which
  is precisely how `build_query()` silently drops the site scope and the visibility
  filter while still returning results.
- Surfaces differ in bootstrap, never in behaviour. A REST route and a CLI command
  answering the same question call one domain class that answers it (`Suggester`). The
  surface holds only bootstrap, input reading and output.
- `solrengine.php` is the only entry point. Do not add a file that is requested directly
  over HTTP or that loads `wp-load.php` itself: WordPress.org review rejects it, which is
  why `instant.php` was removed before the first release (see
  [docs/security.md](docs/security.md#entry-points)). Reach Solr only through the
  WordPress HTTP API.
- Measure before building. Profile where the time actually goes and report it before
  writing a faster path; most latency in a WordPress request is not in this plugin. A
  change that buys speed with duplication has to justify the duplication first.
- Templates in `templates/` are public API: a theme copies them. Changing a
  `data-solrengine-*` attribute or a slot name breaks every theme override, so
  treat it like a hook change and document it in `docs/search-modal.md`.

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
| [docs/search-modal.md](docs/search-modal.md) | Search popup, theme templates |
| [docs/security.md](docs/security.md) | Threat model |

Plans and ADRs live in `.claude/plans/` and are not shipped. Where the repo and a plan disagree, the plan's phase status is the record of which is right.
