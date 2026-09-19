<?php
/**
 * Plugin Name:       SolrEngine
 * Plugin URI:        https://www.solrengine.com
 * Description:       Apache Solr powered search for WordPress. Faster, more relevant results, with WordPress search as an automatic fallback.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            SolrEngine
 * Author URI:        https://www.solrengine.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       solrengine
 * Domain Path:       /languages
 *
 * SolrEngine - Apache Solr powered search for WordPress.
 * Copyright (C) 2026 SolrEngine
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * @package SolrEngine
 */

/*
 * This file must parse on every PHP version a user might be running, because it
 * is what tells them their PHP is too old. It therefore uses no syntax newer
 * than PHP 5.6: no type declarations, no short closures, no namespaced code.
 * Everything modern lives behind the requirement gate below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOLRENGINE_VERSION', '0.4.0' );
define( 'SOLRENGINE_PLUGIN_FILE', __FILE__ );
define( 'SOLRENGINE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOLRENGINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SOLRENGINE_MIN_PHP', '7.4' );
define( 'SOLRENGINE_MIN_WP', '6.5' );

/**
 * Schema contract version.
 *
 * Deliberately independent of the plugin version: most releases change nothing
 * about the Solr schema, and coupling the two would force meaningless
 * migrations. See ADR-003.
 */
define( 'SOLRENGINE_SCHEMA_CONTRACT_VERSION', 1 );

/**
 * Collect any unmet requirement.
 *
 * @since 0.1.0
 *
 * @return string[] Human-readable problems. Empty when the plugin can run.
 */
function solrengine_unmet_requirements() {
	$problems = array();

	if ( version_compare( PHP_VERSION, SOLRENGINE_MIN_PHP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required PHP version, 2: running PHP version. */
			__( 'SolrEngine requires PHP %1$s or newer. This site runs PHP %2$s.', 'solrengine' ),
			SOLRENGINE_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), SOLRENGINE_MIN_WP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required WordPress version, 2: running WordPress version. */
			__( 'SolrEngine requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'solrengine' ),
			SOLRENGINE_MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	if ( ! is_readable( SOLRENGINE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
		$problems[] = __( 'SolrEngine is missing its autoloader. If you installed from source, run "composer install" in the plugin directory.', 'solrengine' );
	}

	return $problems;
}

/**
 * Show unmet requirements to administrators instead of fataling.
 *
 * A missing autoloader or an old PHP version must never white-screen the site.
 * See plan Principle 1 and section 11.2.
 *
 * @since 0.1.0
 *
 * @param string[] $problems Problems to display.
 * @return void
 */
function solrengine_render_requirement_notice( $problems ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>';
	echo esc_html__( 'SolrEngine is inactive.', 'solrengine' );
	echo '</strong></p><ul style="list-style:disc;margin-left:20px">';

	foreach ( $problems as $problem ) {
		echo '<li>' . esc_html( $problem ) . '</li>';
	}

	echo '</ul></div>';
}

/**
 * Boot the plugin.
 *
 * Wiring only. Nothing here touches the database, the network or the filesystem;
 * services are constructed lazily when a hook actually fires.
 *
 * @since 0.1.0
 *
 * @return void
 */
function solrengine_boot() {
	$problems = solrengine_unmet_requirements();

	if ( ! empty( $problems ) ) {
		add_action(
			'admin_notices',
			function () use ( $problems ) {
				solrengine_render_requirement_notice( $problems );
			}
		);

		return;
	}

	require_once SOLRENGINE_PLUGIN_DIR . 'vendor/autoload.php';

	/*
	 * Action Scheduler registers itself through a version negotiation, so the
	 * copy that ends up running may belong to another plugin. Requiring our
	 * bundled copy adds it to that negotiation; it does not force it to win.
	 * Guarded because a broken or partial copy must not fatal the site: the
	 * queue reports itself unavailable and SolrEngine falls back to WP-Cron.
	 */
	$solrengine_scheduler = SOLRENGINE_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

	if ( is_readable( $solrengine_scheduler ) ) {
		require_once $solrengine_scheduler;
	}

	\SolrEngine\Bootstrap::instance()->boot();
}
add_action( 'plugins_loaded', 'solrengine_boot' );

/**
 * Activation.
 *
 * Deliberately minimal. Activation runs in a request the user is waiting on, so
 * it must not contact Solr: a firewalled endpoint would turn activation into a
 * timeout. Schema verification and indexing are explicit actions taken later.
 *
 * @since 0.1.0
 *
 * @return void
 */
function solrengine_activate() {
	if ( solrengine_unmet_requirements() ) {
		return;
	}

	if ( false === get_option( 'solrengine_installed_at', false ) ) {
		add_option( 'solrengine_installed_at', time(), '', false );
	}

	update_option( 'solrengine_version', SOLRENGINE_VERSION, false );
}
register_activation_hook( __FILE__, 'solrengine_activate' );

/**
 * Deactivation.
 *
 * Stops scheduled work and leaves data alone. Removing data is uninstall's job,
 * because deactivation is routinely used for debugging.
 *
 * @since 0.1.0
 *
 * @return void
 */
function solrengine_deactivate() {
	wp_clear_scheduled_hook( 'solrengine_reconcile' );
}
register_deactivation_hook( __FILE__, 'solrengine_deactivate' );
