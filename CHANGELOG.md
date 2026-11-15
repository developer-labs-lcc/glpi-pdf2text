# Changelog

## 1.0.0

- First version: the text of PDF attachments is added as a followup to tickets, changes and problems
  (mail collector, form, API; also PDFs in followups, tasks and solutions).
- Text extraction with pdftotext (poppler-utils) or, as a fallback, the bundled PHP parser.
- Configuration page: item types, private followup, maximum length, path of pdftotext.
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
