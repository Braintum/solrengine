<?php
/**
 * Generate docs/hooks.md from the docblocks above each hook.
 *
 * The plan's section 24.1 states the rule this exists to keep: "the hook
 * reference is generated from PHPDoc rather than hand-maintained, so it cannot
 * drift". A hand-written hook reference is wrong within two releases — the
 * signature changes, the @since stays, and an integrator reads the wrong thing
 * with no way to tell.
 *
 * It does two jobs, and the second is the more useful one:
 *
 * 1. Writes the reference.
 * 2. Refuses to, and says why, when a hook has no docblock, no @since, or
 *    parameters the docblock does not describe. Hooks are public API from 1.0,
 *    so an undocumented one is an API with no contract.
 *
 * Usage:
 *     php bin/generate-hooks.php            write docs/hooks.md
 *     php bin/generate-hooks.php --check    verify it is current, write nothing
 *
 * Runs standalone. No WordPress, no Composer autoloader, because CI runs it
 * before either is guaranteed to be there.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script runs on the command line only.\n" );
	exit( 1 );
}

/**
 * Hooks whose names are not ours, and are called rather than fired.
 *
 * WPML's filter API is read this way. Prefixing them would call hooks that do
 * not exist, so they are consumed under their own names and excluded here.
 */
const SOLRENGINE_OWN_PREFIX = 'solrengine_';

/**
 * Areas a hook is grouped under, keyed by the path fragment that identifies one.
 *
 * Ordered for a reader rather than alphabetically: the order somebody meets
 * these features in.
 */
const SOLRENGINE_AREAS = array(
	'Bootstrap.php' => 'Startup',
	'Documents/'    => 'What gets indexed',
	'Indexing/'     => 'Indexing',
	'Search/'       => 'Searching',
	'Integration/'  => 'Integration with WordPress',
	'Language/'     => 'Languages',
	'Rest/'         => 'The REST API',
);

/**
 * Find every hook fired under src/.
 *
 * @param string $root Plugin root directory.
 * @return array<int,array<string,mixed>>
 */
function solrengine_collect_hooks( string $root ): array {
	$found = array();

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $files as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$relative = 'src' . substr( (string) $file->getPathname(), strlen( $root . '/src' ) );

		foreach ( solrengine_hooks_in( (string) $file->getPathname(), $relative ) as $hook ) {
			$found[] = $hook;
		}
	}

	usort(
		$found,
		static function ( array $a, array $b ): int {
			return $a['name'] <=> $b['name'];
		}
	);

	return solrengine_merge_by_name( $found );
}

/**
 * Collapse a hook fired from more than one place into one entry.
 *
 * An integrator wants one entry per hook, not one per call site. A hook
 * legitimately has several — a filter applied on two code paths — and the
 * reference should say where without saying it twice.
 *
 * The merge is also a check. Two call sites with docblocks that disagree about
 *
 * @since or about how many parameters they pass is drift of the worst kind: both
 * look documented, and whichever the reader finds first is the one they trust.
 *
 * @param array<int,array<string,mixed>> $hooks Hooks in name order.
 * @return array<int,array<string,mixed>>
 */
function solrengine_merge_by_name( array $hooks ): array {
	$merged = array();

	foreach ( $hooks as $hook ) {
		$name = (string) $hook['name'];

		if ( ! isset( $merged[ $name ] ) ) {
			$hook['sites'] = array( sprintf( '%s:%d', $hook['file'], $hook['line'] ) );

			$merged[ $name ] = $hook;

			continue;
		}

		$merged[ $name ]['sites'][] = sprintf( '%s:%d', $hook['file'], $hook['line'] );

		$first = $merged[ $name ];

		$since_of  = static function ( array $entry ): string {
			return null === $entry['doc'] ? '' : (string) $entry['doc']['since'];
		};
		$params_of = static function ( array $entry ): int {
			return null === $entry['doc'] ? -1 : count( $entry['doc']['params'] );
		};

		if ( $since_of( $first ) !== $since_of( $hook ) || $params_of( $first ) !== $params_of( $hook ) ) {
			$merged[ $name ]['disagrees'] = true;
		}
	}

	return array_values( $merged );
}

