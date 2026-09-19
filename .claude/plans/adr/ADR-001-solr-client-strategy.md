# ADR-001 — Solr client and transport strategy

**Status:** Proposed · **Date:** 2026-09-19 · **Supersedes:** the existing dual-client design

## Context

The current plugin ships two Solr clients: a thin one built on the WordPress HTTP API, and a
Solarium-based one selected by a configuration flag. Solarium 6.4.0 is vendored, pulling in Symfony
and PSR packages. The Solarium client is instantiated with a hand-written no-op PSR-14 event
dispatcher, which disables Solarium's own plugin and event system — the mechanism that most of its
advanced features are built on.

Relevant researched facts:

- Solarium 7.0.0 (April 2026) requires PHP ^8.1 and supports Solr 10. Solarium 6.3.7 and later also
  require PHP 8.1+. The library is BSD-3-Clause, which is GPL-compatible.
- WordPress 7.1 requires PHP 7.4. WooCommerce works on PHP 7.4 today and has only *proposed* raising
  its minimum to 8.1 for its January 2027 release.
- Solr 10 removed the PHP response writer and `stream.body`, and requires base URLs ending in
  `/solr`.

SolrEngine uses a narrow slice of Solr's HTTP surface: `/update`, `/select`, `/schema`,
`/admin/ping`, `/admin/info/system`, and a suggest handler. JSON in, JSON out.

## Decision

**Remove Solarium. Implement a thin internal Solr transport on the WordPress HTTP API, behind the
backend adapter contract from ADR-007.**

The transport owns URL construction, authentication headers, timeouts, retry and backoff, response
parsing, Lucene value escaping, and error classification. It is roughly the size of the two clients
it replaces and does strictly less.

## Alternatives considered

**Keep Solarium as the only client.** Rejected. It imposes a PHP 8.1 floor derived from a
dependency rather than from the product's own needs, cutting off hosts that WordPress and
WooCommerce both still support. It places Symfony's event dispatcher in the global namespace of
every site that installs SolrEngine, which is the classic WordPress dependency-conflict scenario.
Avoiding that requires PHP-Scoper, which adds build complexity and produces vendor code that is
harder for a reviewer to read.

**Keep both clients.** Rejected outright. Two transports means two sets of bugs, two escaping
implementations, two error-handling paths and a combinatorial test matrix — in exchange for a
choice no user has a basis to make. The current code already demonstrates the cost: the two clients
have divergent `ping()` return types, so callers break when the flag changes.

**Guzzle or another HTTP client.** Rejected. The WordPress HTTP API is what hosts have configured
for proxies and TLS, what reviewers expect, and what site owners can already filter. Bundling a
second HTTP stack buys nothing here.

**Solarium behind the adapter, as one backend among several.** Deferred rather than rejected. The
adapter boundary means this remains available if a future need appears that genuinely justifies it.

## Consequences

**Positive.** No PHP floor imposed by a dependency. No namespace collision risk. A runtime
dependency tree of essentially one package (Action Scheduler). Full control over escaping, which
ADR-008 and the injection mitigations in the security model depend on. Smaller distributed package
and a faster plugin review.

**Negative.** SolrEngine implements query construction, response parsing and Lucene escaping
itself, and must get them right. Mitigated by the narrow API surface, a comprehensive unit test
layer over exactly these components, and the fact that the equivalent Solarium code paths were
already being bypassed.

**Neutral.** Removing Solarium is a breaking change for anyone depending on the current
`client_type` flag. Given the plugin's state — 0.1.0, unreleased, with the Solarium path failing on
core creation — this is theoretical.

## Why

The dependency is not earning its cost. Its headline value is faceting, highlighting and
suggestions, which SolrEngine will express through the JSON Request API as structured JSON — and
building that JSON is not made easier by an object model designed around Solr's older parameter
style. Meanwhile it costs a PHP version floor, a namespace collision surface, and a build-tooling
question, and the existing integration already neutralises its event system.

The deciding argument is Principle 10 combined with the dependency gates in Appendix B: a
dependency must solve a problem larger than the code it replaces. Here it does not.
