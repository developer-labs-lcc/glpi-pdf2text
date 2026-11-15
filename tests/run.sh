#!/usr/bin/env bash
# PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
#
# @link      https://developer-labs.com/en/glpi-development/
# @copyright Developer Labs LLC
# @license   GPL-3.0-or-later – see LICENSE
# @version   1.0.2
# @created   2026-10-22
# @changed   2026-11-15
#
# Runs all PDF2Text tests. Usage: tests/run.sh [pattern]
#
# ExtractorTest needs only the plugin's vendor/ directory. The other PHP tests boot GLPI and
# run against the database of this instance (as user "glpi", or $PDF2TEXT_USER): they only
# create and purge their own test records. The plugin must be installed and active.
#
# If GLPI runs in the Docker container $PDF2TEXT_CONTAINER, the tests are executed there,
# with the PHP version and extensions of the web server. Otherwise PHP is called directly.
set -u
cd "$(dirname "$0")"
container="${PDF2TEXT_CONTAINER:-}"
user="${PDF2TEXT_USER:-glpi}"
pattern="${1:-}"
failed=0

if [ -n "$container" ]; then
    # Path of the plugin inside the container.
    in_container="${PDF2TEXT_CONTAINER_PATH:-/var/www/html/glpi/plugins/pdf2text}"
    runphp() { docker exec "$container" php "$in_container/tests/$1" "$user"; }
else
    runphp() { php "$1" "$user"; }
fi

for test in php/*Test.php; do
    if [ -n "$pattern" ] && [[ "$test" != *"$pattern"* ]]; then
        continue
    fi
    echo "== $test"
    output=$(runphp "$test" 2>&1)
    status=$?
    # Hide start-up noise of the PHP installation (double extension lines, Xdebug).
    grep -v -E '^(PHP )?Warning: +(Failed loading Zend extension|Module ".*" is already loaded)|Cannot load Xdebug|^(PHP )?Deprecated: +PHP Startup|^$' <<< "$output"
    [ "$status" -eq 0 ] || failed=1
done

exit $failed
