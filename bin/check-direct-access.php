<?php
/**
 * Verify that every shipped PHP file refuses direct access.
 *
 * Plugin Check enforces the same rule in CI, but only against a built package
 * on a WordPress install. This answers the same question in a second, locally,
 * as part of `composer check`, so a missing guard is caught before a push.
 *
 * Every PHP file that ships must either guard against direct access or contain
 * nothing that runs when loaded. There are no exceptions: a file meant to be
 * requested directly over HTTP has to load WordPress itself, which the
 * WordPress.org review does not accept.
 *
 * Usage: php bin/check-direct-access.php
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

/*
 * Third-party code. It ships, but its guards are not this project's to enforce,
 * and Plugin Check does not report them either.
 */
$always_skip = array( 'vendor', 'node_modules', 'build' );

/**
 * Paths excluded from the distributed package.
 *
 * Read from .distignore so this cannot disagree with what bin/build-release.sh
 * actually ships.
 *
 * @param string $root Plugin root.
 * @return string[]
 */
function solrengine_distignored( string $root ): array {
	$lines = (array) file( $root . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	$out   = array();

	foreach ( $lines as $line ) {
		$line = trim( (string) $line );

		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}

		$out[] = rtrim( $line, '/' );
	}

	return $out;
}

/**
 * Whether the file refuses to run when loaded directly.
 *
 * @param string $source File contents.
 * @return bool
 */
function solrengine_has_guard( string $source ): bool {
	return 1 === preg_match(
		'/if\s*\(\s*!\s*defined\(\s*[\'"](?:ABSPATH|WPINC|WP_UNINSTALL_PLUGIN)[\'"]\s*\)\s*\)\s*\{?\s*(?:exit|die)/',
		$source
	);
}

/**
 * Whether the file does nothing at all when loaded.
 *
 * A class file has no statements outside its declarations, so requesting it
 * produces an empty response and there is nothing to guard. This mirrors the
 * exemption Plugin Check itself applies, which is why src/ needs no guards, and
 * it also covers the generated *.asset.php files, whose whole body is one
 * `return array( ... );`.
 *
 * Expressed as what may not appear rather than what may: an allowlist of tokens
 * has to be taught every name token each PHP release invents, and gets this
 * wrong silently when it is not.
 *
 * @param string $source File contents.
 * @return bool
 */
function solrengine_is_declaration_only( string $source ): bool {
	$executable = array(
		T_VARIABLE,
		T_ECHO,
		T_PRINT,
		T_REQUIRE,
		T_REQUIRE_ONCE,
		T_INCLUDE,
		T_INCLUDE_ONCE,
		T_IF,
		T_SWITCH,
		T_WHILE,
		T_DO,
		T_FOR,
		T_FOREACH,
		T_TRY,
		T_THROW,
		T_EXIT,
		T_GLOBAL,
		T_UNSET,
		T_EVAL,
		T_GOTO,
	);

	$declares = array( T_NAMESPACE, T_CLASS, T_INTERFACE, T_TRAIT, T_RETURN );

	if ( defined( 'T_ENUM' ) ) {
		$declares[] = T_ENUM;
	}

	$tokens = token_get_all( $source );
	$depth  = 0;
	$seen   = false;

	// Interpolation inside a double-quoted string opens a brace that arrives as
	// T_CURLY_OPEN rather than as "{", while its closing brace arrives as "}".
	// Counting only the literal braces therefore drove the depth negative on
	// every file building SQL with "{$table}", which put method bodies back at
	// depth 0 and failed them all.
	$openers = array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES );

	if ( defined( 'T_ATTRIBUTE' ) ) {
		$openers[] = T_ATTRIBUTE;
	}

	foreach ( $tokens as $token ) {
		if ( is_string( $token ) ) {
			if ( '{' === $token ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;
			}

			continue;
		}

		if ( in_array( $token[0], $openers, true ) ) {
			++$depth;

			continue;
		}

		// Only the top level matters: anything inside a class body is a method,
		// and a method does not run merely because the file was requested.
		if ( 0 !== $depth ) {
			continue;
		}

		if ( in_array( $token[0], $executable, true ) ) {
			return false;
		}

		if ( in_array( $token[0], $declares, true ) ) {
			$seen = true;
		}
	}

	return $seen;
}

$excluded = array_merge( solrengine_distignored( $root ), $always_skip );
$problems = array();
$checked  = 0;

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

foreach ( $files as $file ) {
	if ( ! $file instanceof SplFileInfo || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$relative = ltrim( str_replace( '\\', '/', substr( (string) $file->getPathname(), strlen( $root ) ) ), '/' );
	$skip     = false;

	foreach ( $excluded as $entry ) {
		if ( $relative === $entry || 0 === strpos( $relative, $entry . '/' ) ) {
			$skip = true;
			break;
		}
	}

	if ( $skip ) {
		continue;
	}

	++$checked;

	$source = (string) file_get_contents( (string) $file->getPathname() );

	if ( solrengine_has_guard( $source ) || solrengine_is_declaration_only( $source ) ) {
		continue;
	}

	$problems[] = $relative;
}

if ( array() !== $problems ) {
	fwrite( STDERR, "These shipped PHP files neither guard against direct access nor are declaration-only:\n" );

	foreach ( $problems as $relative ) {
		fwrite( STDERR, '  ' . $relative . "\n" );
	}

	fwrite(
		STDERR,
		"\nAdd: if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "A file meant to answer an HTTP request directly is not an option: see AGENTS.md.\n"
	);

	exit( 1 );
}

printf( "Direct access: %d shipped PHP files checked, no exceptions.\n", $checked );
exit( 0 );
