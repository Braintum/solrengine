<?php
/**
 * Plugin Name:       SolrEngine
 * Plugin URI:        https://www.solrengine.com
 * Description:       Apache Solr powered search for WordPress. Faster, more relevant results, with WordPress search as an automatic fallback.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Braintum
 * Author URI:        https://www.braintum.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       solrengine
 * Domain Path:       /languages
 *
 * SolrEngine - Apache Solr powered search for WordPress.
 * Copyright (C) 2026 Braintum
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

// Version, paths and the schema contract version, kept in their own file so
// any other entry point reads the same values rather than redefining them.
require_once __DIR__ . '/constants.php';

define( 'SOLRENGINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Action Scheduler, bundled for background indexing.
 *
 * Required here, while this file loads, and not from solrengine_boot(). Action
 * Scheduler joins its version negotiation on `plugins_loaded` at priorities 0
 * and 1. Required from a callback already running on that hook at priority 10,
 * it registered too late to ever initialise, so on a site with no other plugin
 * bundling it every job silently went to WP-Cron instead.
 *
 * Requiring our copy adds it to the negotiation; it does not force it to win,
 * so the copy that runs may belong to WooCommerce. Guarded on the PHP version,
 * because this file must parse on PHP too old for Action Scheduler and reports
 * that itself, and on the file being readable, because a broken or partial
 * copy must not fatal the site: the queue then reports itself unavailable and
 * SolrEngine falls back to WP-Cron.
 */
if ( version_compare( PHP_VERSION, SOLRENGINE_MIN_PHP, '>=' ) && is_readable( SOLRENGINE_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once SOLRENGINE_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

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

	/*
	 * The two history tables. Creating tables is local work against a database
	 * the request already has open, so it does not break the rule above about
	 * activation staying off the network.
	 *
	 * Activation is not the only path that creates them — a site upgraded rather
	 * than activated never runs this — so the code that writes a run record
	 * checks as well. This is the eager half, so an administrator who activates
	 * and looks at the Tools screen sees an empty history rather than a notice
	 * saying history is unavailable.
	 */
	require_once SOLRENGINE_PLUGIN_DIR . 'vendor/autoload.php';

	\SolrEngine\Support\Tables::install();
}
register_activation_hook( __FILE__, 'solrengine_activate' );

/**
 * Deactivation.
 *
 * Stops queued work and leaves data alone. Removing data is uninstall's job,
 * because deactivation is routinely used for debugging. See
 * SolrEngine\Support\Lifecycle::deactivate() for what is stopped and why.
 *
 * @since 0.1.0
 *
 * @return void
 */
function solrengine_deactivate() {
	if ( solrengine_unmet_requirements() ) {
		// The autoloader may be missing or this PHP too old to parse it. Clear
		// the one recurring event by name and leave the rest.
		wp_clear_scheduled_hook( 'solrengine_reconcile' );

		return;
	}

	require_once SOLRENGINE_PLUGIN_DIR . 'vendor/autoload.php';

	\SolrEngine\Support\Lifecycle::deactivate();
}
register_deactivation_hook( __FILE__, 'solrengine_deactivate' );
