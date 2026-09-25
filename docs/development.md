# Development

## Requirements

| Tool | Version |
| --- | --- |
| PHP | 7.4 minimum. Develop on 8.1 or newer, but keep the code 7.4-compatible |
| Composer | 2.x |
| MySQL or MariaDB | Only for integration tests |
| Docker | Only for Solr-backed tests |

## Setup

```bash
composer install
composer check      # lint, static analysis and unit tests
```

The unit suite needs nothing else. It loads no WordPress and contacts no Solr,
and it must stay that way: if a unit test starts needing a WordPress function,
a seam has leaked and the fix belongs in the code, not the test.

## Commands

| Command | What it does |
| --- | --- |
| `composer lint` | Coding standards |
| `composer lint:fix` | Auto-fix. **Read the diff**, see the warning below |
| `composer analyse` | PHPStan |
| `composer test` | Unit tests |
| `composer test:integration` | WordPress integration tests |
| `composer check` | Lint, analyse and unit tests |
| `php bin/check-version.php` | Verify the header, constant and readme agree |
| `bash bin/build-release.sh` | Build the distributable into `build/solrengine` |

### Why `composer.json` pins a platform

```json
"config": { "platform": { "php": "7.4.33" } }
```

It tells Composer to resolve every dependency as though it were running on the
oldest PHP SolrEngine supports, whatever the machine doing the resolving is
running.

Without it, `composer update` on a PHP 8 machine — which is every machine — picks
dev dependencies that require PHP 8, writes them into `composer.lock`, and the
lock then cannot be installed on 7.4 at all. CI's 7.4 job fails with "your lock
file does not contain a compatible set of packages", which reads like a broken
matrix and is really a lock file resolved against the wrong floor. That happened,
and the packages were `doctrine/instantiator` and `myclabs/deep-copy`, both pulled
in by PHPUnit.

It has a second effect worth having: `composer install --no-dev` for a release
resolves against the supported floor too, so the shipped `vendor/` is correct by
construction rather than because the machine that built it happened to be old
enough.

Raise the number when the supported floor rises — see
[ADR-011](../.claude/plans/adr/ADR-011-version-support-policy.md), which records
what has to be true first.

### A warning about `composer lint:fix`

`phpcbf` rewrites string literals, not just whitespace. During Phase 0 it
silently changed `'wordpress'` to `'WordPress'` inside a test assertion,
turning a passing test into a failing one with no obvious cause. That
particular sniff is now disabled, but the general lesson stands: always read
the diff after auto-fixing, and never auto-fix and commit in one step.

## Integration tests

```bash
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
SOLRENGINE_TEST_SUITE=integration composer test:integration
```

Needs `curl` (or `wget`), `tar` and a MySQL you can reach. Deliberately **not**
Subversion: GitHub's runners dropped it and macOS has not shipped it with the
Xcode command line tools since 2020, so the script takes the test library from
the `wordpress-develop` mirror on GitHub instead.

One wrinkle lives in that script rather than in your head. The two sources tag
the same release differently — Subversion calls it `6.5`, GitHub calls it
`6.5.0` — so a two-part version gains a trailing zero on the way to the URL. Get
it wrong and the answer is a 404 rather than anything that names itself.

## Working without Solr

```php
define( 'SOLRENGINE_MEMORY_BACKEND', true );
```

Registers an in-memory backend, selectable on the connection screen. It holds
documents for one request, so a page that indexes and a page that searches see
different worlds — it is for working on admin screens, hooks and the WordPress
integration, not for looking at search results.

Its real job is `tests/Unit/BackendConformanceTest.php`, which asserts the rules
the backend contracts state in prose — writes are upserts, an unconstrained
selector is refused, a search never crosses sites — against an implementation,
with no server needed. The Solr adapter is held to the same rules by the backend
suite below; the difference is that these run on every commit rather than on
every commit where someone remembered to start Docker.

If a change to the WordPress layer cannot be made without teaching the memory
backend some Solr vocabulary, the abstraction has leaked and that is the alarm.

## Solr for backend tests

```bash
docker run -d --rm --name solrengine-test -p 8983:8983 solr:10 solr-precreate wordpress

SOLRENGINE_TEST_SUITE=backend SOLRENGINE_TEST_SOLR_URL=http://127.0.0.1:8983/solr \
	vendor/bin/phpunit --testsuite backend
```

The `solr-precreate wordpress` argument matters: a stock Solr starts with no
cores at all, and the suite skips or fails against a server that has none. Set
`SOLRENGINE_TEST_SOLR_COLLECTION` if you name yours something else.

Without `SOLRENGINE_TEST_SOLR_URL` every test in the suite skips, so an
unnoticed skip looks like a pass. Check the count.

Both supported Solr lines are exercised in CI: 10.x as the recommended target
and 9.10 as the last supported 9.x. See ADR-011.

## WooCommerce tests

`tests/Integration/ProductDocumentTest.php` maps real products through real
WooCommerce CRUD — a variable product's price range, an attribute's terms, a
hidden product's refusal — none of which can be faked convincingly.

It skips unless WooCommerce is loaded, which the test library does not do on
its own. To run it, install WooCommerce into the WordPress test library's
plugin directory and require its entry point alongside the plugin in
`tests/bootstrap.php`. That is not wired into CI yet, so the suite is currently
green by skipping there.

