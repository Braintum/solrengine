<?php
/**
 * Front-end templates a theme can override.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WordPress;

/**
 * Finds and renders SolrEngine's templates, preferring the theme's copy.
 *
 * The convention WooCommerce made familiar: copy a file from the plugin's
 * `templates/` directory into a `solrengine/` directory in the theme, keeping
 * its relative path, and edit the copy. A child theme wins over its parent,
 * and both win over the plugin. Nothing has to be registered.
 *
 * Template names are checked against a strict pattern before anything is
 * looked up. Names come from this plugin's own code, but one of them is built
 * from a post type, and a path assembled from data is a path that should be
 * proven harmless rather than assumed so.
 *
 * @since 0.11.0
 */
final class Templates {

	/**
	 * Directory inside a theme that holds overrides.
	 *
	 * @var string
	 */
	public const THEME_DIR = 'solrengine';

	/**
	 * A relative template path: lowercase segments, ending in .php.
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '#^[a-z0-9_-]+(/[a-z0-9_-]+)*\.php$#';

	/**
	 * The file a template name resolves to.
	 *
	 * @since 0.11.0
	 *
	 * @param string $name Relative path, such as "search-modal/result.php".
	 * @return string Absolute path, or '' when there is no such template.
	 */
	public static function locate( string $name ): string {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			return '';
		}

		$found = locate_template( array( self::THEME_DIR . '/' . $name ), false, false );

		if ( '' === $found ) {
			$bundled = SOLRENGINE_PLUGIN_DIR . 'templates/' . $name;
			$found   = is_readable( $bundled ) ? $bundled : '';
		}

		/**
		 * Filters the file a SolrEngine template resolves to.
		 *
		 * For a plugin, rather than a theme, that wants to supply a template:
		 * a theme needs no hook, because a file at
		 * `{theme}/solrengine/{name}` is found on its own. Return '' to
		 * suppress a template altogether — a per-post-type card, for instance,
		 * so that type falls back to the default card.
		 *
		 * @since 0.11.0
		 *
		 * @param string $path Absolute path found, or '' when none was.
		 * @param string $name Relative template name.
		 */
		$path = apply_filters( 'solrengine_template', $found, $name );

		return is_string( $path ) && '' !== $path && is_readable( $path ) ? $path : '';
	}

	/**
	 * Render a template to a string.
	 *
	 * Through `load_template()`, so a template receives `$args` exactly as one
	 * loaded by `get_template_part()` does, and the theme's globals are in
	 * scope as a theme author expects.
	 *
	 * @since 0.11.0
	 *
	 * @param string              $name Relative template name.
	 * @param array<string,mixed> $args Values the template may read as `$args`.
	 * @return string Rendered markup, or '' when there is no such template.
	 */
	public static function render( string $name, array $args = array() ): string {
		$path = self::locate( $name );

		if ( '' === $path ) {
			return '';
		}

		ob_start();
		load_template( $path, false, $args );

		return (string) ob_get_clean();
	}
}
