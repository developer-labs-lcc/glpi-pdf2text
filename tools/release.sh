#!/usr/bin/env bash
# PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
#
# @link      https://developer-labs.com/en/glpi-development/
# @copyright Developer Labs LLC
# @license   GPL-3.0-or-later – see LICENSE
# @version   1.0.1
# @created   2026-10-23
# @changed   2026-11-15
#
# Builds the release archives dist/glpi-pdf2text-<version>.tar.bz2 (for the GLPI plugin catalog)
# and dist/pdf2text.zip (same content, for manual installation).
#
# The archive contains one directory "pdf2text" (= plugin key, required by the marketplace) with the
# files needed at runtime, including vendor/ without dev packages. Tests, QA configuration, the
# logo source and the VS Code workspace file are left out.
# Needs: bash, tar, bzip2, composer and (for the ZIP) zip.
#
# Usage: tools/release.sh            (version is read from setup.php)
set -euo pipefail
cd "$(dirname "$0")/.."

# Version from setup.php, e.g. define('PLUGIN_PDF2TEXT_VERSION', '1.0.0'); -> 1.0.0
version=$(sed -n "s/^define('PLUGIN_PDF2TEXT_VERSION', '\([^']*\)');/\1/p" setup.php)
if [ -z "$version" ]; then
    echo "Version not found in setup.php" >&2
    exit 1
fi

# The catalog description must offer this version, otherwise the marketplace does not see it.
if ! grep -q "<num>$version</num>" pdf2text.xml; then
    echo "Version $version is missing in pdf2text.xml" >&2
    exit 1
fi

build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT
mkdir -p "$build/pdf2text" dist

# Copy the plugin with tar (no rsync needed); the excluded paths are relative to the plugin root.
tar -cf - \
    --exclude='./.git*' \
    --exclude='./vendor' --exclude='./var' --exclude='./dist' \
    --exclude='./tests' --exclude='./tools' \
    --exclude='./.php-cs-fixer.php' --exclude='./phpstan.neon' \
    --exclude='./pics/logo.svg' \
    --exclude='./*.code-workspace' \
    . | tar -xf - -C "$build/pdf2text"

# Runtime dependencies only (smalot/pdfparser), with an optimised autoloader.
composer install --working-dir="$build/pdf2text" --no-dev --no-interaction --no-progress --optimize-autoloader --quiet

archive="dist/glpi-pdf2text-$version"
rm -f "$archive.tar.bz2" dist/pdf2text.zip
tar -cjf "$archive.tar.bz2" -C "$build" pdf2text
echo "$archive.tar.bz2"

# The ZIP is named after the plugin key and holds the plugin files without a parent directory:
# unpacking it by double-click (which creates a directory named like the ZIP file) inside the
# GLPI plugins directory directly gives "plugins/pdf2text".
if command -v zip >/dev/null 2>&1; then
    (cd "$build/pdf2text" && zip -qr -X "$OLDPWD/dist/pdf2text.zip" .)
    echo "dist/pdf2text.zip"
else
    echo "zip not installed: no ZIP archive built" >&2
fi
