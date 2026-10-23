#!/usr/bin/env bash
# Builds the release archives dist/glpi-pdftext-<version>.tar.bz2 (for the GLPI plugin catalog)
# and dist/pdftext.zip (same content, for manual installation).
#
# The archive contains one directory "pdftext" (= plugin key, required by the marketplace) with the
# files needed at runtime, including vendor/ without dev packages. Tests, QA configuration and the
# logo source are left out. Needs: bash, tar, bzip2, composer and (for the ZIP) zip.
#
# Usage: tools/release.sh            (version is read from setup.php)
set -euo pipefail
cd "$(dirname "$0")/.."

# Version from setup.php, e.g. define('PLUGIN_PDFTEXT_VERSION', '1.0.0'); -> 1.0.0
version=$(sed -n "s/^define('PLUGIN_PDFTEXT_VERSION', '\([^']*\)');/\1/p" setup.php)
if [ -z "$version" ]; then
    echo "Version not found in setup.php" >&2
    exit 1
fi

# The catalog description must offer this version, otherwise the marketplace does not see it.
if ! grep -q "<num>$version</num>" pdftext.xml; then
    echo "Version $version is missing in pdftext.xml" >&2
    exit 1
fi

build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT
mkdir -p "$build/pdftext" dist

# Copy the plugin with tar (no rsync needed); the excluded paths are relative to the plugin root.
tar -cf - \
    --exclude='./.git*' \
    --exclude='./vendor' --exclude='./var' --exclude='./dist' \
    --exclude='./tests' --exclude='./tools' \
    --exclude='./.php-cs-fixer.php' --exclude='./phpstan.neon' \
    --exclude='./pics/logo.svg' \
    . | tar -xf - -C "$build/pdftext"

# Runtime dependencies only (smalot/pdfparser), with an optimised autoloader.
composer install --working-dir="$build/pdftext" --no-dev --no-interaction --no-progress --optimize-autoloader --quiet

archive="dist/glpi-pdftext-$version"
rm -f "$archive.tar.bz2" dist/pdftext.zip
tar -cjf "$archive.tar.bz2" -C "$build" pdftext
echo "$archive.tar.bz2"

# The ZIP is named after the plugin key and holds the plugin files without a parent directory:
# unpacking it by double-click (which creates a directory named like the ZIP file) inside the
# GLPI plugins directory directly gives "plugins/pdftext".
if command -v zip >/dev/null 2>&1; then
    (cd "$build/pdftext" && zip -qr -X "$OLDPWD/dist/pdftext.zip" .)
    echo "dist/pdftext.zip"
else
    echo "zip not installed: no ZIP archive built" >&2
fi
