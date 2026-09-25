# Security

What SolrEngine defends against, how, and — as importantly — what each
mitigation does **not** cover. A security document that lists only strengths
misleads the person it is written for.

## Server-side request forgery

**The risk.** An administrator types a URL and the server fetches it. That is
SSRF by construction. Without controls, the plugin becomes a way to probe
internal infrastructure and cloud metadata services from inside the network.

**Why the usual answer does not work here.** WordPress offers
`wp_safe_remote_get()`, which runs `wp_http_validate_url()`. That rejects
loopback and private ranges and permits only ports 80, 443 and 8080. The
overwhelmingly common Solr deployment is `http://127.0.0.1:8983/solr`, blocked
on both counts. Filtering `http_request_host_is_external` to permit it would
weaken the protection for every other plugin on the site.

**What SolrEngine does instead.** `EndpointValidator` applies its own rules:

| Control | Rule |
| --- | --- |
| Scheme | `http` and `https` only |
| Credentials in the URL | Rejected outright |
| Query strings and fragments | Rejected; this is a base URL |
| Cloud metadata addresses | Always blocked, whatever the settings say |
| Link-local (169.254.0.0/16) | Always blocked |
| Multicast, broadcast, reserved | Always blocked |
| Loopback and private ranges | Permitted only with an explicit acknowledgement |
| Ports | Allowlist: 80, 443, 8443, 8983. Widened only through a filter, in code |
| Plain HTTP to a remote host | Rejected |
| Redirects | Never followed. A 3xx is an error |
| Response size | Capped |
| Capability | `manage_options` only |

Every rule has a test in `tests/Unit/EndpointValidatorTest.php`.

**What this does not cover: DNS rebinding.** The address validated can differ
from the address connected to later. The resolved addresses are recorded on the
`Endpoint` so the transport can compare them at request time, which narrows the
window rather than closing it. Note also that the attacker must already hold
`manage_options`, which is close to full control of the site.

## Credentials

Ranked, best first:

1. **`wp-config.php` constants.** `SOLRENGINE_SOLR_USER` and
   `SOLRENGINE_SOLR_PASSWORD`. Not in the database, not in a dump, not editable
   through a compromised admin session. Detected automatically; the admin field
   is disabled and says so.
2. **Encrypted option.** `sodium_crypto_secretbox` with a key derived from
   `AUTH_KEY` and `SECURE_AUTH_KEY`, stored non-autoloaded.
3. **Never** plaintext, never returned to the browser, never in a log, never in
   a support export.

**What option 2 does not cover.** The key lives in `wp-config.php`, beside the
code. Anything that can execute code on the host can read it. This protects
against database disclosure — a leaked backup, a staging clone, an exported
table — and nothing more. It is obfuscation against that one threat, not
encryption in the sense a reviewer means.

Rotating the site's salts invalidates stored credentials. That is detected and
reported as "re-enter your password" rather than failing silently.

`Credentials::redact()` strips secrets from any context before it reaches a
log, including credentials embedded in URLs, and has its own test file.

## Query injection

Not yet reachable — search arrives in Phase 3 — but the design is fixed:

- User text is never interpolated into a Solr query. It is bound as a separate
  parameter that the query template dereferences, so `{!` in user input is not
  evaluated as a local parameter.
- `fq`, `sort`, `fl`, `facet`, `rows` and `start` are built only from
  server-side definitions.
- Public endpoints accept facet and filter **keys** from a server-side
  registry, never raw query syntax. This is what stops the search endpoint
  becoming an unrestricted Solr proxy.
- No extension hook exposes a raw backend request. That omission is
  deliberate: one such hook would make every guarantee above unenforceable.

## Schema changes

SolrEngine can modify a customer's Solr schema. Capability is not consent, so:

- Only fields prefixed `se_` are ever touched.
- Only additive commands are issued: `add-field`, `add-dynamic-field`,
  `add-copy-field`. Never replace, never delete, not even for its own fields.
- A plan is produced and shown before anything is applied.
- Field types are never created. An analyser is a far larger commitment than a
  field and a wrong one is not recoverable by adding another field.
- A conflicting existing field is reported as a blocker for a human, never
  silently replaced.

The additive restriction is enforced twice: once when the plan is built, and
again in `SolrBackend::apply()` immediately before anything is sent.

## Index lifecycle

Building a replacement index means naming collections, and a collection name
becomes a path segment in a URL. Every name is checked against
`CollectionName::is_valid()` before it reaches a request, so a name carrying a
slash, a query string or a `..` cannot address a handler other than the one
intended. Names arriving from Solr's own listings are checked as well as names
arriving from a form; the point of the guard is that it does not depend on
knowing where a name came from.

Deletion is never automatic. Switching to a new index leaves the old one in
place, `drop_index()` refuses the index currently configured, and the only way
to remove one is an administrator asking for it by name.

## Admin screens

Every screen requires `manage_options`, and every form handler verifies a nonce
before reading a single value from the request. The two checks answer different
questions — "may this user do this" and "did this user ask for it" — and a
cross-site request from an administrator's own browser passes the first.