### Fixtures share one collection

Each backend suite owns a block of two site identifiers — its own, and a
neighbour it writes to in order to prove that one site's documents never reach
another's results. The blocks must not overlap: two suites writing to one site
pass alone and fail together, in whichever order they happen to run. Pick the
next free block when adding a suite.

| Suite | Sites |
| --- | --- |
| `QueryInjectionTest` | 501, 502 |
| `SuggestTest` | 601, 602 |
| `FacetTest` | 701, 702 |
| `ProductQueryTest` | 801, 802 |

## Layout

```
solrengine.php      Plugin entry point. Must parse on old PHP: it is what tells
                    a user their PHP is too old, so no modern syntax belongs in
                    it.
instant.php         Second entry point, reached directly over HTTP. Answers
                    autocomplete on a SHORTINIT boot. Same rule about old PHP.
constants.php       Version, paths, schema contract version. Included by both
                    entry points and needs no WordPress, so neither has to
                    define them twice.
src/Contracts/      Public API. Interfaces and value objects. No WordPress,
                    no network, no Solr vocabulary.
src/Support/        Container, configuration, credentials, logging.
src/Backend/        Backend adapters and the registry they register into.
src/Bootstrap.php   Composition root. The only class that knows how the others
                    are built.
src/Language/       Language tags and the site's languages. Pure.
src/Multisite/      Network configuration and this site's place in a network.
src/Integration/    Everything that knows about a specific other plugin:
                    WooCommerce, WPML, Polylang.
tests/stubs/        Hand-written signatures for optional plugins, so static
                    analysis can check calls into them. Never loaded at runtime.
tests/Unit/         No WordPress, no Solr.
tests/Integration/  WordPress, mocked backend.
tests/Backend/      WordPress and a real Solr.
```

## Database tables

Two, both owned by `Support\Tables`:

| Table | Holds |
| --- | --- |
| `{prefix}solrengine_index_runs` | One row per indexing run, pruned to the most recent fifty |
| `{prefix}solrengine_failures` | Objects that failed inside a run, capped per run |

Created on activation, and checked again the first time a run is recorded —
a site that was upgraded rather than activated never runs the activation hook.
Bump `Tables::VERSION` when a column changes; `dbDelta` does the rest.

Times are Unix timestamps in `bigint` columns rather than `datetime`. A
`datetime` column carries no timezone, so every reader has to know which one the
writer meant, and WordPress has three plausible answers.

## Options

Everything else lives in options. Three are autoloaded, and the rest are not:

| Option | Autoloaded | Holds |
| --- | --- | --- |
| `solrengine_connection` | yes | Endpoint, collection, timeouts, TLS |
| `solrengine_index_config` | yes | What is indexed, and the search settings |
| `solrengine_state` | yes | Applied schema version and fingerprint, reindex notice |
| `solrengine_credentials` | no | Encrypted, and read only when a request talks to Solr |
| `solrengine_breaker` | no | Circuit breaker state. Absent on a healthy site |
| everything else | no | Versions, install time, sync progress |

The three autoloaded groups are read by every request that searches, so leaving
them out of `alloptions` means a separate `SELECT` each on a request a visitor is
waiting on. They hold a few hundred bytes between them. `Config::set()` writes
them autoloaded and `Config::autoload_options()` names them.

`Support\Upgrade` corrects rows written before that, gated on the stored plugin
version and hooked to `admin_init` only — nothing there may cost a visitor a
query. It is the counterpart of `Tables::ensure()` for options, and the place a
future option migration goes. Note that `update_option()` cannot do this job: it
returns early when the value is unchanged and never reaches its autoload
argument, so `wp_set_options_autoload()` is what actually moves the flag.

## Conventions

- `StudlyCase` classes, `snake_case` methods and variables, PSR-4 autoloading.
- Everything in the global namespace is prefixed `solrengine` / `SOLRENGINE` /
  `SolrEngine`.
- Solr fields are prefixed `se_`.
- Services take their dependencies through their constructors. Nothing but the
  composition root, the CLI and tests touches the container.
- Backend calls return `Result`, never throw. WordPress has to keep rendering
  when Solr does not.
- Nothing calls `error_log()` directly. Use the `Logger` contract.
- Admin screens extend `Admin\Screen`, which holds the capability check, the
  redirect-with-notice and the form helpers. Each handler writes its own
  `check_admin_referer()` call: a nonce check is what a security review greps
  each handler for, and static analysis cannot follow one into a parent class.
- A handler reads `$_POST` once, immediately after that nonce check, and passes
  the array on. Nothing further down touches a superglobal.
- An optional plugin is reached through its own public API, never through its
  tables or its taxonomy terms. Those move between major versions; the public
  functions do not.
- Every language reaching a document or a query goes through
  `Language\LanguageTag` first. Four systems spell a locale four ways, and a
  document indexed under one spelling never matches a query using another.

## The 0.1.0 prototype

Removed on 2026-09-19. `src/` now contains only the current foundation.

The prototype's 27 catalogued defects are recorded in section 2.3 of the plan,
and several are encoded as regression tests so they cannot return: composite
document identifiers that cannot collide, a container that throws on an unknown
service rather than returning null, and credential redaction before logging.

To read the old code, check out the commit before its removal. Do not restore
it.
