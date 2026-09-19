#!/usr/bin/env bash
#
# Build the distributable plugin package into build/solrengine.
#
# What ships is decided by .distignore, so the package cannot accidentally
# include tests, build tooling or the quarantined prototype. See plan
# Appendix A item A15.
#
# Run `composer install --no-dev --optimize-autoloader` first: vendor/ is not
# tracked in git and the package needs it.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${ROOT}/build/solrengine"

if [ ! -f "${ROOT}/vendor/autoload.php" ]; then
	echo "vendor/autoload.php is missing." >&2
	echo "Run: composer install --no-dev --optimize-autoloader" >&2
	exit 1
fi

rm -rf "${ROOT}/build"
mkdir -p "${DEST}"

RSYNC_ARGS=(-a --delete)

while IFS= read -r line; do
	# Skip blank lines and comments.
	[ -z "${line}" ] && continue
	case "${line}" in \#*) continue ;; esac
	RSYNC_ARGS+=(--exclude "${line}")
done < "${ROOT}/.distignore"

rsync "${RSYNC_ARGS[@]}" "${ROOT}/" "${DEST}/"

echo "Built ${DEST}"
du -sh "${DEST}"
