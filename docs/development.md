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
solrengine.php      Bootstrap. Must parse on old PHP: it is what tells a user
                    their PHP is too old, so no modern syntax belongs in it.
src/Contracts/      Public API. Interfaces and value objects. No WordPress,
                    no network, no Solr vocabulary.
src/Support/        Container, configuration, credentials, logging.
src/Backend/        Backend adapters and the registry they register into.
src/Bootstrap.php   Composition root. The only class that knows how the others
                    are built.
tests/Unit/         No WordPress, no Solr.
tests/Integration/  WordPress, mocked backend.
tests/Backend/      WordPress and a real Solr.
```

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

## The 0.1.0 prototype

Removed on 2026-09-19. `src/` now contains only the current foundation.

The prototype's 27 catalogued defects are recorded in section 2.3 of the plan,
and several are encoded as regression tests so they cannot return: composite
document identifiers that cannot collide, a container that throws on an unknown
service rather than returning null, and credential redaction before logging.

To read the old code, check out the commit before its removal. Do not restore
it.
