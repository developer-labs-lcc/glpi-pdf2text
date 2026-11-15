#!/usr/bin/env python3
# PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
#
# @link      https://developer-labs.com/en/glpi-development/
# @copyright Developer Labs LLC
# @license   GPL-3.0-or-later – see LICENSE
# @version   1.0.0
# @created   2026-11-15
"""Builds the test documents change.docx and change.odt next to this script.

Both hold the same content: a heading, paragraphs with umlauts, a tab and a line break, a
bulleted list with a nested numbered list, a numbered list, a table and a search token. Parts
that must not be read: deleted text of tracked changes, a field code, a comment, a footnote, a
paragraph in the styles, a generated table of contents and (DOCX) the fallback copy of a text box.

Usage: python3 tests/fixtures/make_office_fixtures.py
"""
import pathlib
import zipfile

OUT = pathlib.Path(__file__).resolve().parent

# ---------------------------------------------------------------- DOCX
W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" ' \
    'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'


def wp(text, style=None, num=None):
    """Paragraph with an optional style id and numbering (numId, level)."""
    ppr = ''
    if style:
        ppr += f'<w:pStyle w:val="{style}"/>'
    if num:
        ppr += f'<w:numPr><w:ilvl w:val="{num[1]}"/><w:numId w:val="{num[0]}"/></w:numPr>'
    return f'<w:p>{"<w:pPr>" + ppr + "</w:pPr>" if ppr else ""}<w:r><w:t xml:space="preserve">{text}</w:t></w:r></w:p>'


def cell(*paragraphs):
    return '<w:tc>' + ''.join(f'<w:p><w:r><w:t>{p}</w:t></w:r></w:p>' for p in paragraphs) + '</w:tc>'


