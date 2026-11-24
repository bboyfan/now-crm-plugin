#!/usr/bin/env bash
#
# Run the PHPUnit suite inside a disposable PHP 8.3 container that mounts the
# local WordPress installation, WooCommerce and the WordPress test library.
#
# The WordPress install, the database and wp-tests-config.php are NOT part of
# this repository. See docs/testing.md for how the harness is prepared.
#
# Usage: tests/bin/run-tests.sh [--multisite] [--filter <pattern>] [-- <phpunit args>]
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEV_ROOT="$(cd "${PLUGIN_DIR}/../.." && pwd)"

CACHE_DIR="${NOWCRM_TEST_CACHE:-${DEV_ROOT}/.cache}"
WP_ROOT="${NOWCRM_WP_ROOT:-${DEV_ROOT}/site}"
PLUGINS_DIR="${NOWCRM_PLUGINS_DIR:-${DEV_ROOT}/plugins}"
WP_TESTS_LIB="${NOWCRM_WP_TESTS_LIB:-${CACHE_DIR}/wp-tests-7.1.4}"
POLYFILLS_DIR="${NOWCRM_POLYFILLS:-${CACHE_DIR}/phpunit-polyfills}"
PHPUNIT_PHAR="${NOWCRM_PHPUNIT:-${CACHE_DIR}/phpunit-9.6.phar}"
IMAGE="${NOWCRM_TEST_IMAGE:-wordpress:cli}"
NETWORK="${NOWCRM_TEST_NETWORK:-wpdev_default}"
DB_NAME="${NOWCRM_TEST_DB:-nowcrm_test}"
DB_USER="${NOWCRM_TEST_DB_USER:-root}"
DB_PASSWORD="${NOWCRM_TEST_DB_PASSWORD:-rootpass123}"
DB_HOST="${NOWCRM_TEST_DB_HOST:-wpdev-db}"

for required in "${WP_ROOT}" "${PLUGINS_DIR}" "${WP_TESTS_LIB}" "${POLYFILLS_DIR}" "${PHPUNIT_PHAR}"; do
	if [ ! -e "${required}" ]; then
		echo "Missing test dependency: ${required}" >&2
		echo "See docs/testing.md (setup section)." >&2
		exit 1
	fi
done

if ! docker network inspect "${NETWORK}" >/dev/null 2>&1; then
	echo "Docker network ${NETWORK} not found. Start the local stack first (./scripts/start.sh)." >&2
	exit 1
fi

PHPUNIT_ARGS=()
MULTISITE=0
HPOS=0

while [ $# -gt 0 ]; do
	case "$1" in
		--multisite) MULTISITE=1; shift ;;
		--hpos) HPOS=1; shift ;;
		*) PHPUNIT_ARGS+=("$1"); shift ;;
	esac
done

if [ "${MULTISITE}" -eq 1 ]; then
	export WP_TESTS_MULTISITE=1
fi

exec docker run --rm \
	--network "${NETWORK}" \
	-v "${WP_ROOT}:/var/www/html" \
	-v "${PLUGINS_DIR}:/var/www/html/wp-content/plugins" \
	-v "${WP_TESTS_LIB}:/tmp/wp-tests-lib:ro" \
	-v "${POLYFILLS_DIR}:/tmp/phpunit-polyfills:ro" \
	-v "${PHPUNIT_PHAR}:/usr/local/bin/phpunit.phar:ro" \
	-e WP_TESTS_DIR=/tmp/wp-tests-lib/phpunit \
	-e WP_TESTS_CONFIG_FILE_PATH=/tmp/wp-tests-lib/wp-tests-config.php \
	-e WP_TESTS_PHPUNIT_POLYFILLS_PATH=/tmp/phpunit-polyfills \
	-e WP_TESTS_MULTISITE="${WP_TESTS_MULTISITE:-0}" \
	-e NOWCRM_HPOS="${HPOS}" \
	-e NOWCRM_DB_NAME="${DB_NAME}" \
	-e NOWCRM_DB_USER="${DB_USER}" \
	-e NOWCRM_DB_PASSWORD="${DB_PASSWORD}" \
	-e NOWCRM_DB_HOST="${DB_HOST}" \
	-w /var/www/html/wp-content/plugins/now-crm \
	--entrypoint sh \
	"${IMAGE}" -c 'mkdir -p /tmp/nowcrm-empty-mu && exec php /usr/local/bin/phpunit.phar --configuration /var/www/html/wp-content/plugins/now-crm/phpunit.xml.dist "$@"' -- ${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"}
