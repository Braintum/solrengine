# Security policy

## Reporting a vulnerability

Please report security issues privately, through GitHub's
[private vulnerability reporting](https://github.com/Braintum/solrengine/security/advisories/new).
Do not open a public issue, pull request or support-forum topic for a
suspected vulnerability.

Include the plugin version, what an attacker needs (for example: a subscriber
account, an administrator account, or nothing), the steps to reproduce, and
what they can do once it works. A proof of concept against a site you own is
welcome; please do not test against sites you do not.

We will acknowledge the report, keep you informed while we investigate, and
credit you in the changelog when the fix ships, unless you would rather not be
named.

## Supported versions

Security fixes are made for the latest release. Update to it before reporting,
in case the issue is already fixed.

## Scope

The plugin itself: its PHP, its JavaScript, its REST routes and its admin
screens. What the plugin defends against, and what it deliberately does not, is
described in [docs/security.md](docs/security.md). Vulnerabilities in Apache
Solr, WordPress, WooCommerce or Action Scheduler should go to those projects.
