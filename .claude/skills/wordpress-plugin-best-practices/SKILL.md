---
name: wordpress-plugin-best-practices
description: General WordPress plugin engineering practice — security (escaping, sanitising, nonces, capabilities), hooks, options and custom tables, activation/upgrade/uninstall, REST routes, admin-ajax, cron and background work, asset loading, i18n, performance, multisite, blocks, optional integrations and testing. Use when writing or reviewing any WordPress plugin PHP or JS, when touching a WordPress API you have not used in this codebase yet, or when a change crosses into lifecycle, data storage or a new request surface. For SolrEngine's own rules and release checklist use solrengine-development; for markup and interaction use solrengine-accessibility.
---

# WordPress plugin best practices

The rules here hold for any well-built plugin. Project rules beat them: in
SolrEngine that is `AGENTS.md`, then the `solrengine-development` skill. Where
this file and those disagree, they win. Worked code for the patterns below is
in [references/patterns.md](references/patterns.md). Read the section you need
rather than the whole file.

## How to think about a WordPress plugin

- **A plugin is a guest in someone else's process.** It shares one PHP request,
  one global namespace, one database and one page with core, the theme and
  dozens of other plugins. Every name you put in a shared space needs a prefix.
  Every query you add runs for every visitor. Every notice you trigger shows up
  in somebody else's log.
- **Do nothing at include time.** A plugin file should only declare things and
  register hooks. Real work happens on `plugins_loaded`, `init`,
  `admin_init`, `rest_api_init` or later. Work done while the file loads runs
  before translations, before other plugins are available, and on every request.
- **Every request type is different.** Front end, admin, REST, admin-ajax,
  WP-Cron, WP-CLI and `SHORTINIT` load different things. Check with
  `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()`,
  `defined( 'REST_REQUEST' )` and `defined( 'WP_CLI' )`, and only register
  what that request needs. Note that `is_admin()` is true for admin-ajax, so
  it does not mean "the current user is an administrator".
- **Fail soft on the front end.** A visitor should never see a fatal error, a
  stack trace or an internal error message because a dependency is down.
  Return `WP_Error` or a result object, log the detail with credentials
  redacted, and show something generic.

## Security: the four questions every handler answers

Answer each question in the handler itself, in this order, so a reviewer can
see them:

1. **Who is asking?** Check `current_user_can()` against a *capability*, never
   a role. Use meta capabilities for objects: `current_user_can( 'edit_post', $id )`,
   not `'edit_posts'`.
2. **Did they mean to?** A nonce: `check_admin_referer()` for form posts,
   `check_ajax_referer()` for admin-ajax, the `wp_rest` nonce in `X-WP-Nonce`
   for REST cookie auth. The capability check and the nonce answer different
   questions, and a CSRF attack from the admin's own browser passes the
   capability check, so you need both.
3. **What did they send?** Unslash, then sanitise, as early as possible:
   `sanitize_text_field( wp_unslash( $_POST['x'] ) )`. Read the superglobal
   once, right after the nonce check, and pass the values on. Validate against
   an allowlist where one exists (`in_array( $v, $allowed, true )`). Prefer
   `absint`, `sanitize_key`, `sanitize_email`, `sanitize_textarea_field` and
   `esc_url_raw` / `sanitize_url` over hand-written regexes.
4. **What goes back out?** Escape late, at the point of output, using the
   function for that context: `esc_html`, `esc_attr`, `esc_url`,
   `esc_textarea`, `wp_kses` / `wp_kses_post` for allowed markup, and
   `wp_json_encode` for data going into JS. Escaping on input, or once in a
   variable "for later", gets bypassed by the next person who edits the code.

Also:

- **SQL:** every variable goes through `$wpdb->prepare()`. Use `%i` for
  identifiers (WP 6.2+), and `$wpdb->esc_like()` before adding `%` wildcards.
  Prefer core APIs (`WP_Query`, `get_posts`, metadata functions) to raw SQL.
- **Redirects:** use `wp_safe_redirect()` followed by `exit`. Never redirect to
  a URL taken from the request without validating it.
- **Never:** `eval`, `extract`, `unserialize` on anything a user can touch
  (store JSON instead), `$_REQUEST`, or building a file path from request input.
- **Outbound HTTP:** use the `wp_remote_*` functions with an explicit
  `timeout`, check `is_wp_error()` and the response code, and cap the body
  size. Use `wp_safe_remote_*` when the URL is not admin-configured.
