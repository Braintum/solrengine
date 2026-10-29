#!/usr/bin/env bash
#
# Build the release: build/solrengine, build/solrengine-<version>.zip, its
# SHA-256 and a manifest of every file in it.
#
# What ships is decided by .distignore, so the package cannot accidentally
# include tests or build tooling. vendor/ is not copied from the working tree:
# it is installed into the package from composer.lock with --no-dev, so a
# developer's PHPUnit never ships and the bundled Action Scheduler is the locked
# version, not whatever happens to be installed.
#
# Reproducible: every entry gets the commit's timestamp and a fixed mode, and the
# zip lists files in byte order, so building one commit twice gives one
# checksum. SOURCE_DATE_EPOCH overrides the timestamp.
#
# Compile assets/build first (npm ci && npm run build). It is committed, and the
# script refuses a package without it, but does not rebuild it.
#
# Needs php, composer, rsync, zip and unzip.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${ROOT}/build"
DEST="${BUILD}/solrengine"
HELPER="${ROOT}/bin/release-manifest.php"

php "${ROOT}/bin/check-version.php"

VERSION="$(sed -n 's/^ \* Version: *//p' "${ROOT}/solrengine.php" | head -n 1 | tr -d '[:space:]')"
ZIP="${BUILD}/solrengine-${VERSION}.zip"
MANIFEST="${BUILD}/solrengine-${VERSION}.manifest.txt"

# The commit being packaged, and whether the working tree matches it. A release
# should be built from a clean checkout; a dirty one is allowed, for trying the
# script, and the manifest says so.
COMMIT="unknown"
DIRTY="no"

if git -C "${ROOT}" rev-parse --git-dir > /dev/null 2>&1; then
	COMMIT="$(git -C "${ROOT}" rev-parse HEAD)"

	if [ -n "$(git -C "${ROOT}" status --porcelain)" ]; then
		DIRTY="yes"
	fi

	EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "${ROOT}" log -1 --format=%ct)}"
else
	EPOCH="${SOURCE_DATE_EPOCH:-$(date +%s)}"
fi

rm -rf "${BUILD}"
mkdir -p "${DEST}"

# 1. Sources, filtered by .distignore. vendor/ is installed in step 2.
RSYNC_ARGS=(-a --delete --exclude /vendor)

while IFS= read -r line; do
	# Skip blank lines and comments.
	[ -z "${line}" ] && continue
	case "${line}" in \#*) continue ;; esac
	RSYNC_ARGS+=(--exclude "${line}")
done < "${ROOT}/.distignore"

rsync "${RSYNC_ARGS[@]}" "${ROOT}/" "${DEST}/"

# 2. Production dependencies, exactly as locked. The lock file is needed to
# install and does not ship.
cp "${ROOT}/composer.lock" "${DEST}/composer.lock"

composer install \
	--working-dir="${DEST}" \
	--no-dev \
	--optimize-autoloader \
	--no-interaction \
	--no-progress \
	--no-plugins \
	--no-scripts \
	--prefer-dist \
	--quiet

rm "${DEST}/composer.lock"

# 3. Refuse a package with something missing or something it must not carry.
php "${HELPER}" check "${DEST}"

# 4. Same timestamps and modes on every build, then the manifest and the zip.
php "${HELPER}" normalise "${DEST}" "${EPOCH}"

HEADER="$(mktemp)"
trap 'rm -f "${HEADER}"; rm -rf "${VERIFY:-}"' EXIT

{
	echo "plugin: solrengine ${VERSION}"
	echo "commit: ${COMMIT}"
	echo "uncommitted changes: ${DIRTY}"
	echo "timestamp: ${EPOCH}"
	echo "composer.lock sha256: $(php -r 'echo hash_file("sha256", $argv[1]);' "${ROOT}/composer.lock")"
} > "${HEADER}"

php "${HELPER}" write "${DEST}" "${MANIFEST}" "${HEADER}"

# One root directory, named for the slug, as WordPress unpacks it. -X leaves out
# the owner and extra timestamps; TZ=UTC because zip stores local time.
(
	cd "${BUILD}"
	find solrengine -print | LC_ALL=C sort | TZ=UTC zip -X -q "${ZIP}" -@
)

ZIP_SHA="$(php -r 'echo hash_file("sha256", $argv[1]);' "${ZIP}")"
echo "${ZIP_SHA}  $(basename "${ZIP}")" > "${ZIP}.sha256"
echo "# zip sha256: ${ZIP_SHA}" >> "${MANIFEST}"

# 5. Unpack the zip somewhere else and check it against the manifest, so the
# archive is tested as a user receives it, not the directory it was made from.
VERIFY="$(mktemp -d)"
unzip -q "${ZIP}" -d "${VERIFY}"

ROOTS="$(find "${VERIFY}" -mindepth 1 -maxdepth 1 | wc -l | tr -d ' ')"

if [ "${ROOTS}" != "1" ] || [ ! -f "${VERIFY}/solrengine/solrengine.php" ]; then
	echo "Release: the zip must hold exactly one directory, solrengine/." >&2
	exit 1
fi

php "${HELPER}" verify "${VERIFY}/solrengine" "${MANIFEST}"

# Every PHP file in the package parses.
find "${VERIFY}/solrengine" -name '*.php' -print0 | xargs -0 -n 1 -P 4 php -l > /dev/null

echo "Built ${DEST}"
echo "      ${ZIP} ($(du -h "${ZIP}" | cut -f1))"
echo "      sha256 ${ZIP_SHA}"

if [ "${DIRTY}" = "yes" ]; then
	echo "Warning: built from a working tree with uncommitted changes. Do not release this zip." >&2
fi
