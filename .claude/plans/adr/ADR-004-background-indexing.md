# ADR-004 — Background indexing architecture

**Status:** Proposed · **Date:** 2026-09-19

## Context

The current implementation indexes synchronously inside `save_post`, issuing an HTTP request with
`commit=true` per document. On a slow Solr every editorial save is slow; on an unreachable Solr
saves hang until the timeout. Bulk operations — an import, a bulk edit, a WP-CLI migration — issue
one commit per post.

A single `wp_update_post()` fires `save_post`, several `updated_post_meta` calls, `set_object_terms`
per taxonomy and `transition_post_status`. A naive listener produces six jobs for one logical
change. Sites in scope range from hundreds of posts to millions, on hosts with PHP time limits and
sometimes with WP-Cron disabled.

## Decision

**Asynchronous, queued, coalesced indexing. Action Scheduler as the v1 driver, accessed only
through a `JobQueue` contract.**

```
hook listeners → request-scoped dirty set (deduplicated)
               → one queued action per object, flushed on shutdown
               → batch worker claims N → build documents → bulk write with commitWithin
               → failures retried with backoff, then dead-lettered
```

Full synchronisation is an ID-cursor walk with adaptive batch sizing, stored progress, and resume,
cancel and restart. WP-CLI can drive the queue directly, bypassing WP-Cron and web timeouts.

No supported synchronous mode in production. A `SOLRENGINE_SYNC_INDEXING` constant exists for
integration tests and does not appear in the settings UI.

## Alternatives considered

**Synchronous indexing, as today.** Rejected. It makes WordPress's availability depend on Solr's,
which violates Principle 1 directly, and no configuration flag makes it safe.

**A custom queue table with WP-Cron.** A genuine contender: full control, no bundled dependency,
and a table designed for the workload. Rejected for v1 because it means reimplementing claim
semantics, retry backoff, concurrency limits and an admin UI — and getting claim semantics subtly
wrong produces duplicated or silently dropped indexing, which is the single worst failure mode this
product has. The `JobQueue` contract keeps this available as a driver if Action Scheduler proves
unsuitable.

**WP-Cron alone.** Rejected. No retry semantics, no claim isolation, no concurrency control, no
visibility, and it collapses under queue depth.

**An external worker or hosted queue.** Rejected for self-hosted v1: it adds an outbound service,
which triggers WordPress.org guideline 7 disclosure and consent for every user. Natural for the
Cloud product later.

**Queue directly from each hook without coalescing.** Rejected. It is the difference between an
importer running at normal speed and an importer crawling, and the fix is cheap.

## Consequences

**Positive.** Post saves stay fast regardless of Solr's state. Bulk operations are throttled and
observable. Failures are retried and inspectable rather than lost. Large sites index without ever
depending on a single long HTTP request. Coalescing turns hook noise into exactly one job per
changed object.

**Negative.** Action Scheduler is GPLv3, making the distributed whole GPLv3 — acceptable and
recorded deliberately. Its version-negotiation loader means another plugin's copy may win, so
initialisation must be defensive and degrade to a WP-Cron driver if the expected API is absent. It
depends on WP-Cron, so the dashboard must detect a stalled queue and say so. Index visibility lags
content changes by up to the `commitWithin` interval, which the UI states plainly.

**Neutral.** The contract means the driver choice is revisable without touching any calling code —
which is the point of recording it here rather than assuming it.

## Why

Action Scheduler is a mature job queue explicitly designed for distribution inside WordPress
plugins, with retries, claim-based concurrency, logging and an admin UI, already installed on every
WooCommerce site. The alternative is a month of work reproducing it, with the failure modes landing
in production rather than in a library thousands of sites already exercise. The contract keeps the
decision reversible, which is what makes adopting a dependency acceptable under Principle 10.