/**
 * Find the hooks in one file.
 *
 * Tokenised rather than matched with a regular expression. A hook name appears
 * in strings, in comments and in this very script, and a regular expression
 * cannot tell those from a call — nor reliably find the docblock above one.
 *
 * @param string $path     Absolute file path.
 * @param string $relative Path to show in the reference.
 * @return array<int,array<string,mixed>>
 */
function solrengine_hooks_in( string $path, string $relative ): array {
	$source = (string) file_get_contents( $path );
	$tokens = token_get_all( $source );
	$hooks  = array();

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		$kind = 'do_action' === $token[1] ? 'action' : ( 'apply_filters' === $token[1] ? 'filter' : '' );

		if ( '' === $kind ) {
			continue;
		}

		$name = solrengine_hook_name( $tokens, $index );

		if ( null === $name || 0 !== strpos( $name, SOLRENGINE_OWN_PREFIX ) ) {
			continue;
		}

		$hooks[] = array(
			'name' => $name,
			'kind' => $kind,
			'file' => $relative,
			'line' => (int) $token[2],
			'doc'  => solrengine_docblock_before( $tokens, $index ),
			'area' => solrengine_area_of( $relative ),
			'args' => solrengine_argument_count( $tokens, $index ),
		);
	}

	return $hooks;
}

/**
 * The hook name a call names, or null when it is not a literal.
 *
 * A hook assembled from a variable cannot be documented, and SolrEngine does not
 * have one. Returning null rather than guessing means such a hook is reported as
 * undocumented instead of silently omitted.
 *
 * @param array<int,mixed> $tokens Token list.
 * @param int              $index  Index of the function name.
 * @return string|null
 */
function solrengine_hook_name( array $tokens, int $index ): ?string {
	$count = count( $tokens );

	for ( $i = $index + 1; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			continue;
		}

		if ( '(' === $token ) {
			continue;
		}

		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			return trim( $token[1], "'\"" );
		}

		return null;
	}

	return null;
}

/**
 * How many arguments a hook passes, not counting its own name.
 *
 * Counted so the reference can insist that the docblock describes what is
 * actually passed. That is the check worth having: a hook documenting two
 * parameters while passing three is worse than one documenting none, because a
 * reader trusts it and writes a callback with the wrong signature.
 *
 * @param array<int,mixed> $tokens Token list.
 * @param int              $index  Index of the function name.
 * @return int
 */
function solrengine_argument_count( array $tokens, int $index ): int {
	$count = count( $tokens );
	$depth = 0;
	$args  = 0;

	for ( $i = $index + 1; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) ) {
			continue;
		}

		if ( '(' === $token || '[' === $token ) {
			++$depth;

			continue;
		}

		if ( ')' === $token || ']' === $token ) {
			--$depth;

			if ( 0 === $depth ) {
				// The call has closed. One argument is the hook name itself,
				// so a bare do_action( 'x' ) has none to document.
				return $args;
			}

			continue;
		}

		if ( ',' === $token && 1 === $depth ) {
			++$args;
		}
	}

	return $args;
}

/**
 * The docblock immediately above a hook call, or null.
 *
 * Walks back over the expression the call sits inside — an assignment, a return,
 * a cast, a condition — and stops at a statement boundary. Stopping matters: a
 * hook with no docblock of its own must not inherit the docblock of the method
 * containing it, which would look documented and describe something else.
 *
 * @param array<int,mixed> $tokens Token list.
 * @param int              $index  Index of the function name.
 * @return array<string,mixed>|null
 */
function solrengine_docblock_before( array $tokens, int $index ): ?array {
	for ( $i = $index - 1; $i >= 0; $i-- ) {
		$token = $tokens[ $i ];

		if ( is_string( $token ) ) {
			if ( ';' === $token || '{' === $token || '}' === $token ) {
				return null;
			}

			continue;
		}

		if ( T_DOC_COMMENT === $token[0] ) {
			return solrengine_parse_docblock( (string) $token[1] );
		}

		if ( T_COMMENT === $token[0] ) {
			// An ordinary comment between the docblock and the call is fine.
			continue;
		}
	}

	return null;
}

/**
 * Split a docblock into the parts the reference renders.
 *
 * @param string $raw Raw docblock.
 * @return array<string,mixed>
 */
