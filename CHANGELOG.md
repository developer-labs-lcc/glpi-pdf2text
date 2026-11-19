# Changelog

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
