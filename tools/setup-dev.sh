#!/bin/sh
# Prepares a clone for the tests and the coding standards. Everything is fetched at a pinned
# version from its own source and checked against tools/checksums.txt:
#
#   - WordPress 7.1.3 and 6.5.13 from wordpress.org (SHA-256), into dev/wp-core/<version>/;
#   - the WordPress PHPUnit test library of the same release: a sparse, shallow clone of the
#     wordpress-develop tag from GitHub (tests/phpunit/includes and tests/phpunit/data only),
#     checked against the tag's commit, into dev/wp-tests/<version>/;
#   - the development packages in composer.lock (PHPUnit 9.6, the PHPUnit polyfills, PHP_CodeSniffer,
#     WordPress Coding Standards, PHPCompatibilityWP) into vendor/, with Composer from the PATH or,
#     when there is none, the composer:2 Docker image.
#
# Finished steps are skipped; --force redoes them.
#
# Usage: sh tools/setup-dev.sh [--force] [--wp <version>] [--no-composer]
#   --wp <version>   prepare only this WordPress version (7.1.3 or 6.5.13); default both
#   --no-composer    skip the Composer step (CI installs the packages itself)
set -eu

# On Windows (Git Bash) the path is taken in its drive-letter form (pwd -W), which Git for Windows
# and the MSYS tools both read correctly, also when MSYS_NO_PATHCONV is set.
root=$(cd "$(dirname "$0")/.." && { pwd -W 2>/dev/null || pwd; })
cd "$root"
force=0
composer=1
versions="7.1.3 6.5.13"
while [ $# -gt 0 ]; do
	case "$1" in
		--force) force=1; shift ;;
		--no-composer) composer=0; shift ;;
		--wp) versions=$2; shift 2 ;;
		*) echo "setup-dev: unknown argument $1" >&2; exit 2 ;;
	esac
done

downloads="$root/dev/downloads"
mkdir -p "$downloads"

step() { printf '\n== %s\n' "$1"; }
die() { echo "setup-dev: $1" >&2; exit 1; }

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | cut -d' ' -f1
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

# fetch <file name in tools/checksums.txt> <url>
fetch() {
	want=$(awk -v f="$1" '$2 == f { print $1 }' tools/checksums.txt)
	[ -n "$want" ] || die "no checksum for $1 in tools/checksums.txt"
	target="$downloads/$1"
	if [ -f "$target" ] && [ "$(sha256_of "$target")" = "$want" ]; then
		echo "have $1"
		return
	fi
	echo "download $2"
	curl -sSfL --retry 3 -o "$target.part" "$2"
	have=$(sha256_of "$target.part")
	if [ "$have" != "$want" ]; then
		rm -f "$target.part"
		die "checksum mismatch for $1: expected $want, got $have"
	fi
	mv "$target.part" "$target"
	echo "verified $1 ($want)"
}

for v in $versions; do
	case "$v" in
		7.1.3 | 6.5.13) ;;
		*) die "unsupported WordPress version $v (the tests know 7.1.3 and 6.5.13)" ;;
	esac

	step "WordPress $v"
	core="$root/dev/wp-core/$v"
	if [ $force = 0 ] && [ -f "$core/wp-settings.php" ]; then
		echo "present: dev/wp-core/$v"
	else
		fetch "wordpress-$v.tar.gz" "https://wordpress.org/wordpress-$v.tar.gz"
		rm -rf "$core"
		mkdir -p "$core"
		# Read through a pipe: GNU tar takes an archive name with a drive letter for a remote host.
		gzip -dc "$downloads/wordpress-$v.tar.gz" | tar -xf - -C "$core" --strip-components=1
		[ -f "$core/wp-settings.php" ] || die "WordPress $v did not unpack"
		echo "unpacked into dev/wp-core/$v"
	fi
	# The test library reads wp-content/uploads before every test; the release archive has none.
	mkdir -p "$core/wp-content/uploads"

	step "WordPress test library $v"
	wpdev="$root/dev/wp-tests/$v"
	commit=$(awk -v t="wordpress-develop@$v" '$2 == t { print $1 }' tools/checksums.txt)
	[ -n "$commit" ] || die "no commit for wordpress-develop@$v in tools/checksums.txt"
	if [ $force = 0 ] && [ -f "$wpdev/tests/phpunit/includes/bootstrap.php" ] && [ "$(git -C "$wpdev" rev-parse HEAD 2>/dev/null)" = "$commit" ]; then
		echo "present: dev/wp-tests/$v at $commit"
	else
		mkdir -p "$root/dev/wp-tests"
		attempt=1
		while :; do
			# A new folder for every attempt: Windows can take a moment to delete a failed one.
			clone="$root/dev/wp-tests/.clone-$v-$$-$attempt"
			if git -c advice.detachedHead=false -c core.autocrlf=false clone --quiet --depth 1 --branch "$v" --filter=blob:none --sparse \
				--config core.autocrlf=false https://github.com/WordPress/wordpress-develop.git "$clone" &&
				git -C "$clone" sparse-checkout set tests/phpunit/includes tests/phpunit/data; then
				break
			fi
			rm -rf "$clone" || true
			[ $attempt -lt 3 ] || die "could not clone the wordpress-develop tag $v"
			attempt=$((attempt + 1))
			echo "retrying the clone ($attempt of 3)"
			sleep 10
		done
		rm -rf "$wpdev"
		mv "$clone" "$wpdev"
		head=$(git -C "$wpdev" rev-parse HEAD)
		[ "$head" = "$commit" ] || die "wordpress-develop tag $v is commit $head, expected $commit"
		[ -f "$wpdev/tests/phpunit/includes/bootstrap.php" ] || die "the test library $v did not check out"
		echo "cloned dev/wp-tests/$v: tag $v, commit $head, tests/phpunit/includes and tests/phpunit/data"
	fi
done

if [ $composer = 1 ]; then
	step "Composer packages (composer.lock)"
	if [ $force = 0 ] && [ -f "$root/vendor/bin/phpunit" ] && [ -f "$root/vendor/bin/phpcs" ]; then
		echo "present: vendor/"
	elif command -v composer >/dev/null 2>&1; then
		composer install --no-interaction --no-progress
	elif command -v docker >/dev/null 2>&1; then
		host_root=$(pwd -W 2>/dev/null || pwd)
		user_flag=""
		case "$(uname -s)" in
			MINGW* | MSYS* | CYGWIN*) ;;
			*) user_flag="--user $(id -u):$(id -g)" ;;
		esac
		# Unpacking into a bind-mounted Windows folder can take longer than Composer's default
		# 300-second process timeout.
		# shellcheck disable=SC2086
		MSYS_NO_PATHCONV=1 docker run --rm $user_flag -e COMPOSER_HOME=/tmp/composer -e COMPOSER_PROCESS_TIMEOUT=1800 \
			-v "$host_root:/app" -w /app composer:2 \
			install --no-interaction --no-progress
	else
		die "Composer is not installed and Docker is not available"
	fi
	[ -f "$root/vendor/bin/phpunit" ] || die "vendor/bin/phpunit is missing after composer install"
fi

step "Done"
for v in $versions; do
	echo "WordPress $v: dev/wp-core/$v, test library dev/wp-tests/$v at $(git -C "$root/dev/wp-tests/$v" rev-parse --short HEAD)"
done
