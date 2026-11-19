#!/usr/bin/env bash
# PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
#
# @link      https://developer-labs.com/en/glpi-development/
# @copyright Developer Labs LLC
# @license   GPL-3.0-or-later – see LICENSE
# @version   1.1.0
# @created   2026-10-23
# @changed   2026-11-19
#
# Builds the release archives dist/glpi-pdf2text-<version>.tar.bz2 (for the GLPI plugin catalog)
# and dist/pdf2text.zip (same content, for manual installation).
#
# The archive contains one directory "pdf2text" (= plugin key, required by the marketplace) with the
# files needed at runtime, including vendor/ without dev packages, in the versions of composer.lock.
# Tests, QA configuration, the logo source, the screenshots and the GitHub templates are left out.
#
# Only committed files are packed (git archive of HEAD), never other files lying in the plugin
# directory (tokens, sync conflict copies, …), and only from a clean working tree. Two steps,
# because the development container has composer but no git:
#   1. tools/release.sh on the host (git): writes dist/source-<version>.tar from HEAD;
#      if composer is there as well, it goes on with step 2 at once.
#   2. tools/release.sh where composer is (e.g. in the container): builds the archives from
#      dist/source-<version>.tar.
# Needs: bash, tar, bzip2, git (step 1), composer (step 2) and (for the ZIP) zip.
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

mkdir -p dist
source_tar="dist/source-$version.tar"

# Step 1: the committed files of HEAD (git available)
if command -v git >/dev/null 2>&1 && git rev-parse --git-dir >/dev/null 2>&1; then
    # Changed or staged tracked files: the archive would not match the commit
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        echo "Uncommitted changes: commit them first (the archive is built from HEAD)" >&2
        exit 1
    fi
    git archive --format=tar --output="$source_tar" HEAD
    echo "$source_tar ($(git rev-parse --short HEAD))"
    if ! command -v composer >/dev/null 2>&1; then
        echo "Now run tools/release.sh where composer is (e.g. in the container) to build the archives."
        exit 0
    fi
fi

# Step 2: the archives, from the committed files
if [ ! -f "$source_tar" ]; then
    echo "$source_tar is missing: run tools/release.sh on the host (with git) first" >&2
    exit 1
fi

build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT
mkdir -p "$build/pdf2text"
# Unpack without the files not needed at runtime (paths relative to the plugin root)
tar -xf "$source_tar" -C "$build/pdf2text" \
    --exclude='.git*' --exclude='tests' --exclude='tools' \
    --exclude='.php-cs-fixer.php' --exclude='phpstan.neon' \
    --exclude='pics/logo.svg' --exclude='pics/screenshots'

# Runtime dependencies only (smalot/pdfparser), exactly the versions of composer.lock
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
