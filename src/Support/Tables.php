<?php
/**
 * Custom table definitions.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

/**
 * The two tables SolrEngine owns, and their installation.
 *
 * Two tables rather than options, and the reasoning is the plan's section 17.5:
 * a log is exactly the shape that grows without bound, and an autoloaded option
 * that grows without bound is one of the most common causes of WordPress
 * performance collapse. Run history and per-object failures are logs.
 *
 * Times are stored as Unix timestamps in bigint columns, not as datetime. A
 * datetime column carries no timezone, so every reader has to know which zone
 * the writer meant, and WordPress has three plausible answers. An integer has
 * one meaning everywhere.
 *
 * Installation is lazy. Activation installs the tables eagerly, but a site that
 * was upgraded rather than activated never runs that hook, so the first write
 * checks as well. The check is one non-autoloaded option read, and only on a
 * request that is already recording a run.
 *
 * @since 0.8.0
 */
final class Tables {

	/**
	 * Option holding the installed table version.
	 *
	 * @var string
	 */
	public const OPTION_VERSION = 'solrengine_db_version';

	/**
	 * Current table layout version.
	 *
	 * Bumped when a column changes. Independent of both the plugin version and
	 * the schema contract version, for the same reason those are independent of
	 * each other: most releases change none of them.
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * Whether this request has already verified the tables.
	 *
	 * @var bool
	 */
	private static $verified = false;

	/**
	 * Whether both tables were found, once this request has looked.
	 *
	 * @var bool|null
	 */
	private static $present = null;

	/**
	 * The index-run table name, with the site prefix.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	public static function runs(): string {
		global $wpdb;

		return $wpdb->prefix . 'solrengine_index_runs';
	}

	/**
	 * The failure table name, with the site prefix.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	public static function failures(): string {
		global $wpdb;

		return $wpdb->prefix . 'solrengine_failures';
	}

	/**
	 * Install or update the tables if they are not current.
	 *
	 * @since 0.8.0
	 *
	 * @param bool $force Install even if the version matches.
	 * @return void
	 */
	public static function ensure( bool $force = false ): void {
		if ( self::$verified && ! $force ) {
			return;
		}

		self::$verified = true;

		if ( ! $force && (int) get_option( self::OPTION_VERSION, 0 ) === self::VERSION ) {
			return;
		}

		self::install();

		self::$present = null;
	}

	/**
	 * Create the tables.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$runs    = self::runs();
		$fails   = self::failures();

		/*
		 * dbDelta is particular about formatting: two spaces after PRIMARY KEY,
		 * one field per line, KEY definitions last. Deviating from it makes
		 * dbDelta re-issue ALTER statements on every call, forever.
		 */
		dbDelta(
			"CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site_id bigint(20) unsigned NOT NULL DEFAULT 0,
				kind varchar(20) NOT NULL DEFAULT '',
				state varchar(20) NOT NULL DEFAULT '',
				target varchar(120) NOT NULL DEFAULT '',
				started_at bigint(20) unsigned NOT NULL DEFAULT 0,
				finished_at bigint(20) unsigned NOT NULL DEFAULT 0,
				processed bigint(20) unsigned NOT NULL DEFAULT 0,
				written bigint(20) unsigned NOT NULL DEFAULT 0,
				removed bigint(20) unsigned NOT NULL DEFAULT 0,
				failed bigint(20) unsigned NOT NULL DEFAULT 0,
				total bigint(20) unsigned NOT NULL DEFAULT 0,
				message varchar(500) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY site_started (site_id,started_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$fails} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL DEFAULT 0,
				site_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(40) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				message varchar(500) NOT NULL DEFAULT '',
				created_at bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY run (run_id)
			) {$charset};"
		);

		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	/**
	 * Whether both tables are present.
	 *
	 * Used by the admin screens, which would rather say "history is
	 * unavailable" than render an empty list that looks like "nothing has ever
	 * run".
	 *
	 * @since 0.8.0
	 *
	 * @return bool
	 */
	public static function exist(): bool {
		global $wpdb;

		if ( null !== self::$present ) {
			return self::$present;
		}

		self::$present = false;

		foreach ( array( self::runs(), self::failures() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( (string) $found !== $table ) {
				return false;
			}
		}

		self::$present = true;

		return true;
	}
}
