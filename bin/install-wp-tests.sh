#!/usr/bin/env bash
#
# Install the WordPress test library and a test database.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Based on the standard scaffold shipped by WP-CLI, trimmed to what this
# project needs.

set -euo pipefail

DB_NAME="${1:-}"
DB_USER="${2:-}"
DB_PASS="${3:-}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"

if [ -z "${DB_NAME}" ] || [ -z "${DB_USER}" ]; then
	echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]" >&2
	exit 1
fi

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress/}"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sSL "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

if [ "${WP_VERSION}" = "latest" ]; then
	download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	WP_VERSION="$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | head -1 | cut -d'"' -f4)"
	echo "Latest WordPress is ${WP_VERSION}"
fi

# The test library comes from the wordpress-develop mirror on GitHub rather than
# from develop.svn.wordpress.org, because Subversion is no longer a thing a
# machine reliably has: GitHub's Ubuntu runners dropped it, and macOS has not
# shipped it with the Xcode command line tools since 2020. A contributor
# following docs/development.md on a current Mac could not run this script.
#
# The two sources tag the same release differently, and getting it wrong is a
# 404 rather than an error that names itself:
#
#     Subversion   tags/6.5        tags/7.1.1
#     GitHub       6.5.0           7.1.1
#
# Subversion drops the trailing zero of an x.y.0 release; GitHub does not. So a
# two-part version gains one here.
if [ "${WP_VERSION}" = "trunk" ] || [ "${WP_VERSION}" = "nightly" ]; then
	ARCHIVE="https://wordpress.org/nightly-builds/wordpress-latest.zip"
	TESTS_REF="refs/heads/trunk"
else
	ARCHIVE="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"

	case "${WP_VERSION}" in
		*.*.*) TESTS_REF="refs/tags/${WP_VERSION}" ;;
		*.*)   TESTS_REF="refs/tags/${WP_VERSION}.0" ;;
		*)     TESTS_REF="refs/tags/${WP_VERSION}" ;;
	esac
fi

TESTS_ARCHIVE="https://github.com/WordPress/wordpress-develop/archive/${TESTS_REF}.tar.gz"

# WordPress core
if [ ! -d "${WP_CORE_DIR}" ]; then
	mkdir -p "${WP_CORE_DIR}"

	case "${ARCHIVE}" in
		*.zip)
			download "${ARCHIVE}" /tmp/wordpress.zip
			unzip -q /tmp/wordpress.zip -d /tmp/wp-unpacked
			mv /tmp/wp-unpacked/wordpress/* "${WP_CORE_DIR}"
			;;
		*)
			download "${ARCHIVE}" /tmp/wordpress.tar.gz
			tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "${WP_CORE_DIR}"
			;;
	esac

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "${WP_CORE_DIR}wp-content/db.php"
fi

# Test library
#
# Guarded on a file the download produces rather than on the directory, which
# this script creates itself. A run interrupted between the mkdir and the
# download would otherwise leave a directory that looks complete, skip the
# download for ever, and fail further down copying a sample file that was never
# fetched — under `set -e`, with no message naming the cause.
if [ ! -f "${WP_TESTS_DIR}/includes/bootstrap.php" ]; then
	mkdir -p "${WP_TESTS_DIR}"

	download "${TESTS_ARCHIVE}" /tmp/wordpress-develop.tar.gz

	# The archive's top directory is named after the ref — wordpress-develop-6.5.0,
	# wordpress-develop-trunk — so it is read from the archive rather than
	# reconstructed. Only three paths are extracted; the rest of
	# wordpress-develop is the whole of WordPress twice over and is not wanted.
	TESTS_ROOT="$(tar -tzf /tmp/wordpress-develop.tar.gz | head -1 | cut -d/ -f1)"

	tar -xzf /tmp/wordpress-develop.tar.gz -C /tmp \
		"${TESTS_ROOT}/tests/phpunit/includes" \
		"${TESTS_ROOT}/tests/phpunit/data" \
		"${TESTS_ROOT}/wp-tests-config-sample.php"

	mv "/tmp/${TESTS_ROOT}/tests/phpunit/includes" "${WP_TESTS_DIR}/includes"
	mv "/tmp/${TESTS_ROOT}/tests/phpunit/data" "${WP_TESTS_DIR}/data"
	mv "/tmp/${TESTS_ROOT}/wp-tests-config-sample.php" "${WP_TESTS_DIR}/wp-tests-config-sample.php"

	rm -rf "/tmp/${TESTS_ROOT}" /tmp/wordpress-develop.tar.gz
fi

if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ]; then
	# From the copy the archive already provided, rather than a second request to
	# a different host for a file we have.
	cp "${WP_TESTS_DIR}/wp-tests-config-sample.php" "${WP_TESTS_DIR}/wp-tests-config.php"

	# BSD and GNU sed disagree about -i, so write through a temporary file.
	sed \
		-e "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}':" \
		-e "s/youremptytestdbnamehere/${DB_NAME}/" \
		-e "s/yourusernamehere/${DB_USER}/" \
		-e "s/yourpasswordhere/${DB_PASS}/" \
		-e "s|localhost|${DB_HOST}|" \
		"${WP_TESTS_DIR}/wp-tests-config.php" > "${WP_TESTS_DIR}/wp-tests-config.php.tmp"

	mv "${WP_TESTS_DIR}/wp-tests-config.php.tmp" "${WP_TESTS_DIR}/wp-tests-config.php"
fi

# Database
if ! mysqladmin status --user="${DB_USER}" --password="${DB_PASS}" --host="${DB_HOST%%:*}" >/dev/null 2>&1; then
	echo "Cannot reach MySQL at ${DB_HOST}." >&2
	exit 1
fi

mysqladmin create "${DB_NAME}" --user="${DB_USER}" --password="${DB_PASS}" --host="${DB_HOST%%:*}" 2>/dev/null || true

echo "WordPress test library ready at ${WP_TESTS_DIR}"
