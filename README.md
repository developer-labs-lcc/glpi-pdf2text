# PDF2Text – GLPI plugin

Copies the text of PDF attachments into GLPI **tickets, changes and problems**, so it can be
read in the timeline and found with the search, without opening the attachment.

Typical use: a third party sends notifications as PDF attachments by mail. The mail collector
creates the ticket, and the plugin adds the text of the PDF as a followup.

## How it works

- Every time a PDF is linked to a ticket, change or problem, the plugin reads its text and adds
  it as a **followup** (private by default). This covers:
  - new tickets from the **mail collector** and the web form,
  - **reply mails** and followups, tasks or solutions with a PDF (the text goes to their
    ticket, change or problem),
  - documents added through the API.
- The text is read with **`pdftotext`** (package `poppler-utils`) if it is installed, which keeps
  tables and columns. Otherwise the bundled PHP parser
  ([smalot/pdfparser](https://github.com/smalot/pdfparser)) is used, so the plugin also works on
  hosting without shell access.
- **Scanned PDFs** (images without a text layer) are skipped – there is no OCR.
- The followup does **not** send notifications and does **not** reopen solved tickets.
- Problems (unreadable or broken PDFs) are logged to `files/_log/pdf2text.log`; the ticket is
  created in any case.

Searching: in the ticket list, use the search criterion **Followups > Description** (it contains
the PDF text) or the global search.

## Requirements

- GLPI 11.0 or later (tested with 11.0.9 and 12.0.0)
- PHP 8.2 or later
- Recommended: `pdftotext` (`apt install poppler-utils` / `dnf install poppler-utils`)

## Installation

Easiest: download a release archive from the
[releases](https://github.com/developer-labs-lcc/glpi-pdf2text/releases), with all dependencies:

- `pdf2text.zip`: copy it into your GLPI `plugins` directory and unpack it there by double-click
  (or `unzip pdf2text.zip -d pdf2text`). The files are directly in the ZIP, so the new directory
  is named after the file: `plugins/pdf2text`.
- `glpi-pdf2text-<version>.tar.bz2`: contains the directory `pdf2text`; unpack it in the `plugins`
  directory (`tar -xjf glpi-pdf2text-<version>.tar.bz2`).

From the source code:

```sh
cd /var/www/glpi/plugins            # your GLPI plugins directory
git clone https://github.com/developer-labs-lcc/glpi-pdf2text.git pdf2text
cd pdf2text
composer install --no-dev
```

The directory must be named `pdf2text` (the technical key of the plugin). Then enable the plugin in
**Setup > Plugins** (install, then enable).

## Configuration

**Setup > Plugins > PDF2Text** (requires the right to change the GLPI configuration):

| Setting | Default | Meaning |
|---|---|---|
| Add the text to | Tickets, Changes, Problems | Item types the text is added to |
| Private followup | on | Followup visible to technicians only |
| Maximum length of the text | 20000 | Longer texts are shortened; the full text stays in the PDF |
| Path of pdftotext | empty | Empty = search `/usr/bin`, `/usr/local/bin`, `/opt/homebrew/bin` |

The page also shows which method (pdftotext or PHP parser) is in use.

## Limits

- PDFs attached **before** the plugin was enabled are not processed.
- The PHP parser only reads files up to 20 MB; larger ones need `pdftotext`.
- `pdftotext` is stopped after 30 seconds.
- PDFs that need a password to open, and other file types (Word, images, ...), are not read.

## Development

Tests (`composer install` with dev packages first):

```sh
tests/run.sh                 # all tests; the GLPI tests need the plugin installed and active
php tests/php/ExtractorTest.php   # text extraction only, also runs without GLPI
```

`HandlerTest` boots GLPI and creates its own tickets, changes and documents in the database of the
instance, and purges them at the end. `ConfigTest` checks the configuration page (saving, access,
CSRF token field only on GLPI 11) and restores the settings afterwards.

Languages: German (`de_DE`) and English (`en_GB`, `en_US`; same texts as the source). A file for
every English variant GLPI offers is needed: for a language without a file, GLPI loads the plugin
texts in the default language of the system (e.g. German), while its own texts follow the user's
language. After a change of a `.po` file, compile it with `msgfmt -o locales/<lang>.mo
locales/<lang>.po` and run `bin/console cache:clear` in GLPI.

Code style and static analysis follow Teclib's plugin template. They need the plugin in the
`plugins/pdf2text` directory of a GLPI development checkout (`composer install` in GLPI):

```sh
../../vendor/bin/php-cs-fixer fix --dry-run --diff
../../vendor/bin/phpstan analyse
```

### Release

1. Raise the version in `setup.php` (`PLUGIN_PDF2TEXT_VERSION`), add it to `pdf2text.xml`
   (`<version>` with `<download_url>`) and describe it in `CHANGELOG.md`.
2. `tools/release.sh` builds `dist/glpi-pdf2text-<version>.tar.bz2` and, with the same content
   but without the parent directory, `dist/pdf2text.zip`.
3. Create a GitHub release with the tag `<version>` and attach both archives.

The plugin catalog reads `pdf2text.xml` from the `main` branch.

### File headers

Every file starts with a header (comment) naming the plugin, the address of the developer,
copyright, license, the version of the file and its dates:

```php
/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.0.1
 * @created   2026-10-22
 * @changed   2026-11-15
 */
```

`@created` is the day the file was created. Until the file has been committed for the first time,
it stays at version 1.0.0 without `@changed`, however often it is edited. After that, a changed
file gets `@changed` with the day of the last change and a higher `@version` (each file has its
own version, starting with 1.0.0):

- last digit + 1 (1.0.0 → 1.0.1): bug fixes and small changes,
- middle digit + 1 (1.0.1 → 1.1.0): larger changes,
- first digit + 1 (1.1.0 → 2.0.0): the file was extended a lot or rewritten.

## License

Author: AI & Developer Labs LLC

GPL-3.0-or-later, see [LICENSE](LICENSE). © Developer Labs LLC
