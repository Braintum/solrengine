# ADR-010 — Commercialisation architecture

**Status:** Proposed · **Date:** 2026-09-19

## Context

The goal is a free plugin in the WordPress.org directory plus future paid functionality. Several
models are commonly assumed to be acceptable and are not.

Researched facts from the Detailed Plugin Guidelines:

- **Guideline 5:** "Plugins may not contain functionality that is restricted or locked, only to be
  made available by payment or upgrade." Sandbox-only API access counts as trialware. The same
  guideline explicitly permits "add-on plugins, hosted outside of WordPress.org", and allows
  upselling within the bounds of guideline 11.
- **Guideline 6:** Interfaces to external third-party services "are allowed, even for paid
  services", provided the service offers functionality of substance. "A service that exists for the
  sole purpose of validating licenses or keys while all functional aspects of the plugin are
  included locally is not permitted."
- **Guideline 8:** No serving updates or installing plugins from servers other than WordPress.org's.
- **Guideline 11:** Upgrade prompts must be limited, sparing and dismissible.

## Decision

**A fully functional free plugin, plus a genuine SaaS backend, plus add-on plugins distributed off
the directory. No locked functionality, ever.**

```
Free plugin (WordPress.org)
  Complete, unrestricted search against a customer's own Solr.
  Ships the Cloud adapter as an alternative backend — a choice, not an unlock.
        ├── SolrEngine Cloud (paid SaaS)          guideline 6
        └── Add-on plugins (off-directory)        guideline 5
```

Three architectural consequences, which is why this is an ADR and not a pricing note:

1. The **backend adapter registry is public API from v1.0**; the Cloud adapter and every add-on
   bind to it.
2. The **admin tab registry and facet registry are public API**, so add-ons extend rather than fork.
3. **No code path in the free plugin branches on a licence state.** There is no licence state in
   the free plugin.

## Alternatives considered

**Free plugin with Pro features locked behind a key.** Not permitted by guideline 5. This is the
model most commonly assumed to be standard, and it is the one that gets plugins rejected.

**Free plugin plus a licence-validation service.** Not permitted by guideline 6, which names this
pattern specifically.

**Free plugin that downloads and installs the paid version.** Not permitted by guideline 8.

**Free plugin as a funnel with deliberately limited capability.** Technically arguable, practically
rejected. Reviewers read intent, users read intent, and the reputational cost of shipping a
crippled free plugin exceeds the conversion gain.

**Support and services only.** Compatible but low leverage, and it does not scale with the product.
Retained as supplementary.

## Consequences

**Positive.** The free plugin is genuinely useful, which is what earns directory placement,
reviews and adoption. The Cloud product sells infrastructure and operations — a real cost the
customer avoids — rather than selling access to code they already have. Add-ons carry deep,
narrow features without bloating core. The compliance position is defensible on first submission
rather than after a rejection.

**Negative.** Revenue depends on the Cloud product actually existing, which is a business to
operate, not a feature to ship (§22.4). Add-ons need their own distribution and update
infrastructure. The free plugin must be maintained at production quality with no direct revenue.

**Neutral.** Some capability will be easier in Cloud mode — browser-direct search, analytics —
because of where the infrastructure sits. That is a natural consequence of architecture, not a
deliberate limitation, and the distinction matters both ethically and for review.

## Why

The line that keeps this compliant is precise and worth stating as a rule the team can apply to
future features: **a backend choice is an interface to a service; a greyed-out feature with an
"Upgrade" badge is locked functionality.** The two look similar in a mock-up and sit on opposite
sides of guideline 5. Because the distinction is architectural rather than cosmetic, it has to be
decided before the adapter registry is written — which is now.
