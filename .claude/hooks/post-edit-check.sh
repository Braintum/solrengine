#!/usr/bin/env bash
#
# PostToolUse hook: check the file Claude just wrote.
#
# Runs the part of `composer check` that applies to one file, so a mistake is
# reported on the edit that made it rather than at the end of a session:
#
#   PHP              php -l, phpcs on the file, the direct-access guard
#   src/ PHP         + phpstan on the file, the unit suite, the hook reference
#   tests/Unit PHP   + the unit suite
#   assets/src JS    eslint (wp-scripts lint-js) on the file
#   assets/src CSS   stylelint (wp-scripts lint-style) on the file
#   docs/hooks.md    refused: it is generated
#
# Exit 2 hands stderr back to Claude as feedback. The edit itself stays; the
# point is that the next edit fixes it. Anything outside the plugin, and
# anything vendored or built, is ignored.
#
# Set SOLRENGINE_HOOK_SKIP=1 in the environment to switch it off.

set -u

[ "${SOLRENGINE_HOOK_SKIP:-}" = "1" ] && exit 0

ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"

input="$(cat)"
file="$(printf '%s' "$input" | jq -r '.tool_input.file_path // .tool_response.filePath // empty' 2>/dev/null)"

[ -n "$file" ] || exit 0
[ -f "$file" ] || exit 0

# Resolve to a real path so a symlinked checkout still matches ROOT.
file="$(cd "$(dirname "$file")" && pwd -P)/$(basename "$file")"

case "$file" in
	"$ROOT"/*) rel="${file#"$ROOT"/}" ;;
	*) exit 0 ;;
esac

case "$rel" in
	vendor/* | node_modules/* | assets/build/* | build/* | tmp/* | .claude/plans/*) exit 0 ;;
esac

cd "$ROOT" || exit 0

failures=""
reminders=""

# The unit suite, without its progress rows: on failure the details are what
# matter, and the dots repeat on every edit until the failure is fixed.
unit_tests() {
	local out status
	out="$(vendor/bin/phpunit --testsuite unit 2>&1)"
	status=$?
	printf '%s\n' "$out" | grep -vE '^[.EFWSRIND]+ +[0-9]+ / [0-9]+ +\(' | sed '/^$/N;/^\n$/D'
	return $status
}

# Runs a check, collecting its output when it fails.
run() {
	local label="$1"
	shift
	local out
	if ! out="$("$@" </dev/null 2>&1)"; then
		failures="${failures}
--- ${label} ---
${out}
"
	fi
}

case "$rel" in
	docs/hooks.md)
		echo "docs/hooks.md is generated. Revert the hand edit, change the hook's docblock, then run: composer hooks" >&2
		exit 2
		;;

	*.php)
		run "php -l" php -l "$rel"

		# phpcs.xml.dist lists the checked paths; a file outside them is not linted.
		case "$rel" in
			solrengine.php | uninstall.php | src/* | templates/* | tests/* | bin/*)
				run "phpcs" vendor/bin/phpcs -q --report=emacs "$rel"
				;;
		esac

		case "$rel" in
			solrengine.php | uninstall.php | src/* | templates/*)
				run "direct-access guard" php bin/check-direct-access.php
				;;
		esac

		case "$rel" in
			solrengine.php | uninstall.php | src/*)
				run "phpstan" vendor/bin/phpstan analyse --no-progress --error-format=raw --memory-limit=1G "$rel"
				run "unit tests" unit_tests
				run "hook reference (run: composer hooks)" php bin/generate-hooks.php --check
				;;
			tests/Unit/* | tests/bootstrap*.php | tests/stubs/*)
				run "unit tests" unit_tests
				;;
			tests/Integration/*)
				reminders="Integration tests are not run on edit; they need the WordPress test library (see docs/development.md)."
				;;
		esac
		;;

	assets/src/*.js)
		run "eslint" npx --no-install wp-scripts lint-js "$rel"
		reminders="assets/build is committed: run npm run build before finishing."
		;;

	assets/src/*.css)
		run "stylelint" npx --no-install wp-scripts lint-style "$rel"
		reminders="assets/build is committed: run npm run build before finishing."
		;;

	*)
		exit 0
		;;
esac

if [ -n "$failures" ]; then
	{
		echo "Checks failed for ${rel}:"
		printf '%s\n' "$failures"
		[ -n "$reminders" ] && echo "$reminders"
	} >&2
	exit 2
fi

if [ -n "$reminders" ]; then
	jq -n --arg ctx "$reminders" '{hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: $ctx}}'
fi

exit 0