- **Files:** write to `wp_upload_dir()`, never into the plugin's own directory,
  which an update wipes. Use `WP_Filesystem` for anything a host may lock down.
- **Secrets:** a `wp-config.php` constant beats an option. If a secret has to
  live in the database, store it encrypted and non-autoloaded. Never return it
  to the browser, put it in a log or include it in an export.

## Hooks

- Prefix every hook name. Document each one with a docblock that has `@since`
  and `@param` (with a `@return` for filters). Hooks are public API: once you
  ship one, you can't rename it.
- A filter callback **must return** its first argument, changed or unchanged.
  An action returns nothing. A filter that forgets to return a value silently
  wipes data for every later callback.
- Pass the number of arguments you actually use as `accepted_args`. Pick
  priorities on purpose and say why in a comment when it isn't 10.
- Anything another developer might want to unhook should be a named method on
  an instance they can reach, not a closure. `remove_action()` needs the same
  callable and the same priority.
- Avoid hooks that run on every request (`init`, `wp_loaded`, `the_content`,
  `option_*`) for expensive work. Do the work later, on a narrower hook.
- Do not pass mutable internals into a filter when a value object or array
  copy would do. Whatever you expose, other code will rely on.

## Data

**Options.** One option holding an array per concern beats twenty scalar
options. Options are autoloaded by default. That suits small values read on
every request. Large values, or values read rarely, should be stored with
autoload off (`add_option( $k, $v, '', false )` /
`update_option( $k, $v, false )`). Use the Settings API (`register_setting`
with `type`, `default` and `sanitize_callback`) for settings screens.

**Transients and the object cache.** A transient is a cache, not storage: it
can disappear at any moment, and with a persistent object cache it never
reaches the database. Never put anything in one that you can't rebuild. Use
`wp_cache_*` with a plugin-specific group to cache within a request. Use
transients or a persistent cache to keep values across requests.

**Post meta vs. custom tables.** Meta is fine for per-post attributes that are
read by ID. Querying by meta value at scale (`meta_query` across thousands of
rows) is slow, because `meta_value` has no useful index. Logs, queues, history
and other high-volume or relational data belong in a custom table. Create it
with `dbDelta()`, which is fussy about format (see patterns), and track its
schema version in an option.

**Queries.** Never use `posts_per_page => -1` on unbounded data. Set
`no_found_rows => true` when you don't paginate, use `fields => 'ids'` when
you only need IDs, and turn off the meta and term cache priming you don't use.
Don't put a query inside a loop. Batch large work by ID ranges, not by offset.

## Lifecycle

- **Activation** (`register_activation_hook( __FILE__, … )`, in the main file
  only): keep it light and idempotent. Create tables, set defaults with
  `add_option` (which never overwrites), and set a flag to flush rewrite rules
  on the next `init` instead of flushing straight away. Activation does **not**
  run on updates, including auto-updates.
- **Upgrades:** compare a stored version option with the code's version early
  in the request, and run idempotent, ordered migrations. Large data migrations
  run in batches in the background, not in the request that noticed the
  version change.
- **Deactivation:** unschedule cron events and flush rewrite rules. Keep the
  data: deactivating is not uninstalling.
- **Uninstall:** `uninstall.php` guarded by `WP_UNINSTALL_PLUGIN`, which
  removes every option, transient, table, cron event, capability and user or
  post meta key the plugin created. On multisite, loop over the sites. Be wary
  of deleting anything that lives outside WordPress, such as remote data or
  shared infrastructure. Ask the owner instead.
- **Dependencies:** declare `Requires at least`, `Requires PHP` and, for hard
  plugin dependencies, `Requires Plugins` in the header. Guard optional
  integrations with `class_exists` / `function_exists` / `did_action()` *after*
  `plugins_loaded`, and keep every call into them behind that guard.

## Request surfaces

- **REST** over admin-ajax for anything new. Namespace with a version
  (`plugin/v1`). Every route has a real `permission_callback`. Use
  `__return_true` only for genuinely public reads, with a comment saying so.
  Declare `args` with `type`, `required`, `sanitize_callback` and
  `validate_callback`, so the input has been checked before your callback runs.
  Return `WP_Error` with a `status`. Never let raw exception text reach the
  response.
- **admin-ajax:** use `wp_ajax_{action}` for logged-in users, and add
  `wp_ajax_nopriv_{action}` only when it's truly public. Check the nonce and
  capability, then reply with `wp_send_json_success` / `_error`, which exit.
