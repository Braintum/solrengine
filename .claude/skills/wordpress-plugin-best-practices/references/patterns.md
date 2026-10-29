# Patterns

Reference implementations for the rules in `SKILL.md`. `myplugin` / `MyPlugin`
stand in for the project's prefix (in SolrEngine: `solrengine` /
`SolrEngine`). Each snippet targets PHP 7.4 and WordPress 6.5 or later.

## Contents

- [Admin form handler](#admin-form-handler)
- [REST route](#rest-route)
- [admin-ajax handler](#admin-ajax-handler)
- [Prepared SQL](#prepared-sql)
- [Custom table with dbDelta](#custom-table-with-dbdelta)
- [Upgrade routine](#upgrade-routine)
- [Activation and deactivation](#activation-and-deactivation)
- [Uninstall on multisite](#uninstall-on-multisite)
- [Cron](#cron)
- [Caching](#caching)
- [Enqueueing with data](#enqueueing-with-data)
- [Translatable strings](#translatable-strings)
- [Escaping in templates](#escaping-in-templates)
- [Documented hook](#documented-hook)
- [Optional integration guard](#optional-integration-guard)

## Admin form handler

The nonce check and the capability check are both written in the handler. The
superglobal is read once, after the nonce check.

```php
add_action( 'admin_post_myplugin_save', array( $this, 'handle_save' ) );

public function handle_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'myplugin' ), 403 );
	}

	check_admin_referer( 'myplugin_save' );

	$input = array(
		'label' => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
		'limit' => isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10,
		'mode'  => isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'auto',
	);

	if ( ! in_array( $input['mode'], array( 'auto', 'manual' ), true ) ) {
		$input['mode'] = 'auto';
	}

	$this->settings->save( $input );

	wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=myplugin' ) ) );
	exit;
}
```

The form:

```php
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="myplugin_save" />
	<?php wp_nonce_field( 'myplugin_save' ); ?>
	…
</form>
```

## REST route

`args` validates and sanitises before the callback runs. The permission
callback is real. Errors carry a status and a stable code, never internal
detail.

```php
add_action( 'rest_api_init', array( $this, 'register_routes' ) );

public function register_routes() {
	register_rest_route(
		'myplugin/v1',
		'/items',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_items' ),
			// Public read: returns only published content.
			'permission_callback' => '__return_true',
			'args'                => array(
				'q'        => array(
					'type'              => 'string',
					'required'          => true,
					'minLength'         => 1,
					'maxLength'         => 200,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
				),
			),
		)
	);

	register_rest_route(
		'myplugin/v1',
		'/items/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'update_item' ),
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return current_user_can( 'edit_post', (int) $request['id'] );
			},
		)
	);
}

public function get_items( WP_REST_Request $request ) {
	$result = $this->service->search( $request['q'], $request['per_page'] );

	if ( ! $result->is_ok() ) {
		return new WP_Error( 'myplugin_unavailable', __( 'Search is unavailable.', 'myplugin' ), array( 'status' => 503 ) );
	}

	return rest_ensure_response( $result->value() );
}
```

From JS, `@wordpress/api-fetch` sends the `wp_rest` nonce for you once
`wpApiSettings` (or `apiFetch.createNonceMiddleware`) is set up.

## admin-ajax handler

Use this only where REST doesn't fit. `wp_send_json_*` exits.

```php
add_action( 'wp_ajax_myplugin_dismiss', array( $this, 'dismiss' ) );

public function dismiss() {
	check_ajax_referer( 'myplugin_dismiss' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( null, 403 );
	}

	update_user_meta( get_current_user_id(), 'myplugin_dismissed', 1 );
	wp_send_json_success();
}
```

## Prepared SQL

```php
global $wpdb;

$table = $wpdb->prefix . 'myplugin_log';
$like  = '%' . $wpdb->esc_like( $term ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, result cached by caller.
$rows = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT id, message FROM %i WHERE message LIKE %s AND created_at > %s ORDER BY id DESC LIMIT %d',
		$table,
		$like,
		$since,
		$limit
	)
);
```

An `IN ()` list builds its placeholders, never its values:

```php
$ids          = array_map( 'absint', $ids );
$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ( $placeholders )", $ids );
```

## Custom table with dbDelta

`dbDelta()` parses the SQL with regexes. It needs one column per line,
`PRIMARY KEY` followed by **two spaces**, `KEY` rather than `INDEX`, lowercase
types and no `IF NOT EXISTS`. Get the format wrong and it silently does
nothing, or adds a duplicate index on every run.

```php
public static function install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = $wpdb->prefix . 'myplugin_log';
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object_id (object_id),
			KEY created_at (created_at)
		) {$charset};"
	);

	update_option( 'myplugin_db_version', self::DB_VERSION, false );
}
```

## Upgrade routine

Activation doesn't run on update, so check the version every request. The
check is cheap: one autoloaded option read.

```php
add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );

public function maybe_upgrade() {
	$stored = get_option( 'myplugin_db_version', '0' );

	if ( version_compare( $stored, self::DB_VERSION, '>=' ) ) {
		return;
	}

	// Each step is idempotent: safe if two requests race, safe to re-run.
	if ( version_compare( $stored, '1.1.0', '<' ) ) {
		self::install(); // dbDelta adds the new column.
	}
	if ( version_compare( $stored, '1.2.0', '<' ) ) {
		// Large data: queue it, don't do it in this request.
		$this->queue->enqueue( 'myplugin_migrate_1_2_0' );
	}

	update_option( 'myplugin_db_version', self::DB_VERSION, false );
}
```

## Activation and deactivation

```php
// Main plugin file only: __FILE__ must be the plugin's main file.
register_activation_hook( __FILE__, 'myplugin_activate' );
register_deactivation_hook( __FILE__, 'myplugin_deactivate' );

function myplugin_activate() {
	MyPlugin\Schema::install();
	add_option( 'myplugin_settings', array( 'limit' => 10 ) ); // Never overwrites.
	update_option( 'myplugin_flush_rewrites', 1, false );
}

function myplugin_deactivate() {
	wp_clear_scheduled_hook( 'myplugin_daily' );
	flush_rewrite_rules();
}

// Flush once, after the plugin's rewrite rules have been registered on init.
add_action(
	'init',
	static function () {
		if ( get_option( 'myplugin_flush_rewrites' ) ) {
			delete_option( 'myplugin_flush_rewrites' );
			flush_rewrite_rules();
		}
	},
	99
);
```

## Uninstall on multisite

```php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function myplugin_uninstall_site() {
	global $wpdb;

	delete_option( 'myplugin_settings' );
	delete_option( 'myplugin_db_version' );
	delete_transient( 'myplugin_cache' );
	wp_clear_scheduled_hook( 'myplugin_daily' );
	delete_metadata( 'post', 0, '_myplugin_flag', '', true ); // All posts.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'myplugin_log' ) );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		myplugin_uninstall_site();
		restore_current_blog();
	}
	delete_site_option( 'myplugin_network' );
} else {
	myplugin_uninstall_site();
}
```

On a very large network this loop can time out. Note the limit in a comment,
or provide a WP-CLI command for the cleanup.

## Cron

```php
add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		$schedules['myplugin_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every fifteen minutes', 'myplugin' ),
		);
		return $schedules; // A filter always returns.
	}
);

add_action( 'myplugin_sync', array( $this, 'sync' ) );

if ( ! wp_next_scheduled( 'myplugin_sync' ) ) {
	wp_schedule_event( time(), 'myplugin_fifteen_minutes', 'myplugin_sync' );
}
```

Action Scheduler, for queues:

```php
if ( function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( 'myplugin_index_post', array( $post_id ), 'myplugin' ) ) {
	as_enqueue_async_action( 'myplugin_index_post', array( $post_id ), 'myplugin' );
}
```

## Caching

```php
$key   = 'counts_' . md5( wp_json_encode( $args ) );
$value = wp_cache_get( $key, 'myplugin' );

if ( false === $value ) {
	$value = $this->compute_counts( $args );
	wp_cache_set( $key, $value, 'myplugin', 5 * MINUTE_IN_SECONDS );
}
```

To invalidate a whole group without a persistent cache that supports group
flushes, keep a version number in an option and include it in each key.

## Enqueueing with data

```php
add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

public function enqueue( $hook_suffix ) {
	if ( 'toplevel_page_myplugin' !== $hook_suffix ) {
		return;
	}

	$asset = require MYPLUGIN_DIR . 'build/admin.asset.php';

	wp_enqueue_script(
		'myplugin-admin',
		MYPLUGIN_URL . 'build/admin.js',
		$asset['dependencies'],
		$asset['version'],
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	wp_add_inline_script(
		'myplugin-admin',
		'window.myPluginSettings = ' . wp_json_encode(
			array(
				'restUrl' => rest_url( 'myplugin/v1/' ),
				'limit'   => 10, // Stays an integer, unlike wp_localize_script().
			)
		) . ';',
		'before'
	);

	wp_set_script_translations( 'myplugin-admin', 'myplugin', MYPLUGIN_DIR . 'languages' );
}
```

## Translatable strings

```php
printf(
	/* translators: 1: number of posts, 2: index name. */
	esc_html( _n( '%1$s post indexed into %2$s.', '%1$s posts indexed into %2$s.', $count, 'myplugin' ) ),
	esc_html( number_format_i18n( $count ) ),
	esc_html( $index )
);

echo esc_html_x( 'Post', 'content type label', 'myplugin' );
```

Wrong: `__( $label, 'myplugin' )`, `__( 'Found ' . $n . ' items', 'myplugin' )`,
`__( 'Saved.', MYPLUGIN_DOMAIN )`. The string extractor reads literals only.

## Escaping in templates

```php
<a class="myplugin-card" href="<?php echo esc_url( $item['url'] ); ?>"
	data-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
	<h3><?php echo esc_html( $item['title'] ); ?></h3>
	<div class="myplugin-excerpt"><?php echo wp_kses_post( $item['excerpt'] ); ?></div>
	<span><?php esc_html_e( 'Read more', 'myplugin' ); ?></span>
</a>

<script type="application/json" id="myplugin-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
```

`JSON_HEX_TAG` stops a `</script>` inside the data from closing the element.

## Documented hook

```php
/**
 * Filters the post types that are indexed.
 *
 * @since 1.2.0
 *
 * @param string[] $post_types Post type names. Default: public post types.
 * @param int      $site_id    Current site ID.
 * @return string[]
 */
$post_types = (array) apply_filters( 'myplugin_post_types', $post_types, get_current_blog_id() );
```

Cast or validate what comes back from a filter, because other code may return
the wrong type.

## Optional integration guard

```php
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		( new MyPlugin\Integration\WooCommerce() )->register();
	},
	20 // After WooCommerce has loaded.
);
```

Keep every call into the optional plugin inside the class registered there,
so nothing outside it can hit an undefined function on a site without it.
