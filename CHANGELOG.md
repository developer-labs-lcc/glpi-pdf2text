# Changelog

## 1.0.0

- First version: the text of PDF attachments is added as a followup to tickets, changes and problems
  (mail collector, form, API; also PDFs in followups, tasks and solutions).
- Text extraction with pdftotext (poppler-utils) or, as a fallback, the bundled PHP parser.
- Word (DOCX) and OpenDocument (ODT) files are read as well (without extra program; tables kept
  as lines with tab-separated cells, comments and deleted text left out); file types can be
  switched off one by one.
- No second followup for a file attached again to the same ticket, change or problem (recognised
  by its checksum; table `glpi_plugin_pdf2text_processedfiles`). Followups written before are
  recognised by their content.
- Files attached before: massive action "Copy the text of the attachments (PDF2Text)" in the lists
  of tickets, changes and problems, and the console command `plugins:pdf2text:process`
  (with `--dry-run`, `--since`, `--itemtype`, `--id`, `--limit`).
- Button "Copy the text into a followup" at the attachments in the timeline (next to GLPI's buttons
  edit, delete, import exclusion list), for users who may add a followup; asks before adding the
  text of a file a second time.
- Configuration page: item types, file types, private followup, maximum length, path of pdftotext.
- Compatible with GLPI 11 and 12: the CSRF token is only sent where GLPI 11 needs it (GLPI 12
  checks the origin of requests instead).
- Translations: English (`en_GB`, `en_US`), German.
- Plugin key and folder `pdf2text`, like the plugin's name (development versions used `pdftext`;
  to move such an installation, rename `directory` in `glpi_plugins` and the configuration context
  `plugin:pdftext` in `glpi_configs` to `pdf2text`).
- Plugin logo (logo.png), shown in the plugin list and the marketplace.
- Catalog description `pdf2text.xml` (English, German, French) and release script `tools/release.sh`.
- Code style and static analysis (PHPStan level max) as in Teclib's plugin template.
- File headers with link, version and dates of each file, as in the Menu Manager (see README,
  "File headers").