function solrengine_parse_docblock( string $raw ): array {
	$lines = preg_split( '/\R/', $raw );
	$body  = array();
	$since = '';
	$args  = array();

	foreach ( (array) $lines as $line ) {
		$line = (string) preg_replace( '#^\s*(/\*\*|\*/|\*)\s?#', '', (string) $line );
		$line = rtrim( $line );

		if ( 1 === preg_match( '/^\s*@since\s+(\S+)/', $line, $matches ) ) {
			$since = $matches[1];

			continue;
		}

		if ( 1 === preg_match( '/^\s*@param\s+(\S+)\s+(\$\S+)\s*(.*)$/', $line, $matches ) ) {
			$args[] = array(
				'type'        => $matches[1],
				'name'        => $matches[2],
				'description' => trim( $matches[3] ),
			);

			continue;
		}

		if ( 1 === preg_match( '/^\s*@/', $line ) ) {
			continue;
		}

		$body[] = $line;
	}

	// Trim blank lines from both ends without collapsing the paragraph breaks
	// in the middle, which carry the structure of the explanation.
	while ( array() !== $body && '' === trim( (string) $body[0] ) ) {
		array_shift( $body );
	}

	while ( array() !== $body && '' === trim( (string) end( $body ) ) ) {
		array_pop( $body );
	}

	return array(
		'summary'     => $body[0] ?? '',
		'description' => implode( "\n", array_slice( $body, 1 ) ),
		'since'       => $since,
		'params'      => $args,
	);
}

/**
 * Which area of the reference a file belongs to.
 *
 * @param string $relative Relative path.
 * @return string
 */
function solrengine_area_of( string $relative ): string {
	foreach ( SOLRENGINE_AREAS as $fragment => $area ) {
		if ( false !== strpos( $relative, $fragment ) ) {
			return $area;
		}
	}

	return 'Other';
}

/**
 * Problems that stop a reference being written.
 *
 * @param array<int,array<string,mixed>> $hooks Hooks found.
 * @return string[]
 */
function solrengine_problems( array $hooks ): array {
	$problems = array();

	foreach ( $hooks as $hook ) {
		$where = sprintf( '%s (%s:%d)', $hook['name'], $hook['file'], $hook['line'] );

		if ( null === $hook['doc'] ) {
			$problems[] = $where . ' has no docblock. Hooks are public API; an undocumented one has no contract.';

			continue;
		}

		if ( '' === $hook['doc']['summary'] ) {
			$problems[] = $where . ' has a docblock with no summary line.';
		}

		if ( '' === $hook['doc']['since'] ) {
			$problems[] = $where . ' has no @since. An integrator cannot tell which release to require.';
		}

		if ( ! empty( $hook['disagrees'] ) ) {
			$problems[] = sprintf(
				'%s is fired from %s with docblocks that disagree. Both look documented, and a reader trusts whichever they find first.',
				$hook['name'],
				implode( ' and ', $hook['sites'] )
			);
		}

		$documented = count( $hook['doc']['params'] );

		if ( $documented !== $hook['args'] ) {
			// Compared against the call, not merely required to exist. A hook
			// documenting two parameters while passing three is worse than one
			// documenting none: a reader trusts it and writes the wrong
			// signature. A hook passing nothing needs no @param at all.
			$problems[] = sprintf(
				'%s passes %d argument(s) and documents %d.',
				$where,
				$hook['args'],
				$documented
			);
		}
	}

	return $problems;
}

/**
 * Render the reference.
 *
 * @param array<int,array<string,mixed>> $hooks Hooks found.
 * @return string
 */
