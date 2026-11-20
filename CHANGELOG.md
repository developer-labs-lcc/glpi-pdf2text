# Changelog

## 1.0.2

- Japanese translation (`ja_JP`), contributed by INOUE Daisuke (pull request #2), with the terms of
  GLPI's own Japanese translation (フォロー for followups, 非公開 for private); catalog
  description in Japanese.

## 1.0.1

Fixes from the security review of Teclib before the publication in the plugin catalog (issue #1):

- Word and OpenDocument: the maximum length is checked while the text is read (a running budget),
  not afterwards. A small ODT could expand to hundreds of megabytes (`text:s` stands for up to 1000
  spaces) and stop PHP, e.g. in the mail collector. The cleaning of the text no longer uses a
  regular expression whose time grew with the square of a run of spaces.
- PDF with the PHP parser: PDFs whose compressed parts unpack to more than 32 MB are refused
  instead of stopping PHP; `pdftotext` is stopped once its text is longer than the maximum length.
- The path of pdftotext is accepted only if the program is named `pdftotext`.
- The reservation of a file against two processes uses GLPI's query builder instead of SQL text.
- Massive action: the summary is added once, at the last item, without changing GLPI's session
  messages directly.

Further fixes from an audit of the whole plugin after the review:

- Word/ODT files whose structure cost time or memory without text are bounded: a list item with
  hundreds of thousands of paragraphs (time grew with the square), hundreds of thousands of
  styles or numbering definitions (styles are read without XPath now), up to 20 000 blocks and
  50 000 table cells per document.
- PHP parser: its own per-stream limit for all filters; LZW and RunLength streams are counted
  with their possible size before parsing; images are not kept in memory.
- PHP 8.2: reading with pdftotext failed for every PDF (PHP before 8.3 reports the end of the
  process differently); fixed.
- Text followups no longer mark a ticket as taken into account (take-into-account times and SLAs
  were wrong, tickets from the mail collector counted as taken into account at once).
- Visibility: the text is public only if its file is on the object itself, a solution or a public
  followup (files of public tasks gave a public text); it is made private when the source followup
  or task is made private or the file is taken off the object.
- Each text followup carries the checksum of its file (`data-pdf2text`): no duplicates after a
  merge of tickets or a new installation; followups of 1.0.0 are recognised in every language.
- Interrupted readings: a reservation older than 15 minutes is taken over (it no longer made the
  file count as read without text); a failure after the followup was added no longer frees the
  file again.
- Button: not on closed objects; adding a text again only with the right to update all followups.
- Massive action: rights checked per object as well.
- Console command: invalid `--id` / `--limit` values are refused (they meant "all"); objects in
  the trash are skipped.
- pdftotext path: absolute path checked at run time too, and after shortening to the field length.
- Release: archives are built only from committed files (`git archive`), `composer.lock` is
  versioned; the plugin's autoloader no longer goes before GLPI's, and the mbstring polyfill is
  not bundled any more.

## 1.0.0

- First version: the text of PDF attachments is added as a followup to tickets, changes and problems
  (mail collector, form, API; also PDFs in followups, tasks and solutions).
- Text extraction with pdftotext (poppler-utils) or, as a fallback, the bundled PHP parser.
- Word (DOCX) and OpenDocument (ODT) files are read as well (without extra program) and added
  formatted: headings, paragraphs, bulleted and numbered lists (also nested) and tables, with
  wrapping lines; footnotes, comments, tables of contents and deleted text left out. File types
  can be switched off one by one.
- No second followup for a file attached again to the same ticket, change or problem (recognised
  by its checksum; table `glpi_plugin_pdf2text_processedfiles`). Followups written before are
  recognised by their content.
- Files attached before: massive action "Copy the text of the attachments (PDF2Text)" in the lists
  of tickets, changes and problems, and the console command `plugins:pdf2text:process`
  (with `--dry-run`, `--since`, `--itemtype`, `--id`, `--limit`).
- Button "Copy the text into a followup" at the attachments in the timeline (next to GLPI's buttons
  edit, delete, import exclusion list), for users who may add a followup (also in the simplified
  interface), only at the files they may see; asks before adding the text of a file a second time.
- Files of approvals are read too; the text of a file only on private followups or tasks goes
  into a private followup.
- Numbered lists interrupted by a paragraph count on; a file that would need too much memory is
  skipped and logged instead of stopping PHP; the console command ends with exit code 1 if a
  file could not be read.
- Configuration page: item types, file types, private followup, maximum length, path of pdftotext.
- Compatible with GLPI 11 and 12: the CSRF token is only sent where GLPI 11 needs it (GLPI 12
  checks the origin of requests instead).
- Translations: English, German, French, Portuguese (Brazil, Portugal), Spanish, Russian, Polish and
  Italian, with a file for every regional variant GLPI offers of these languages.
- Plugin key and folder `pdf2text`, like the plugin's name (development versions used `pdftext`;
  to move such an installation, rename `directory` in `glpi_plugins` and the configuration context
  `plugin:pdftext` in `glpi_configs` to `pdf2text`).
- Plugin logo (logo.png), shown in the plugin list and the marketplace.
- Catalog description `pdf2text.xml` (English, German, French) and release script `tools/release.sh`.
- Code style and static analysis (PHPStan level max) as in Teclib's plugin template.
- File headers with link, version and dates of each file, as in the Menu Manager (see README,
  "File headers").
