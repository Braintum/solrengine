<?php
/**
 * Corrections applied after an update.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

/**
 * Brings a site's stored data in line with the running version.
 *
 * Activation runs once and never again, so anything that has to reach a site
 * that *updated* rather than activated belongs here. {@see Tables} is the same
 * idea for the history tables; this one is for options.
 *
 * Runs on `admin_init` only. Every correction here is something a front-end
 * request must never pay for, and an administrator loading a screen is a request
 * where an extra query does not matter.
 *
 * @since 0.10.2
 */
final class Upgrade {

	/**
	 * Option holding the version these corrections last ran for.
	 *
	 * Written by activation as well, so a fresh install starts up to date.
	 *
	 * @var string
	 */
	public const OPTION_VERSION = 'solrengine_version';

	/**
	 * Apply anything this version needs, once.
	 *
	 * @since 0.10.2
	 *
	 * @return void
	 */
	public static function run(): void {
		$stored = (string) get_option( self::OPTION_VERSION, '' );

		if ( SOLRENGINE_VERSION === $stored ) {
			return;
		}

		self::mark_config_autoloaded();

		update_option( self::OPTION_VERSION, SOLRENGINE_VERSION, false );
	}

	/**
	 * Autoload the option groups every search reads.
	 *
	 * {@see Config::set()} writes them autoloaded now. A site that saved its
	 * settings before that still has them marked otherwise, and would keep paying
	 * a query per group on every search until someone edited a setting.
	 *
	 * `wp_set_options_autoload()` rather than `update_option()`: the latter
	 * returns early when the value is unchanged and never reaches its autoload
	 * argument, so rewriting the value a row already holds does nothing at all.
	 * Verified by watching this run and change no rows before the fix.
	 *
	 * @return void
	 */
	private static function mark_config_autoloaded(): void {
		if ( ! function_exists( 'wp_set_options_autoload' ) ) {
			// WordPress 6.4 and later. Below the plugin's floor, so this is
			// belt and braces; a site without it simply keeps the extra query.
			return;
		}

		wp_set_options_autoload( Config::autoload_options(), true );
	}
}