function solrengine_render( array $hooks ): string {
	$by_area = array();

	foreach ( $hooks as $hook ) {
		$by_area[ $hook['area'] ][] = $hook;
	}

	$ordered = array();

	foreach ( SOLRENGINE_AREAS as $area ) {
		if ( isset( $by_area[ $area ] ) ) {
			$ordered[ $area ] = $by_area[ $area ];
		}
	}

	foreach ( $by_area as $area => $list ) {
		if ( ! isset( $ordered[ $area ] ) ) {
			$ordered[ $area ] = $list;
		}
	}

	$out = array(
		'# Hooks',
		'',
		'Every action and filter SolrEngine fires, with its signature and the release',
		'it arrived in. Each one is public API from 1.0.',
		'',
		'<!--',
		'  GENERATED FILE. Do not edit.',
		'',
		'  Written from the docblocks above each hook by bin/generate-hooks.php, so',
		'  that a signature and its documentation cannot drift apart. Change the',
		'  docblock and regenerate:',
		'',
		'      php bin/generate-hooks.php',
		'-->',
		'',
		sprintf( '%d hooks: %d actions and %d filters.', count( $hooks ), solrengine_count( $hooks, 'action' ), solrengine_count( $hooks, 'filter' ) ),
		'',
	);

	foreach ( $ordered as $area => $list ) {
		$out[] = '- [' . $area . '](#' . strtolower( str_replace( ' ', '-', $area ) ) . ')';
	}

	foreach ( $ordered as $area => $list ) {
		$out[] = '';
		$out[] = '## ' . $area;

		foreach ( $list as $hook ) {
			$out[] = '';
			$out[] = '### `' . $hook['name'] . '`';
			$out[] = '';
			$sites = array();

			foreach ( (array) $hook['sites'] as $site ) {
				$sites[] = '`' . $site . '`';
			}

			$out[] = sprintf(
				'%s · since %s · %s',
				'action' === $hook['kind'] ? 'Action' : 'Filter',
				$hook['doc']['since'],
				implode( ', ', $sites )
			);
			$out[] = '';
			$out[] = $hook['doc']['summary'];

			if ( '' !== trim( (string) $hook['doc']['description'] ) ) {
				$out[] = '';
				$out[] = trim( (string) $hook['doc']['description'] );
			}

			if ( array() !== $hook['doc']['params'] ) {
				$out[] = '';
				$out[] = '| Parameter | Type | Description |';
				$out[] = '| --- | --- | --- |';

				foreach ( $hook['doc']['params'] as $param ) {
					$out[] = sprintf(
						'| `%s` | `%s` | %s |',
						$param['name'],
						$param['type'],
						'' !== $param['description'] ? $param['description'] : '—'
					);
				}
			}
		}
	}

	$out[] = '';

	return implode( "\n", $out );
}

/**
 * How many hooks of one kind there are.
 *
 * @param array<int,array<string,mixed>> $hooks Hooks found.
 * @param string                         $kind  "action" or "filter".
 * @return int
 */
function solrengine_count( array $hooks, string $kind ): int {
	$count = 0;

	foreach ( $hooks as $hook ) {
		if ( $kind === $hook['kind'] ) {
			++$count;
		}
	}

	return $count;
}

$solrengine_root   = dirname( __DIR__ );
$solrengine_target = $solrengine_root . '/docs/hooks.md';
$solrengine_check  = in_array( '--check', $argv, true );
$solrengine_hooks  = solrengine_collect_hooks( $solrengine_root );

if ( array() === $solrengine_hooks ) {
	fwrite( STDERR, "No hooks found. That is almost certainly a bug in this script.\n" );
	exit( 1 );
}

$solrengine_problems = solrengine_problems( $solrengine_hooks );

if ( array() !== $solrengine_problems ) {
	fwrite( STDERR, "Hooks are not fully documented:\n\n" );

	foreach ( $solrengine_problems as $solrengine_problem ) {
		fwrite( STDERR, '  - ' . $solrengine_problem . "\n" );
	}

	fwrite( STDERR, "\nFix the docblocks rather than this script: the reference is generated from them.\n" );
	exit( 1 );
}

$solrengine_rendered = solrengine_render( $solrengine_hooks );

if ( $solrengine_check ) {
	$solrengine_current = is_readable( $solrengine_target ) ? (string) file_get_contents( $solrengine_target ) : '';

	if ( $solrengine_current !== $solrengine_rendered ) {
		fwrite( STDERR, "docs/hooks.md is out of date. Run: php bin/generate-hooks.php\n" );
		exit( 1 );
	}

	fwrite( STDOUT, sprintf( "docs/hooks.md is current (%d hooks).\n", count( $solrengine_hooks ) ) );
	exit( 0 );
}

file_put_contents( $solrengine_target, $solrengine_rendered );

fwrite( STDOUT, sprintf( "Wrote docs/hooks.md from %d hooks.\n", count( $solrengine_hooks ) ) );