The capability check is shared in `Admin\Screen`; the nonce check is written out
in each handler rather than shared, deliberately. It is the check a review greps
for, and static analysis cannot follow one into a parent class.

Each handler reads `$_POST` once, immediately after that nonce check, and passes
the values on. Nothing below that point touches a superglobal, so there is no
path by which a helper reads request data that was never verified.

## Networks

Two controls, and they do different jobs.

**Per-site opt-in.** A site in a network does nothing until a network
administrator switches it on. That is primarily an operational control — it stops
four hundred sites indexing at once — but it is also the answer to a site
administrator who should not be able to point their site at the network's Solr
without anybody knowing.

**Pinned settings.** A network administrator can lock the endpoint, the
collection and the TLS and private-network options so a site administrator cannot
change them. Without that, any site administrator in the network can aim the
plugin at an address of their choosing, which is the SSRF surface described above
with one more person able to reach it. A pinned value is reported as pinned on
the site's own screen rather than silently discarding a save.

A constant in `wp-config.php` still overrides a network pin, because the file on
the server outranks the database in every case.

**Network credentials** are encrypted with the keys in `wp-config.php`, which a
network shares by definition, so one stored value is readable from every site.
That is the property that makes them usable and it is also their limit: any site
in the network can read them, so a network is a trust boundary and a compromised
site administrator on any site has the Solr password. Where that is not
acceptable, use `SOLRENGINE_SOLR_USER` and `SOLRENGINE_SOLR_PASSWORD` in
`wp-config.php`, or give each site its own credentials.

**Cross-site leakage under a shared collection** is covered below: `se_i_site` is
injected by the query builder, never supplied by a caller, and never removable
through a hook.

## Backends

Each backend keeps its own credentials, in its own option. With one backend that
was a distinction without a difference; with two it is the difference between a
Solr password staying with Solr and being handed to whatever else the site is
pointed at.

A backend that manages its own schema reports it, and the schema screens stand
down rather than offering changes that cannot be applied. That path was prose in
a contract until a second adapter made it real.

See [privacy](privacy.md) for what each backend is sent, and for the rules any
future off-site backend has to meet before it ships.

## Data exposure

By default only publicly visible content is indexed. Drafts, private posts,
trashed content and the bodies of password-protected posts are not. Post meta
is never indexed unless the specific key is allowlisted, because meta routinely
holds internal notes, API keys and personal data.

Two leakage paths that return no documents and are easy to miss:

- **Facet counts disclose existence.** Visibility and site filters are applied
  in the same request as faceting, before counts are computed, so a count can
  never reveal how much private content exists. Asserted against a real Solr in
  `tests/Backend/FacetTest.php`.
- **Catalogue visibility is a disclosure rule too.** A product a merchant hid
  from search must not appear in search results, in the REST API, or in the
  autocomplete dropdown. The rule is applied on every path that reads the
  index, and a fully hidden product is never indexed at all.
- **Cross-site leakage on a shared collection.** `se_i_site` is injected by the
  query builder, never supplied by a caller and never removable through a hook.
  On a shared multisite collection it is the only thing separating tenants, so
  it is treated as a security control rather than a scoping convenience.

## The instant-search entry point

`instant.php` is reachable directly over HTTP and answers autocomplete on a
`SHORTINIT` boot. Being a second door into the plugin, it is worth stating what it
does and does not rely on.

**It reads, and only reads.** One prefix query for at most twenty short rows. No
parameter reaches Solr as syntax, no state is written, and there is nothing to
forge — which is why it carries no nonce.

**The query's guarantees are the query's, not the bootstrap's.** The site scope
and the public-visibility filter are built by `SuggestTranslator` on the server
and are unreachable from the query string. Typed text is bound as a Solr
parameter. The endpoint is the validated one from configuration, and credentials
are decrypted through the same `Encryption` from the same salts.

**The site is resolved by WordPress, never by a parameter.** There is no `site`
argument. On multisite the blog comes from the host and path WordPress itself
resolved, because `se_i_site` is what separates tenants.

**`CookieViewer` is not authentication.** In a `SHORTINIT` process there is no
user stack, so the presence of a `wordpress_logged_in_` cookie is noticed and
never validated. It grants nothing: its only consequence is whether one
suggestion list may be shared in the cache. A forged or expired cookie makes it
answer "signed in", which costs the sender a cache entry and gains them nothing.
What matters is the direction of the error — a signed-in user is never mistaken
for anonymous, which is the mistake that would leak one person's view into
another's cache.

**No path comes from the request.** `wp-load.php` is found by walking up from
`__FILE__`, never from `DOCUMENT_ROOT` or any other attacker-influenced value,
because a path taken from a request is how a file-inclusion bug gets written.

**A broken install answers an empty list.** A missing autoloader or an
unconfigured backend returns `{"suggestions":[]}` rather than a fatal or a stack
trace, and error messages never echo the Solr host.

One thing this endpoint does not currently do that the REST route does: **it is
not rate limited.** `RateLimiter` runs on the REST routes only. Note also that
rate limiting depends on `wp_cache_*` outliving the request, so it is only
effective on a site with a persistent object cache — without one, the counter is
per-request memory on either route.

## Reporting a vulnerability

Please report privately rather than in a public issue.
