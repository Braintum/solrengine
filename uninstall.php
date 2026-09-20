<?php
/**
 * Uninstall handler.
 *
 * Runs only when the user deletes the plugin, never on deactivation. Removes
 * everything SolrEngine created in this site's database.
 *
 * Deliberately does NOT delete documents from Solr. The index belongs to the
 * customer's infrastructure, the endpoint may be unreachable, and deleting
 * someone's data from a remote server during an uninstall is not a decision
 * this file should make silently. The admin screen offers an explicit
 * "remove my documents from Solr" action for that.
 *
 * @package SolrEngine
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove SolrEngine data for the current site.
 *
 * @since 0.1.0
 *
 * @return void
 */
function solrengine_uninstall_site() {
	global $wpdb;

	$options = array(
		'solrengine_connection',
		'solrengine_credentials',
		'solrengine_index_config',
		'solrengine_state',
		'solrengine_version',
		'solrengine_installed_at',
		'solrengine_db_version',
		'solrengine_meta_keys',
		// Run state. Each is written by a different part of the indexing
		// pipeline, and each would otherwise survive the plugin's removal and
		// confuse a later reinstall into thinking a run was in progress.
		'solrengine_sync_run',
		'solrengine_sync_run_rebuild',
		'solrengine_rebuild',
		'solrengine_reconciliation',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// The discovered meta-key list is a transient rather than an option, and
	// transients are stored as options with a prefix, so it needs naming twice.
	delete_transient( 'solrengine_meta_keys' );

	// Legacy 0.1.0 prototype options.
	delete_option( 'solrengine_settings' );
	delete_option( 'solrengine_solr_endpoint' );
	delete_option( 'solrengine_commit_on_update' );

	/*
	 * Per-backend credentials. Their option names carry the backend identifier,
	 * and a backend registered by an add-on that has since been removed cannot
	 * be enumerated — so they are matched by prefix. The prefix is a literal
	 * this file owns, not anything from a request.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$solrengine_credential_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'solrengine_credentials_' ) . '%'
		)
	);

	foreach ( (array) $solrengine_credential_options as $solrengine_credential_option ) {
		delete_option( $solrengine_credential_option );
	}

	$tables = array(
		$wpdb->prefix . 'solrengine_index_runs',
		$wpdb->prefix . 'solrengine_failures',
	);

	foreach ( $tables as $table ) {
		// Table names cannot be parameterised, and these are built from the
		// trusted $wpdb prefix rather than from any request input.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	wp_clear_scheduled_hook( 'solrengine_reconcile' );
}

if ( is_multisite() ) {
	$solrengine_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $solrengine_site_ids as $solrengine_site_id ) {
		switch_to_blog( $solrengine_site_id );
		solrengine_uninstall_site();
		restore_current_blog();
	}

	delete_site_option( 'solrengine_network_connection' );
	delete_site_option( 'solrengine_network_state' );
	delete_site_option( 'solrengine_network_credentials' );
} else {
	solrengine_uninstall_site();
}
