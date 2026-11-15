<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.0.0
 * @created   2026-11-15
 */

namespace GlpiPlugin\Pdf2text;

use RuntimeException;
use XMLReader;
use ZipArchive;

use function Safe\preg_replace;

/**
 * Reads the text of Word documents (.docx) and OpenDocument texts (.odt).
 *
 * Both formats are ZIP archives with the text in one XML file (DOCX: word/document.xml,
 * ODT: content.xml), so no extra library is needed: the XML is read with PHP's XMLReader.
 * Paragraphs become lines, table cells are separated by tabs and table rows become lines,
 * like the layout pdftotext gives for tables. Headers, footers, footnotes, comments and
 * deleted text of tracked changes are left out.
 *
 * The class does not depend on GLPI, so it can be tested on its own.
 */
final class OfficeTextExtractor
{
    public const DOCX = 'docx';
    public const ODT  = 'odt';

    /** XML file with the text of the document, per format. */
    private const CONTENT_FILE = [
        self::DOCX => 'word/document.xml',
        self::ODT  => 'content.xml',
    ];

    /**
     * Larger (unpacked) XML files are not read: a few kilobytes of ZIP can unpack to gigabytes
     * ("zip bomb"), and the XML is kept in memory.
     */
    private const MAX_XML_BYTES = 50 * 1024 * 1024;

    /** XML namespaces of the elements that matter (WordprocessingML, ODF text, ODF table, ODF office). */
    private const NS_W      = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_TEXT   = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const NS_TABLE  = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    /**
     * Returns the normalised text of the document, or an empty string if it contains no text
     * (e.g. only images).
     *
     * @param string $format self::DOCX or self::ODT
     *
     * @throws RuntimeException if the file is not a readable document of that format
     */
    public function extract(string $file, string $format): string
    {
        if (!isset(self::CONTENT_FILE[$format])) {
            throw new RuntimeException(sprintf('Unknown format "%s".', $format));
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException(sprintf('File "%s" is not readable.', $file));
        }
        if (!self::isAvailable()) {
            throw new RuntimeException('Word and OpenDocument files need the PHP extensions zip and xmlreader.');
        }

        $xml  = self::readZipEntry($file, self::CONTENT_FILE[$format]);
        $text = $format === self::DOCX ? self::docxText($xml) : self::odtText($xml);

        return PdfTextExtractor::normalise($text);
    }

