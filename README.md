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
- Problems (unreadable or broken PDFs) are logged to `files/_log/pdftext.log`; the ticket is
  created in any case.

Searching: in the ticket list, use the search criterion **Followups > Description** (it contains
the PDF text) or the global search.

## Requirements

- GLPI 11.0 or later
- PHP 8.2 or later
- Recommended: `pdftotext` (`apt install poppler-utils` / `dnf install poppler-utils`)

## Installation

```sh
cd /var/www/glpi/plugins            # your GLPI plugins directory
git clone <repository-url> pdftext  # or unpack the release archive as "pdftext"
cd pdftext
composer install --no-dev           # not needed for release archives that contain vendor/
```

Then enable the plugin in **Setup > Plugins** (install, then enable).

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

## License

Author: AI & Developer Labs LLC

GPL-3.0-or-later, see [LICENSE](LICENSE). © Developer Labs LLC
