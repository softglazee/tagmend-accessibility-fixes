#!/bin/sh
# Runs the PHPUnit suite in throw-away Docker containers, so neither PHP nor a database is needed
# on the host. Run tools/setup-dev.sh first.
#
# A private Docker network holds a MariaDB 11 container (no published port, a random root
# password made for this run and passed to Docker through the environment, never on a command
# line) and one PHP container per run, with the repository mounted read-only at /app:
#
#   71-83  WordPress 7.1.3,  PHP 8.3  (wordpress:cli-php8.3)
#   65-74  WordPress 6.5.13, PHP 7.4  (wordpress:php7.4-apache)
#   71-84  WordPress 7.1.3,  PHP 8.4  (wordpress:php8.4-apache)
#
# Prints each run's PHPUnit count line, removes the containers and the network, and exits
# non-zero if any run fails.
#
# Usage: sh tools/run-tests-docker.sh [--runs 71-83,65-74,71-84] [--filter <PHPUnit filter>]
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"
host_root=$(pwd -W 2>/dev/null || pwd)
export MSYS_NO_PATHCONV=1

runs="71-83,65-74,71-84"
filter=""
while [ $# -gt 0 ]; do
	case "$1" in
		--runs) runs=$2; shift 2 ;;
		--filter) filter=$2; shift 2 ;;
		*) echo "run-tests-docker: unknown argument $1" >&2; exit 2 ;;
	esac
done

[ -f "$root/vendor/bin/phpunit" ] || { echo "run-tests-docker: vendor/ is missing; run sh tools/setup-dev.sh" >&2; exit 1; }

name="tagmend-tests-$$"
network="$name-net"
db="$name-db"
MARIADB_ROOT_PASSWORD=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
SGL_DB_PASSWORD=$MARIADB_ROOT_PASSWORD
MYSQL_PWD=$MARIADB_ROOT_PASSWORD
export MARIADB_ROOT_PASSWORD SGL_DB_PASSWORD MYSQL_PWD

cleanup() {
	docker rm -f "$db" >/dev/null 2>&1 || true
	docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

docker network create "$network" >/dev/null
docker run -d --name "$db" --network "$network" --network-alias db -e MARIADB_ROOT_PASSWORD mariadb:11 >/dev/null

printf 'waiting for MariaDB'
i=0
until docker exec "$db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
	i=$((i + 1))
	[ $i -lt 90 ] || { echo; echo "run-tests-docker: MariaDB did not start" >&2; docker logs "$db" >&2; exit 1; }
	printf '.'
	sleep 1
done
echo
docker exec -e MYSQL_PWD "$db" mariadb -uroot -e 'CREATE DATABASE wptests71; CREATE DATABASE wptests65;'

summary=""
failed=0
for run in $(echo "$runs" | tr ',' ' '); do
	case "$run" in
		71-83) title="WP 7.1.3 / PHP 8.3"; image=wordpress:cli-php8.3; wp=7.1.3 ;;
		65-74) title="WP 6.5.13 / PHP 7.4"; image=wordpress:php7.4-apache; wp=6.5.13 ;;
		71-84) title="WP 7.1.3 / PHP 8.4"; image=wordpress:php8.4-apache; wp=7.1.3 ;;
		*) echo "run-tests-docker: unknown run $run" >&2; exit 2 ;;
	esac
	[ -f "$root/dev/wp-core/$wp/wp-settings.php" ] || { echo "run-tests-docker: WordPress $wp is missing; run sh tools/setup-dev.sh" >&2; exit 1; }

	echo
	echo "=== $title ($image) ==="
	log=$(mktemp)
	set +e
	set -- --filter "$filter"
	[ -n "$filter" ] || set --
	docker run --rm --network "$network" \
		--mount "type=bind,source=$host_root,target=/app,readonly" \
		-e SGL_DB_USER=root -e SGL_DB_PASSWORD -e SGL_DB_HOST=db \
		-e "SGL_WP_CORE_DIR=/app/dev/wp-core/$wp" -e "WP_TESTS_VERSION=$wp" \
		-w /app --entrypoint php "$image" \
		vendor/bin/phpunit -c phpunit.xml.dist --colors=never "$@" > "$log" 2>&1
	code=$?
	set -e
	cat "$log"
	count=$(grep -E '^(OK \([0-9]+ tests?, [0-9]+ assertions?\)|OK, but .*|Tests: [0-9]+, Assertions: [0-9]+.*|No tests executed!)' "$log" | tail -n 1)
	[ -n "$count" ] || count="(no PHPUnit count line found)"
	rm -f "$log"
	[ "$code" = 0 ] || failed=$((failed + 1))
	summary="$summary$(printf '%-22s %s  %s' "$title" "$([ "$code" = 0 ] && echo pass || echo FAIL)" "$count")
"
done

echo
echo "=== Summary ==="
printf '%s' "$summary"
if [ $failed -gt 0 ]; then
	echo "FAILED: $failed run(s) failed."
	exit 1
fi
echo "All runs passed."