document = f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document {W}><w:body>
{wp("Change Notification CN-2026-0815", style="berschrift1")}
<w:p><w:r><w:t>Vendor: Contoso – Wartungsfenster für Kärnten &amp; Zürich</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r></w:p>
<w:p><w:r><w:t>Key</w:t></w:r><w:r><w:tab/><w:t>Value</w:t><w:br/><w:t>second line</w:t></w:r></w:p>
<w:p><w:del><w:r><w:delText>DELETED-WORD</w:delText></w:r></w:del><w:r><w:instrText>FIELDCODE</w:instrText></w:r><w:r><w:t>Kept text</w:t></w:r></w:p>
{wp("Steps", style="Heading2")}
{wp("Bullet one", num=(1, 0))}
{wp("Sub step A", num=(1, 1))}
{wp("Sub step B", num=(1, 1))}
{wp("Bullet two", num=(1, 0))}
{wp("Numbered one", num=(2, 0))}
{wp("Numbered two", num=(2, 0))}
<w:p><w:r><mc:AlternateContent><mc:Choice Requires="wps"><w:drawing><w:txbxContent><w:p><w:r><w:t>Box text</w:t></w:r></w:p></w:txbxContent></w:drawing></mc:Choice><mc:Fallback><w:pict><w:txbxContent><w:p><w:r><w:t>Box text</w:t></w:r></w:p></w:txbxContent></w:pict></mc:Fallback></mc:AlternateContent></w:r></w:p>
<w:tbl><w:tr>{cell("System")}{cell("Impact")}</w:tr><w:tr>{cell("Core router R1")}{cell("Outage", "15 min")}</w:tr></w:tbl>
{wp("Unique search token: OKAPI-0815")}
<w:sectPr/>
</w:body></w:document>'''

# Style ids are language dependent ("berschrift1" in a German Word), the names are not
styles = f'''<?xml version="1.0" encoding="UTF-8"?>
<w:styles {W}>
<w:style w:type="paragraph" w:styleId="berschrift1"><w:name w:val="heading 1"/></w:style>
<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/></w:style>
<w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/></w:style>
</w:styles>'''

numbering = f'''<?xml version="1.0" encoding="UTF-8"?>
<w:numbering {W}>
<w:abstractNum w:abstractNumId="10"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>
<w:abstractNum w:abstractNumId="20"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>
<w:num w:numId="1"><w:abstractNumId w:val="10"/></w:num>
<w:num w:numId="2"><w:abstractNumId w:val="20"/></w:num>
</w:numbering>'''

footnotes = f'''<?xml version="1.0" encoding="UTF-8"?>
<w:footnotes {W}><w:footnote w:id="1"><w:p><w:r><w:t>FOOTNOTE-TEXT</w:t></w:r></w:p></w:footnote></w:footnotes>'''

with zipfile.ZipFile(OUT / 'change.docx', 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
    z.writestr('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>')
    z.writestr('word/document.xml', document)
    z.writestr('word/styles.xml', styles)
    z.writestr('word/numbering.xml', numbering)
    z.writestr('word/footnotes.xml', footnotes)

# ---------------------------------------------------------------- ODT
NS = ('xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
      'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
      'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
      'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
      'xmlns:dc="http://purl.org/dc/elements/1.1/" office:version="1.3"')
content = f'''<?xml version="1.0" encoding="UTF-8"?>
<office:document-content {NS}>
  <office:automatic-styles>
    <text:list-style style:name="L1"><text:list-level-style-bullet text:level="1" text:bullet-char="•"/><text:list-level-style-number text:level="2" style:num-format="1"/></text:list-style>
    <text:list-style style:name="L2"><text:list-level-style-number text:level="1" style:num-format="1"/></text:list-style>
    <text:p>STYLE-NOT-TEXT</text:p>
  </office:automatic-styles>
  <office:body>
    <office:text>
      <text:sequence-decls><text:sequence-decl text:name="Table"/></text:sequence-decls>
      <text:tracked-changes><text:changed-region text:id="c1"><text:deletion><text:p>DELETED-WORD</text:p></text:deletion></text:changed-region></text:tracked-changes>
      <text:table-of-content text:name="TOC"><text:index-body><text:p>TOC-ENTRY Change Notification</text:p></text:index-body></text:table-of-content>
      <text:h text:outline-level="1">Change Notification CN-2026-0815</text:h>
      <text:p>Vendor: Contoso – Wartungsfenster für <text:span>Kärnten</text:span> &amp; Zürich<text:note text:note-class="footnote"><text:note-citation>1</text:note-citation><text:note-body><text:p>FOOTNOTE-TEXT</text:p></text:note-body></text:note></text:p>
      <text:p>Key<text:tab/>Value<text:line-break/>second line</text:p>
      <text:p>A<text:s text:c="3"/>B<office:annotation><dc:creator>X</dc:creator><text:p>COMMENT-TEXT</text:p></office:annotation> kept</text:p>
      <text:p/>
      <text:h text:outline-level="2">Steps</text:h>
      <text:list text:style-name="L1">
        <text:list-item><text:p>Bullet one</text:p>
          <text:list><text:list-item><text:p>Sub step A</text:p></text:list-item><text:list-item><text:p>Sub step B</text:p></text:list-item></text:list>
        </text:list-item>
        <text:list-item><text:p>Bullet two</text:p></text:list-item>
      </text:list>
      <text:list text:style-name="L2">
        <text:list-item><text:p>Numbered one</text:p></text:list-item>
        <text:list-item><text:p>Numbered two</text:p></text:list-item>
      </text:list>
      <table:table>
        <table:table-row><table:table-cell><text:p>System</text:p></table:table-cell><table:table-cell><text:p>Impact</text:p></table:table-cell></table:table-row>
        <table:table-row><table:table-cell><text:p>Core router R1</text:p></table:table-cell><table:table-cell><text:p>Outage</text:p><text:p>15 min</text:p></table:table-cell></table:table-row>
      </table:table>
      <text:p>Unique search token: OKAPI-0815</text:p>
    </office:text>
  </office:body>
</office:document-content>'''
with zipfile.ZipFile(OUT / 'change.odt', 'w') as z:
    # The ODF format wants "mimetype" first and uncompressed
    z.writestr(zipfile.ZipInfo('mimetype'), 'application/vnd.oasis.opendocument.text', compress_type=zipfile.ZIP_STORED)
    z.writestr('META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>', compress_type=zipfile.ZIP_DEFLATED)
    z.writestr('content.xml', content, compress_type=zipfile.ZIP_DEFLATED)
print('written:', OUT / 'change.docx', OUT / 'change.odt')
