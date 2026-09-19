# ADR-009 — Configuration persistence and secret storage

**Status:** Proposed · **Date:** 2026-09-19

## Context

SolrEngine stores connection settings, credentials, index configuration, runtime state, job state,
failure records and cached data. These have different sizes, lifetimes, access frequencies and
sensitivities. The current code stores an endpoint in one option, reads it from a different option
name, and has no provision for credentials at all.

WordPress has no secret store. Autoloaded options are loaded on every request, so an option that
grows without bound is a site-wide performance problem — and logs are exactly the shape that grows
without bound.

## Decision

**Separate stores by concern, never one option for everything, and never credentials in plaintext.**

| Concern | Store | Autoload |
| --- | --- | --- |
| Connection settings | `solrengine_connection` option | No |
| Credentials | `wp-config.php` constants preferred; encrypted `solrengine_credentials` otherwise | No |
| Index configuration (types, field map, facets) | `solrengine_index_config` option, versioned with a migration runner | No |
| Runtime state (schema version applied, last sync, index generation) | `solrengine_state` option | No |
| Index run history | `{prefix}solrengine_index_runs` table | — |
| Failures and dead-lettered jobs | `{prefix}solrengine_failures` table, pruned | — |
| Job state | Action Scheduler's own tables | — |
| Query and facet caches | Object cache / transients, keyed by schema version and index generation | — |
| Multisite | `get_site_option` for network defaults, per-site override | No |

Credentials, in order of preference:

1. **`wp-config.php` constants** (`SOLRENGINE_SOLR_USER`, `SOLRENGINE_SOLR_PASSWORD`). Detected
   automatically; the UI shows "set in wp-config.php" and disables the field.
2. **Encrypted option** using `sodium_crypto_secretbox` with a key derived from `AUTH_KEY` and
   `SECURE_AUTH_KEY`, stored non-autoloaded.
3. **Never** plaintext, never rendered back into a form, never in a system-status export, never in
   a log, never in a REST response.

## Alternatives considered

**One `solrengine_settings` option holding everything.** Rejected. It mixes a 200-byte endpoint
with a field map that grows with the site, forces a full read-modify-write for any change, and
makes a concurrent write lose the other's data.

**Everything in custom tables.** Rejected for configuration. Options are the idiomatic, cached,
migration-free store for small configuration, and reviewers expect them.

**Logs in options or transients.** Rejected. Unbounded growth in a store WordPress loads eagerly is
one of the most common causes of WordPress performance collapse.

**Plaintext credentials with a documentation note.** Rejected. Any database dump — a backup, a
staging clone, a leaked export — becomes credentials for the customer's search infrastructure.

**A dedicated encryption key file outside the web root.** Rejected for v1. It cannot be created
reliably across hosting environments, and a key the plugin cannot read is a support incident.

## Consequences

**Positive.** Each store is sized and cached appropriately. Nothing unbounded is autoloaded. A
database dump does not yield plaintext credentials. Constants give security-conscious customers a
genuinely strong option. Versioned index configuration makes upgrades migratable rather than
guesswork.

**Negative.** More stores to reason about, and two tables to create, migrate and uninstall.
Encryption keyed from `wp-config.php` salts means rotating salts invalidates stored credentials —
detected, and surfaced as "re-enter your Solr password" rather than a silent failure.

**Neutral, and stated plainly:** option 2 is obfuscation against database disclosure, not against
code execution, because the key lives beside the code. The documentation must say exactly that.
Overstating a mitigation is worse than omitting it.

## Why

The separation is standard practice that the current code skipped, and the cost of retrofitting it
is a data migration for every installed site. The credential ranking is the honest one: WordPress
cannot store secrets well, so the best available answer is to prefer the place that is not the
database, do something defensible when it must be the database, and describe the residual risk
accurately.
