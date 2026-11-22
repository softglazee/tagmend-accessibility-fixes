#!/bin/sh
# Runs the PHPUnit suite with the PHP on the PATH against a MySQL or MariaDB server. CI uses it;
# without a local PHP or database, use tools/run-tests-docker.sh instead.
#
# Run tools/setup-dev.sh first. The databases wptests71 (WordPress 7.1.3) and wptests65
# (WordPress 6.5.13) must exist; the WordPress test library empties and reinstalls them on every
# run, so never point it at a database that holds anything you need.
#
# Usage: sh tools/run-tests.sh [--wp 7.1.3|6.5.13] [PHPUnit arguments...]
# Environment: SGL_DB_HOST (default 127.0.0.1), SGL_DB_USER (default root), SGL_DB_PASSWORD
# (default empty). tests/wp-tests-config-71.php and -65.php read them.
set -eu

root=$(cd "$(dirname "$0")/.." && { pwd -W 2>/dev/null || pwd; })
cd "$root"
wp=7.1.3
if [ "${1:-}" = "--wp" ]; then
	wp=$2
	shift 2
fi
case "$wp" in
	7.1.3 | 6.5.13) ;;
	*) echo "run-tests: unsupported WordPress version $wp" >&2; exit 2 ;;
esac

[ -f "$root/dev/wp-core/$wp/wp-settings.php" ] || { echo "run-tests: WordPress $wp is missing; run sh tools/setup-dev.sh" >&2; exit 1; }
[ -f "$root/vendor/bin/phpunit" ] || { echo "run-tests: vendor/ is missing; run sh tools/setup-dev.sh" >&2; exit 1; }

SGL_DB_HOST=${SGL_DB_HOST:-127.0.0.1}
SGL_DB_USER=${SGL_DB_USER:-root}
SGL_DB_PASSWORD=${SGL_DB_PASSWORD:-}
SGL_WP_CORE_DIR="$root/dev/wp-core/$wp"
WP_TESTS_VERSION=$wp
export SGL_DB_HOST SGL_DB_USER SGL_DB_PASSWORD SGL_WP_CORE_DIR WP_TESTS_VERSION

echo "PHP $(php -r 'echo PHP_VERSION;'), WordPress $wp"
exec php vendor/bin/phpunit -c phpunit.xml.dist --colors=never "$@"