- **Admin pages:** add them on `admin_menu` / `network_admin_menu` with a
  capability. Handle form posts on `admin_post_{action}`, then redirect back
  (Post/Redirect/Get) with a notice.
- **WP-CLI:** register commands only when `WP_CLI` is defined. A command calls
  the same domain class the admin screen does: surfaces differ in how they
  start up and read input, not in behaviour.
- **No new direct entry points.** A PHP file loaded straight over HTTP skips
  every WordPress control. If you think you need one, stop and ask.

## Background work

WP-Cron runs only when someone visits the site, and only after the response is
sent. On low-traffic sites it is late, and on sites with `DISABLE_WP_CRON` it
depends on a system cron. Always check `wp_next_scheduled()` before
`wp_schedule_event()`. Register custom intervals through `cron_schedules`.
For queues, retries and large batches, use Action Scheduler: it logs, retries,
and doesn't drop jobs. Every job must be safe to run twice and must stay well
inside the request timeout.

## Assets

- Register and enqueue, never print `<script>` or `<link>` tags. Load assets
  only where they're used: check `$hook_suffix` in `admin_enqueue_scripts`,
  and the condition on the front end.
- Use the `.asset.php` file from `@wordpress/scripts` for dependencies and the
  version. Pass `array( 'strategy' => 'defer', 'in_footer' => true )` for
  non-blocking scripts (WP 6.3+).
- Pass data with `wp_add_inline_script( $handle, 'const x = ' . wp_json_encode( $data ) . ';', 'before' )`.
  `wp_localize_script()` turns every scalar into a string.
- Never deregister core's copies of libraries, and never load your own copy
  of jQuery or React. Use the WordPress-provided handles.

## Internationalisation

- Each string is a literal with the literal text domain:
  `__( 'Saved.', 'plugin-slug' )`. Never use variables or concatenated strings
  inside `__()`.
- Use placeholders with `sprintf` and a `/* translators: */` comment on the
  line before. Use `_n()` for plurals and `_x()` when the same word needs
  context.
- Don't translate before `init`. Since WP 6.7, loading translations earlier
  triggers a `_load_textdomain_just_in_time` notice. Plugins hosted on
  WordPress.org don't need `load_plugin_textdomain()`.
- In JS, use `@wordpress/i18n` together with `wp_set_script_translations()`.
- Escape translated output too: `esc_html__`, `esc_attr__`, `esc_html_e`.

## Multisite

- Per-site data uses `get_option`. Network-wide data uses `get_site_option` /
  `get_network_option`. Decide which one each setting is, on purpose.
- A network-activated plugin is active on sites that were never set up for it.
  Set up new sites on `wp_initialize_site` and clean up on `wp_uninitialize_site`.
  Initialise lazily rather than looping over every site at activation.
- `switch_to_blog()` is expensive and does not switch the loaded plugins.
  Always pair it with `restore_current_blog()`, and don't call it inside a
  per-request loop.
- Capabilities change: `manage_network_options` for network screens, and
  `unfiltered_html` is super-admin only.

## Blocks

Register blocks from `block.json` with `register_block_type( $dir )`. Dynamic
blocks render in PHP. Escape everything in the render file and use
`get_block_wrapper_attributes()` on the root element. Attributes are user
input stored in post content: validate them in the render callback, because
the editor's controls aren't the only way to set them. Changing a block's saved
markup breaks existing posts unless you add a `deprecated` entry.

## Testing

- Keep domain logic free of WordPress so it unit-tests in milliseconds without
  WordPress loaded. If a unit test needs `get_option()`, the dependency belongs
  behind an interface.
- Use integration tests (`WP_UnitTestCase` and its factories) for anything
  that depends on hooks, queries, capabilities or the database. Test security
  checks directly: a subscriber, a missing nonce, a wrong-site ID.
- Run with `WP_DEBUG`, `WP_DEBUG_LOG` and `SCRIPT_DEBUG` on. Any notice,
  deprecation or `_doing_it_wrong` your change causes is a bug.
- A skipped test is not a passing test. Read the count.

## Before calling it done

- [ ] Every new global name is prefixed. Every new string is translatable with the right domain.
- [ ] Every handler checks capability and nonce, sanitises input and escapes output.
- [ ] Every `$wpdb` call with a variable uses `prepare()`.
- [ ] No new work at include time, on every request, or in a loop.
- [ ] Anything the plugin creates is removed by uninstall.
- [ ] Anything that is now public API (hook, route, template, option others may read) is documented with `@since`.
- [ ] The project's own checks pass (`composer check` and friends in SolrEngine).