    /**
     * Whether the PHP extensions needed for DOCX and ODT are installed (both are common, GLPI
     * itself uses zip for its marketplace).
     */
    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class) && class_exists(XMLReader::class);
    }

    /**
     * Content of one file of the ZIP archive.
     *
     * @throws RuntimeException if the archive or the file in it cannot be read
     */
    private static function readZipEntry(string $file, string $entry): string
    {
        $zip = new ZipArchive();
        // Password protected Word files are not ZIP archives (but encrypted OLE files), so they
        // fail here as well.
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The file is not a valid document (no ZIP archive).');
        }
        try {
            $stat = $zip->statName($entry);
            if ($stat === false) {
                throw new RuntimeException(sprintf('The document contains no "%s".', $entry));
            }
            if ($stat['size'] > self::MAX_XML_BYTES) {
                throw new RuntimeException(sprintf('The text of the document is too large (%d bytes unpacked).', $stat['size']));
            }
            $xml = $zip->getFromName($entry);
            if ($xml === false) {
                throw new RuntimeException(sprintf('"%s" could not be unpacked.', $entry));
            }
        } finally {
            $zip->close();
        }

        return $xml;
    }

    /**
     * Opens the XML for reading. LIBXML_NONET: no network access; external entities are not
     * loaded (no LIBXML_NOENT), so the file cannot read other files of the server (XXE).
     */
    private static function reader(string $xml): XMLReader
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('The XML of the document could not be read.');
        }

        return $reader;
    }

    /**
     * Text of word/document.xml (WordprocessingML).
     *
     * Elements used: w:p paragraph, w:t text, w:tab tab, w:br / w:cr line break, w:tc table
     * cell, w:tr table row. Deleted text (w:delText) and field codes (w:instrText) are other
     * elements than w:t and so left out automatically.
     */
    private static function docxText(string $xml): string
    {
        $reader = self::reader($xml);
        $out    = '';
        // Depth of open table cells: a paragraph inside a cell ends with a space instead of a
        // line break, so that one table row stays on one line.
        $cells  = 0;
        // Whether the reader is inside a w:t element (only text there is document text)
        $in_text = false;

        while (@$reader->read()) {
            $type = $reader->nodeType;
            if ($type === XMLReader::ELEMENT && $reader->namespaceURI === self::NS_W) {
                switch ($reader->localName) {
                    case 't':
                        $in_text = !$reader->isEmptyElement;
                        break;
                    case 'tab':
                        $out .= "\t";
                        break;
                    case 'br':
                    case 'cr':
                        $out .= "\n";
                        break;
                    case 'tc':
                        $cells += $reader->isEmptyElement ? 0 : 1;
                        break;
                }
            } elseif ($type === XMLReader::END_ELEMENT && $reader->namespaceURI === self::NS_W) {
                switch ($reader->localName) {
                    case 't':
                        $in_text = false;
                        break;
                    case 'p':
                        $out .= $cells > 0 ? ' ' : "\n";
                        break;
                    case 'tc':
                        $cells = max(0, $cells - 1);
                        $out  .= "\t";
                        break;
                    case 'tr':
                        $out .= "\n";
                        break;
                }
            } elseif ($in_text && ($type === XMLReader::TEXT || $type === XMLReader::SIGNIFICANT_WHITESPACE || $type === XMLReader::CDATA)) {
                $out .= $reader->value;
            }
        }
        $reader->close();

        return self::tidyCells($out);
    }

    /**
     * Text of content.xml (OpenDocument).
     *
     * Elements used: text:p paragraph, text:h heading, text:s spaces (attribute text:c = number),
     * text:tab tab, text:line-break line break, table:table-cell cell, table:table-row row.
     * Only the body (office:body) is read; comments (office:annotation) and the deleted text of
     * tracked changes (text:tracked-changes) are skipped.
     */
    private static function odtText(string $xml): string
    {
        $reader = self::reader($xml);
        $out    = '';
        $cells  = 0;
        // Depth of open text:p / text:h: text outside of paragraphs is only indentation
        $paragraphs = 0;
        $in_body    = false;
        // Depth inside a skipped element (comment, tracked changes); 0 = not inside one
        $skip = 0;

        while (@$reader->read()) {
            $type  = $reader->nodeType;
            $ns    = $reader->namespaceURI;
            $name  = $reader->localName;
            $empty = $type === XMLReader::ELEMENT && $reader->isEmptyElement;

            if ($skip > 0) {
                // Count the nesting so that the end of the skipped element itself is found
                if ($type === XMLReader::ELEMENT && !$empty) {
                    $skip++;
                } elseif ($type === XMLReader::END_ELEMENT) {
                    $skip--;
                }
                continue;
            }

            if ($type === XMLReader::ELEMENT) {
                if ($ns === self::NS_OFFICE && $name === 'body') {
                    $in_body = !$empty;
                    continue;
                }
                if (!$in_body) {
                    continue;
                }
                if (($ns === self::NS_OFFICE && $name === 'annotation') || ($ns === self::NS_TEXT && $name === 'tracked-changes')) {
                    $skip = $empty ? 0 : 1;
                    continue;
                }
                if ($ns === self::NS_TEXT) {
                    switch ($name) {
                        case 'p':
                        case 'h':
                            if ($empty) {
                                // An empty paragraph is an empty line
                                $out .= $cells > 0 ? ' ' : "\n";
                            } else {
                                $paragraphs++;
                            }
                            break;
                        case 's':
                            // text:c = number of spaces (default 1), at most 1000 against absurd values
                            $count = (int) ($reader->getAttributeNs('c', self::NS_TEXT) ?? 1);
                            $out  .= str_repeat(' ', max(1, min(1000, $count)));
                            break;
                        case 'tab':
                            $out .= "\t";
                            break;
                        case 'line-break':
                            $out .= "\n";
                            break;
                    }
                } elseif ($ns === self::NS_TABLE && $name === 'table-cell') {
                    if ($empty) {
                        // Empty cell: only its separator
                        $out .= "\t";
                    } else {
                        $cells++;
                    }
                }
            } elseif ($type === XMLReader::END_ELEMENT && $in_body) {
                if ($ns === self::NS_TEXT && ($name === 'p' || $name === 'h')) {
                    $paragraphs = max(0, $paragraphs - 1);
                    $out       .= $cells > 0 ? ' ' : "\n";
                } elseif ($ns === self::NS_TABLE && $name === 'table-cell') {
                    $cells = max(0, $cells - 1);
                    $out  .= "\t";
                } elseif ($ns === self::NS_TABLE && $name === 'table-row') {
                    $out .= "\n";
                } elseif ($ns === self::NS_OFFICE && $name === 'body') {
                    $in_body = false;
                }
            } elseif ($in_body && $paragraphs > 0 && ($type === XMLReader::TEXT || $type === XMLReader::CDATA || $type === XMLReader::SIGNIFICANT_WHITESPACE)) {
                // In ODF, runs of spaces, tabs and line breaks in the XML count as one space
                // (real ones are written as text:s, text:tab and text:line-break, see above).
                // [ \t\r\n]+ = one or more of these characters
                $out .= preg_replace('/[ \t\r\n]+/', ' ', $reader->value);
            }
        }
        $reader->close();

        return self::tidyCells($out);
    }

    /**
     * Removes the spaces a paragraph end leaves at the end of a table cell, before its tab:
     * " +\t" = one or more spaces directly before a tab.
     */
    private static function tidyCells(string $text): string
    {
        return preg_replace('/ +\t/', "\t", $text);
    }
}
