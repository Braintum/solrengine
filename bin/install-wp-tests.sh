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

if [ "${WP_VERSION}" = "trunk" ] || [ "${WP_VERSION}" = "nightly" ]; then
	ARCHIVE="https://wordpress.org/nightly-builds/wordpress-latest.zip"
	TESTS_TAG="trunk"
else
	ARCHIVE="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
	TESTS_TAG="tags/${WP_VERSION}"
fi

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
if [ ! -d "${WP_TESTS_DIR}" ]; then
	mkdir -p "${WP_TESTS_DIR}"
	svn co --quiet "https://develop.svn.wordpress.org/${TESTS_TAG}/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes"
	svn co --quiet "https://develop.svn.wordpress.org/${TESTS_TAG}/tests/phpunit/data/" "${WP_TESTS_DIR}/data"
fi

if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ]; then
	download "https://develop.svn.wordpress.org/${TESTS_TAG}/wp-tests-config-sample.php" "${WP_TESTS_DIR}/wp-tests-config.php"

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
