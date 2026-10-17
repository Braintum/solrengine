---
name: solrengine-development
description: Checklist for changing the SolrEngine WordPress plugin — PHP, templates, front-end assets, hooks, REST routes, docs — to WordPress.org directory standards. Use before and while editing anything under wp-content/plugins/solrengine, before calling a change done, and when preparing a release package.
---

# Working on SolrEngine

SolrEngine is headed for the WordPress.org plugin directory. Treat every change
as something a plugin reviewer will read. The project rules are in `AGENTS.md`
and win over anything here; this skill is the working checklist that goes with
them.

## Before writing code

1. Read `AGENTS.md`, then the doc for the area (table at its foot).
2. Find the class that already answers the question. `SearchService`,
   `QueryTranslator`, `Suggester`, `InstantResults`, `FacetRegistry` and
   `HttpTransport` between them build every query and read every reply. A second
   implementation is a defect, even when the first cannot run where you are —
   add a seam (an interface, a container override) instead.
3. Decide which layer the change belongs to: WordPress surface → integration →
   domain → backend adapter. Domain code must unit-test with no WordPress loaded.
4. A new web-reachable entry point needs the owner's say-so. Only
   `solrengine.php` and `instant.php` exist; do not add another.

## WordPress.org requirements

Reviewers reject plugins for these. Check each one that the change touches.

- **Escape on output, late.** `esc_html`, `esc_attr`, `esc_url`,
  `wp_kses_post`; `esc_html_e` / `esc_attr_e` for translated literals. That
  includes templates and admin screens. Never echo a variable raw.
- **Sanitize on input, early.** `sanitize_text_field`, `sanitize_key`,
  `absint`, `wp_unslash` first. Read `$_POST` once, right after the nonce check,
  and pass the array on.
- **Nonces and capabilities** on every state change: `check_admin_referer()`
  written in the handler itself (not inherited), plus `current_user_can()`.
  REST routes need a real `permission_callback`; `__return_true` only for public
  reads, and say so in a comment.
- **Prefix everything global** with `solrengine` / `SOLRENGINE` / `SolrEngine`:
  options, hooks, handles, CSS classes, JS globals, transients, cron events.
- **i18n.** Text domain `solrengine`, literal strings only (no variables in
  `__()`), `/* translators: */` comments before any placeholder. In JS, import
  from `@wordpress/i18n` and call `wp_set_script_translations()`.
- **Enqueue, never inline tags.** `wp_enqueue_script` / `_style` with the
  `.asset.php` dependencies and version. Pass data with
  `wp_add_inline_script()` and `wp_json_encode()`; `wp_localize_script()` turns
  numbers and booleans into strings.
- **Direct-access guard** at the top of every PHP file that is not
  declaration-only, in exactly this form (`bin/check-direct-access.php` matches
  it):

  ```php
  if ( ! defined( 'ABSPATH' ) ) {
  	exit;
  }
  ```

- **No front-end credit** unless the site owner opts in (guideline 10). The
  popup's "Powered by" line defaults to off.
- **No remote calls a site did not configure**, no tracking, and no compiled
  code without accessible source (guideline 4). `.distignore` drops
  `assets/src` from the package, so either ship it or link a public source in
  `readme.txt` — open as publishing task P01, a blocker. A new asset adds to it.
- **GPL-compatible** everything, including fonts, icons and npm packages that
  end up in `assets/build`.
- **PHP 7.4.** No `match`, no constructor promotion, no union types, no `?->`,
  no `str_contains` without a polyfill. `solrengine.php` must parse on older
  PHP still: no type declarations, short closures or namespaces there.

## Public API

A hook, a contract method, a REST parameter, a template, a template slot
(`data-solrengine-*`) and a JS event are all public API. Each one needs:

- [ ] `@since x.y.z` in its docblock (the unreleased version is in `readme.txt`'s changelog)
- [ ] a test
- [ ] a line in the matching doc (`docs/search-modal.md` for templates, slots and the JS event)
- [ ] for hooks: `composer hooks` to regenerate `docs/hooks.md` (never edit that file by hand)
- [ ] a `readme.txt` changelog line if a site owner would notice

Renaming or removing any of them breaks sites. Keep the old one working, or
treat it as a breaking change and say so in the changelog.

## Templates

Templates in `templates/` are located theme-first through
`Integration\WordPress\Templates`: `yourtheme/solrengine/<name>.php`, then the
plugin's copy, then the `solrengine_template` filter.

- The script finds parts by `data-solrengine-*` attributes, never by class.
  Classes and structure are free to change; attributes are the contract.
- Card templates have exactly one root element, carrying `role="option"`.
- Values are inserted as text and URLs are restricted to http(s) in
  `assets/src/search-modal.js`. Do not add a slot that inserts markup.
- The fast path loads no theme, so templates are rendered once per page into
  `<template>` elements. Anything per-result that a template cannot express
  belongs in the `solrengine:result` event, not in a new endpoint.

## Front-end assets

- Source in `assets/src`, build into `assets/build` with `npm run build`, and
  commit both. A change to `assets/src` without a rebuild is incomplete.
- Format JS with `npx wp-scripts lint-js <file> --fix`, not with
  `wp-scripts format`: Prettier and the ESLint config disagree about trailing
  commas and ternary indentation, and ESLint is what CI checks. The file comes
  before `--fix`: written the other way round, wp-scripts lints and rewrites the
  whole project, including `webpack.config.js`'s `@package` tag. Read the diff.
- Write `' '` as a named constant; the formatter turns the escape into an
  invisible literal.
- `filters.css` and `instant-search.css` have older stylelint findings
  (publishing task P13). Do not add new ones; fix old ones when you touch them.
  `npm run lint:css` also feeds the `.js` files to stylelint, so lint CSS files
  by name until that script is fixed.

## Checks

The PostToolUse hook in `.claude/settings.json` runs the relevant part of these
after every edit: php -l, phpcs, phpstan, the unit suite, the hook-reference and
direct-access checks, and eslint or stylelint. When it reports a failure, fix it
before the next edit. Set `SOLRENGINE_HOOK_SKIP=1` to disable it for a session.

Before calling a change done, run the full set:

```bash
composer check                # phpcs, phpstan, unit tests, hooks --check, direct-access check
npm run lint:js
npx wp-scripts lint-style <changed .css files>
npm run build && git status assets/build   # rebuilt output is committed
```

Integration tests need the WordPress test library and a MySQL database:

```bash
bash bin/install-wp-tests.sh <db> <user> <pass> 127.0.0.1 latest
SOLRENGINE_TEST_SUITE=integration composer test:integration
```

Skips are not passes. Read the count. The WooCommerce tests skip when WooCommerce
is not loaded; the backend suite skips entirely without `SOLRENGINE_TEST_SOLR_URL`.

Read the diff after `composer lint:fix`. `phpcbf` rewrites string literals.

## Before a release package

Build in a scratch copy, never in the working tree's `vendor/`:

```bash
rsync -a --exclude vendor --exclude node_modules ./ "$SCRATCH/solrengine/"
cd "$SCRATCH/solrengine"
composer install --no-dev --optimize-autoloader
bash bin/build-release.sh                       # honours .distignore
wp plugin check "$SCRATCH/solrengine/build/solrengine"
php bin/check-version.php
```

The version number lives in three places (the `solrengine.php` header,
`SOLRENGINE_VERSION` in `constants.php`, the `readme.txt` Stable tag) and `bin/check-version.php` holds
them together. Bump the version only when the owner asks — that is a release
task, not part of a feature.

Do not upload, tag, or submit anything to WordPress.org without the owner's
explicit go-ahead.
