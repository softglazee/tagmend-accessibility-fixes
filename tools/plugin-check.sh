#!/bin/sh
# Installs a built plugin ZIP on a WordPress test site and runs Plugin Check on the installed copy,
# as the release gate does:
#
#   1. the command WordPress.org runs when a plugin is uploaded (plugin_repo category, errors
#      from severity 7, warnings from severity 6, prefixing excluded), on the installed folder;
#   2. the default run;
#   3. the security, performance, plugin_repo and accessibility categories;
#   4. every check at --severity=1.
#
# Each run must print "Success: Checks complete. No errors found." and nothing else. A control
# plugin with no readme and no licence header must then report errors, which shows the checks ran.
#
# Usage: sh tools/plugin-check.sh <tagmend-accessibility-fixes.zip>
# Run it from the WordPress folder of a test site, with WP-CLI on the PATH as `wp` (or set WP to
# the command) and Plugin Check installed and active.
set -eu

zip=${1:?usage: sh tools/plugin-check.sh <tagmend-accessibility-fixes.zip>}
slug=tagmend-accessibility-fixes
wp=${WP:-wp}
ok="Success: Checks complete. No errors found."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
failed=0

echo "== Install $zip"
$wp plugin install "$zip" --force --activate
$wp plugin list --name="$slug" --fields=name,status,version
dir=$($wp plugin path "$slug" --dir)

echo
echo "== The installed copy equals the ZIP"
mkdir -p "$tmp/zip"
unzip -q "$zip" -d "$tmp/zip"
if diff -r "$tmp/zip/$slug" "$dir"; then
	echo "identical: $(find "$dir" -type f | wc -l | tr -d ' ') files"
else
	echo "FAIL: the installed folder differs from the ZIP"
	failed=1
fi

check() { # label, then the wp plugin check arguments
	label=$1
	shift
	echo
	echo "== $label"
	echo "\$ wp plugin check $*"
	set +e
	$wp plugin check "$@" > "$tmp/out.txt" 2>&1
	code=$?
	set -e
	cat "$tmp/out.txt"
	if [ $code -eq 0 ] && [ "$(grep -c . "$tmp/out.txt")" -eq 1 ] && grep -q -x -F "$ok" "$tmp/out.txt"; then
		echo "PASS: $label"
	else
		echo "FAIL: $label (exit $code)"
		failed=1
	fi
}

check "WordPress.org upload command" "$dir" --error-severity=7 --warning-severity=6 --include-low-severity-errors --exclude-checks=prefixing --categories=plugin_repo --format=json --slug="$slug"
check "Default run" "$slug"
check "Four categories" "$slug" --categories=security,performance,plugin_repo,accessibility
check "Every check at severity 1" "$slug" --severity=1

echo
echo "== Control plugin (must report errors)"
mkdir -p "$tmp/pcp-control/pcp-control"
printf '%s\n' '<?php' '/**' ' * Plugin Name: PCP Control' ' * Version: 1.0' ' */' > "$tmp/pcp-control/pcp-control/pcp-control.php"
$wp plugin check "$tmp/pcp-control/pcp-control" --categories=plugin_repo --slug=pcp-control > "$tmp/control.txt" 2>&1 || true
cat "$tmp/control.txt"
if grep -q 'no_plugin_readme' "$tmp/control.txt"; then
	echo "PASS: the control plugin reports no_plugin_readme"
else
	echo "FAIL: the control plugin reported nothing, so the checks may not have run"
	failed=1
fi

echo
$wp plugin list --name=plugin-check --fields=name,status,version
if [ $failed -ne 0 ]; then
	echo "Plugin Check: FAILED"
	exit 1
fi
echo "Plugin Check: no errors and no warnings in any run"
