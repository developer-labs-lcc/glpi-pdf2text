<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   2.2.0
 * @created   2026-11-15
 * @changed   2026-11-15
 */

namespace GlpiPlugin\Pdf2text;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

use function Safe\preg_match;
use function Safe\preg_replace;

/**
 * Reads Word documents (.docx) and OpenDocument texts (.odt) with their structure: headings,
 * paragraphs, bulleted and numbered lists (with their nesting) and tables.
 *
 * Both formats are ZIP archives with the text in one XML file (DOCX: word/document.xml,
 * ODT: content.xml), so no extra library is needed: the XML is read with PHP's DOM. Whether a
 * list is numbered and which paragraphs are headings is taken from the styles of the document
 * (DOCX: word/styles.xml and word/numbering.xml; ODT: the list styles in content.xml and
 * styles.xml).
 *
 * The result is a list of blocks (see extractBlocks()), which toText() turns into plain text and
 * toHtml() into the HTML of a followup. Headers, footers, footnotes, comments and deleted text of
 * tracked changes are left out.
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
     * ("zip bomb"), and the DOM of the XML needs several times its size in memory.
     */
    private const MAX_XML_BYTES = 20 * 1024 * 1024;

    /** Memory the DOM of an XML file needs, as a multiple of its size (rough, on the safe side). */
    private const DOM_MEMORY_FACTOR = 10;

    /** XML namespaces (WordprocessingML, markup compatibility; ODF office, text, table, style). */
    private const NS_W      = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_MC     = 'http://schemas.openxmlformats.org/markup-compatibility/2006';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const NS_TEXT   = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const NS_TABLE  = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_STYLE  = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

    /** Budget in bytes when no maximum length is given (see extractBlocks()). */
    private const DEFAULT_BUDGET = 4 * 1024 * 1024;

    /** Bytes per character for the budget: UTF-8 needs up to 4 bytes per character. */
    private const BYTES_PER_CHAR = 4;

    /** Deepest list level that is kept (deeper items are shown on this level). */
    private const MAX_LIST_DEPTH = 9;

    /** Heading levels of the document (1 = top) become <h3> … <h6> in the followup. */
    private const HTML_HEADING_OFFSET = 2;

    /** Format of the document being read */
    private string $format = self::DOCX;

    /** @var array<string, array{name: string, based_on: string, outline: int|null, num_id: string, num_level: int}> DOCX paragraph styles by id */
    private array $docx_styles = [];

    /** @var array<string, array<int, bool>> DOCX: numId => level => numbered (true) or bullets (false) */
    private array $docx_numbering = [];

    /** @var array<string, array<int, bool>> ODT: list style name => level => numbered or bullets */
    private array $odt_list_styles = [];

    /** ODT: number of the lists met so far (their ids for the item blocks) */
    private int $odt_lists = 0;

    /** ODT: id of the last list on the top level (for text:continue-numbering) */
    private string $odt_last_list = '';

    /** @var array<string, string> ODT: xml:id of a list => its id (for text:continue-list) */
    private array $odt_list_xml_ids = [];

    /**
     * Bytes of text the reader may still produce (see take()). The budget is checked while the
     * text is built: an ODT can say "1000 spaces" in a few bytes (text:s), so a small file could
     * otherwise grow to gigabytes in memory before any limit applies.
     */
    private int $left = self::DEFAULT_BUDGET;

    /** Whether the budget ran out (the text is incomplete). */
    private bool $cut = false;

    /**
     * Returns the plain text of the document (see toText()), or an empty string if it contains
     * no text (e.g. only images).
     *
     * @param string $format    self::DOCX or self::ODT
     * @param int    $max_chars see extractBlocks()
     *
     * @throws RuntimeException if the file is not a readable document of that format
     */
    public function extract(string $file, string $format, int $max_chars = 0): string
    {
        return self::toText($this->extractBlocks($file, $format, $max_chars));
    }

    /**
     * Whether the last reading stopped because the text got longer than the maximum length (the
     * blocks then hold only the beginning of the document).
     */
    public function wasCut(): bool
    {
        return $this->cut;
    }

    /**
     * Reads the blocks of the document, in their order:
     * - ['type' => 'heading', 'level' => 1…6, 'text' => …]
     * - ['type' => 'paragraph', 'text' => …]
     * - ['type' => 'item', 'depth' => 1…, 'numbered' => bool, 'list' => id, 'text' => …] (list
     *   item; "list" tells the lists apart: items of another list start again at 1)
     * - ['type' => 'table', 'rows' => [[cell text, …], …]]
     * Texts may contain line breaks ("\n") and tabs ("\t"). Empty paragraphs are left out.
     *
     * Reading stops once the text gets longer than $max_chars characters (plus a margin); 0 = a
     * default of about 4 MB. wasCut() then says that the document was not read to its end.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException if the file is not a readable document of that format
     */
    public function extractBlocks(string $file, string $format, int $max_chars = 0): array
    {
        // Margin of 1 KB: the text is shortened to $max_chars later (toHtml(), toText()), the
        // margin lets that see that there was more
        $this->left = $max_chars > 0 ? $max_chars * self::BYTES_PER_CHAR + 1024 : self::DEFAULT_BUDGET;
        $this->cut  = false;

        if (!isset(self::CONTENT_FILE[$format])) {
            throw new RuntimeException(sprintf('Unknown format "%s".', $format));
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException(sprintf('File "%s" is not readable.', $file));
        }
        if (!self::isAvailable()) {
            throw new RuntimeException('Word and OpenDocument files need the PHP extensions zip and dom.');
        }

        $zip = new ZipArchive();
        // Password protected Word files are not ZIP archives (but encrypted OLE files), so they
        // fail here as well.
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The file is not a valid document (no ZIP archive).');
        }
        try {
            $this->format = $format;
            $content = self::readZipEntry($zip, self::CONTENT_FILE[$format], true);
            if ($format === self::DOCX) {
                $this->readDocxStyles($zip);
                $body = $content->getElementsByTagNameNS(self::NS_W, 'body')->item(0);
            } else {
                $this->odt_list_styles = [];
                $this->odt_lists       = 0;
                $this->odt_last_list   = '';
                $this->odt_list_xml_ids = [];
                $styles = self::readZipEntry($zip, 'styles.xml', false);
                if ($styles !== null) {
                    $this->readOdtListStyles($styles);
                }
                // The automatic styles of content.xml come last: they are the ones used by the text
                $this->readOdtListStyles($content);
                $body = $content->getElementsByTagNameNS(self::NS_OFFICE, 'text')->item(0);
            }
        } finally {
            $zip->close();
        }

        $blocks = [];
        if ($body instanceof DOMElement) {
            $this->walkBlocks($body, $blocks, 0, null);
        }

        return $blocks;
    }

    /**
     * Whether the PHP extensions needed for DOCX and ODT are installed (both are common, GLPI
     * itself uses zip for its marketplace).
     */
    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class) && class_exists(DOMDocument::class);
    }

    /**
     * Plain text of the blocks: headings and paragraphs separated by an empty line, list items
     * with "•" or their number, indented by their depth, table rows as lines with the cells
     * separated by tabs.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function toText(array $blocks): string
    {
        $out = '';
        // Numbers per list and level: a list interrupted by a paragraph continues its numbers
        $numbers  = [];
        $previous = '';
        foreach ($blocks as $block) {
            $type = Cast::string($block['type'] ?? '');
            // An empty line between blocks, but the items of one list follow each other directly
            if ($out !== '' && !($type === 'item' && $previous === 'item')) {
                $out .= "\n";
            }
            switch ($type) {
                case 'heading':
                case 'paragraph':
                    $out .= Cast::string($block['text'] ?? '') . "\n";
                    break;
                case 'item':
                    $depth  = max(1, Cast::int($block['depth'] ?? 1));
                    $number = self::count($numbers, Cast::string($block['list'] ?? ''), $depth);
                    $mark   = (bool) ($block['numbered'] ?? false) ? $number . '.' : '•';
                    $indent = str_repeat('  ', $depth - 1);
                    // Further lines of the item start below its text
                    $text = str_replace("\n", "\n" . $indent . str_repeat(' ', mb_strlen($mark) + 1), Cast::string($block['text'] ?? ''));
                    $out .= $indent . $mark . ' ' . $text . "\n";
                    break;
                case 'table':
                    foreach (is_array($block['rows'] ?? null) ? $block['rows'] : [] as $row) {
                        $out .= implode("\t", array_map(Cast::string(...), is_array($row) ? $row : [])) . "\n";
                    }
                    break;
            }
            $previous = $type;
        }

        return PdfTextExtractor::normalise($out);
    }

    /**
     * Counts an item of the list $list on level $depth and returns its number. The deeper levels
     * of that list start again at 1 with the next item there.
     *
     * @param array<string, array<int, int>> $numbers numbers so far, per list and level
     */
    private static function count(array &$numbers, string $list, int $depth): int
    {
        $levels = $numbers[$list] ?? [];
        // Only the levels down to this one are kept: "array_filter … ARRAY_FILTER_USE_KEY" filters by level
        $levels = array_filter($levels, static fn(int $level) => $level <= $depth, ARRAY_FILTER_USE_KEY);
        $levels[$depth] = ($levels[$depth] ?? 0) + 1;
        $numbers[$list] = $levels;

        return $levels[$depth];
    }

    /**
     * HTML of the blocks for a followup: <h3>…<h6>, <p>, nested <ul>/<ol> and <table>, shortened
     * to about $max_chars characters of text (whole blocks; the last one is cut).
     *
     * @param list<array<string, mixed>> $blocks
     *
     * @return array{0: string, 1: bool} the HTML and whether it was shortened
     */
    public static function toHtml(array $blocks, int $max_chars): array
    {
        $html      = '';
        $left      = $max_chars;
        $shortened = false;
        /** @var list<bool> $lists open lists, one entry per level: true = numbered (<ol>), false = bullets (<ul>) */
        $lists = [];
        // Id of the list open on each level (another list on the same level: a new <ol>/<ul>)
        $list_ids = [];
        /** @var array<int, bool> $open_item whether the last <li> of a level is still open (a deeper list goes into it) */
        $open_item = [];
        // Numbers per list and level (see count()): a numbered list continued after a paragraph
        // starts with its next number (<ol start="…">)
        $numbers = [];

        // Closes the open lists down to $to_depth levels
        $close_lists = static function (int $to_depth) use (&$html, &$lists, &$open_item): void {
            while (count($lists) > $to_depth) {
                $depth = count($lists);
                if ($open_item[$depth] ?? false) {
                    $html .= '</li>';
                }
                unset($open_item[$depth]);
                $html .= array_pop($lists) ? '</ol>' : '</ul>';
            }
        };

        foreach ($blocks as $block) {
            // Stop when the text is used up or a block was cut (no bits of the next block)
            if ($shortened) {
                break;
            }
            if ($left <= 0) {
                $shortened = true;
                break;
            }
            $type = Cast::string($block['type'] ?? '');
            if ($type === 'table') {
                $close_lists(0);
                [$table, $left, $cut] = self::tableHtml(is_array($block['rows'] ?? null) ? $block['rows'] : [], $left);
                $html     .= $table;
                $shortened = $cut;
                continue;
            }

            [$text, $cut] = PdfTextExtractor::truncate(Cast::string($block['text'] ?? ''), $left);
            $left     -= mb_strlen($text);
            $shortened = $cut;
            $inline    = self::inlineHtml($text);

            if ($type === 'item') {
                $depth    = max(1, min(self::MAX_LIST_DEPTH, Cast::int($block['depth'] ?? 1)));
                $numbered = (bool) ($block['numbered'] ?? false);
                $list_id  = Cast::string($block['list'] ?? '');
                $number   = self::count($numbers, $list_id, $depth);
                $close_lists($depth);
                // Same level, other list or other kind of list: a new list
                if (count($lists) === $depth && ($lists[$depth - 1] !== $numbered || ($list_ids[$depth] ?? '') !== $list_id)) {
                    $close_lists($depth - 1);
                }
                while (count($lists) < $depth) {
                    $level = count($lists);
                    // A deeper list belongs into an item of the level above (an empty one if
                    // the document starts deeper)
                    if ($level > 0 && !($open_item[$level] ?? false)) {
                        $html .= '<li>';
                        $open_item[$level] = true;
                    }
                    // Levels in between (the document skips one) get bullets
                    $kind    = $level + 1 === $depth ? $numbered : false;
                    $lists[] = $kind;
                    $list_ids[count($lists)] = $level + 1 === $depth ? $list_id : '';
                    $html   .= $kind ? ($level + 1 === $depth && $number > 1 ? '<ol start="' . $number . '">' : '<ol>') : '<ul>';
                }
                if ($open_item[$depth] ?? false) {
                    $html .= '</li>';
                }
                $html .= '<li>' . $inline;
                $open_item[$depth] = true;
                continue;
            }

            $close_lists(0);
            if ($type === 'heading') {
                $level = min(6, max(1, Cast::int($block['level'] ?? 1)) + self::HTML_HEADING_OFFSET);
                $html .= '<h' . $level . '>' . $inline . '</h' . $level . '>';
            } else {
                $html .= '<p>' . $inline . '</p>';
            }
        }
        $close_lists(0);

        return [$html, $shortened];
    }

    /**
     * HTML of a table, shortened when the text runs out ($left characters).
     *
     * @param array<mixed> $rows
     *
     * @return array{0: string, 1: int, 2: bool} the HTML, the characters left, whether it was cut
     */
    private static function tableHtml(array $rows, int $left): array
    {
        $html = '<table class="table table-sm table-bordered"><tbody>';
        $cut  = false;
        foreach ($rows as $row) {
            if ($left <= 0) {
                $cut = true;
                break;
            }
            $html .= '<tr>';
            foreach (is_array($row) ? $row : [] as $cell) {
                [$text, $short] = PdfTextExtractor::truncate(Cast::string($cell), max(0, $left));
                $left -= mb_strlen($text);
                $cut   = $cut || $short;
                $html .= '<td>' . self::inlineHtml($text) . '</td>';
            }
            $html .= '</tr>';
        }

        return [$html . '</tbody></table>', $left, $cut];
    }

    /**
     * Escaped text for HTML: line breaks become <br>, tabs a space (HTML has no tab stops).
     */
    private static function inlineHtml(string $text): string
    {
        return str_replace("\n", '<br>', htmlspecialchars(str_replace("\t", ' ', $text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Loads one XML file of the ZIP archive.
     *
     * @return ($required is true ? DOMDocument : DOMDocument|null)
     *
     * @throws RuntimeException if a required file is missing or cannot be read
     */
    private static function readZipEntry(ZipArchive $zip, string $entry, bool $required): ?DOMDocument
    {
        $stat = $zip->statName($entry);
        if ($stat === false) {
            if ($required) {
                throw new RuntimeException(sprintf('The document contains no "%s".', $entry));
            }
            return null;
        }
        if ($stat['size'] > self::MAX_XML_BYTES) {
            throw new RuntimeException(sprintf('The text of the document is too large (%d bytes unpacked).', $stat['size']));
        }
        // The DOM needs many times the size of the XML (see PdfTextExtractor::ensureMemory())
        PdfTextExtractor::ensureMemory($stat['size'] * self::DOM_MEMORY_FACTOR);
        $xml = $zip->getFromName($entry);
        if ($xml === false) {
            throw new RuntimeException(sprintf('"%s" could not be unpacked.', $entry));
        }

        // LIBXML_NONET: no network access. External entities are not loaded (no LIBXML_NOENT,
        // no LIBXML_DTDLOAD), so the file cannot read other files of the server (XXE).
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException(sprintf('The XML of "%s" could not be read.', $entry));
        }

        return $dom;
    }

    // ------------------------------------------------------------------ styles

    /**
     * DOCX: paragraph styles (word/styles.xml: name, base style, outline level, numbering) and the
     * kind of each numbering (word/numbering.xml: numId -> abstract numbering -> format per level).
     */
    private function readDocxStyles(ZipArchive $zip): void
    {
        $this->docx_styles    = [];
        $this->docx_numbering = [];

        $styles = self::readZipEntry($zip, 'word/styles.xml', false);
        if ($styles !== null) {
            foreach ($styles->getElementsByTagNameNS(self::NS_W, 'style') as $style) {
                if ($style->getAttributeNS(self::NS_W, 'type') !== 'paragraph') {
                    continue;
                }
                $outline = self::wValue($style, 'pPr/w:outlineLvl');
                $this->docx_styles[$style->getAttributeNS(self::NS_W, 'styleId')] = [
                    'name'      => strtolower(self::wValue($style, 'name')),
                    'based_on'  => self::wValue($style, 'basedOn'),
                    'outline'   => $outline === '' ? null : (int) $outline,
                    'num_id'    => self::wValue($style, 'pPr/w:numPr/w:numId'),
                    'num_level' => (int) self::wValue($style, 'pPr/w:numPr/w:ilvl'),
                ];
            }
        }

        $numbering = self::readZipEntry($zip, 'word/numbering.xml', false);
        if ($numbering === null) {
            return;
        }
        // Abstract numberings: level => numbered (any format but bullets and none)
        $abstract = [];
        foreach ($numbering->getElementsByTagNameNS(self::NS_W, 'abstractNum') as $definition) {
            $levels = [];
            foreach ($definition->getElementsByTagNameNS(self::NS_W, 'lvl') as $level) {
                $format = self::wValue($level, 'numFmt');
                $levels[(int) $level->getAttributeNS(self::NS_W, 'ilvl')] = !in_array($format, ['bullet', 'none', ''], true);
            }
            $abstract[$definition->getAttributeNS(self::NS_W, 'abstractNumId')] = $levels;
        }
        foreach ($numbering->getElementsByTagNameNS(self::NS_W, 'num') as $num) {
            $this->docx_numbering[$num->getAttributeNS(self::NS_W, 'numId')] = $abstract[self::wValue($num, 'abstractNumId')] ?? [];
        }
    }

    /**
     * Value (attribute w:val) of a child element given by a path below $element, e.g.
     * "pPr/w:numPr/w:numId" (the first part without prefix), or "" if it is missing.
     */
    private static function wValue(DOMElement $element, string $path): string
    {
        if ($element->ownerDocument === null) {
            return '';
        }
        $xpath = new DOMXPath($element->ownerDocument);
        $xpath->registerNamespace('w', self::NS_W);
        $found = $xpath->query('w:' . $path, $element);
        $first = $found !== false ? $found->item(0) : null;

        return $first instanceof DOMElement ? $first->getAttributeNS(self::NS_W, 'val') : '';
    }

    /**
     * ODT: whether each level of each list style is numbered (text:list-level-style-number with a
     * format) or bulleted (text:list-level-style-bullet, or a number style without format).
     */
    private function readOdtListStyles(DOMDocument $dom): void
    {
        foreach ($dom->getElementsByTagNameNS(self::NS_TEXT, 'list-style') as $style) {
            $levels = [];
            foreach ($style->childNodes as $level) {
                if (!$level instanceof DOMElement || $level->namespaceURI !== self::NS_TEXT) {
                    continue;
                }
                $levels[(int) $level->getAttributeNS(self::NS_TEXT, 'level')] = $level->localName === 'list-level-style-number'
                    && $level->getAttributeNS(self::NS_STYLE, 'num-format') !== '';
            }
            $this->odt_list_styles[$style->getAttributeNS(self::NS_STYLE, 'name')] = $levels;
        }
    }

    // ------------------------------------------------------------------ body

    /**
     * Walks the block elements below $parent and adds their blocks.
     *
     * @param list<array<string, mixed>> $blocks
     * @param int         $depth      depth of the list the elements are in (0 = none)
     * @param string|null $list_style ODT: style of that list
     */
    private function walkBlocks(DOMNode $parent, array &$blocks, int $depth, ?string $list_style): void
    {
        foreach ($parent->childNodes as $node) {
            // Budget spent: the rest of the document is not read
            if ($this->left <= 0) {
                $this->cut = true;
                return;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($this->format === self::DOCX) {
                $this->docxBlock($node, $blocks);
            } else {
                $this->odtBlock($node, $blocks, $depth, $list_style);
            }
        }
    }

    /**
     * DOCX: one element of the body (paragraph, table, or a container such as a content control).
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function docxBlock(DOMElement $node, array &$blocks): void
    {
        if ($node->namespaceURI === self::NS_MC && $node->localName === 'Fallback') {
            // Copy of the content in an older format (shown by old programs): read only once
            return;
        }
        if ($node->namespaceURI !== self::NS_W) {
            $this->walkBlocks($node, $blocks, 0, null);
            return;
        }
        switch ($node->localName) {
            case 'p':
                $this->docxParagraph($node, $blocks);
                return;
            case 'tbl':
                $blocks[] = ['type' => 'table', 'rows' => $this->tableRows($node, self::NS_W, 'tr', 'tc')];
                return;
            case 'sectPr':
            case 'del':
            case 'moveFrom':
                // Page settings; deleted and moved-away text of tracked changes
                return;
            default:
                // Content controls (sdt / sdtContent), custom XML, inserted text, …: their content
                $this->walkBlocks($node, $blocks, 0, null);
        }
    }

    /**
     * DOCX: a paragraph as a heading, list item or paragraph, after its style and numbering.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function docxParagraph(DOMElement $p, array &$blocks): void
    {
        $text = self::clean($this->inlineText($p));
        if ($text === '') {
            return;
        }

        $style_id = self::wValue($p, 'pPr/w:pStyle');
        $style    = $this->docx_styles[$style_id] ?? null;

        // Numbering of the paragraph itself, else of its style (e.g. "List Bullet"); numId 0 = none
        $num_id = self::wValue($p, 'pPr/w:numPr/w:numId');
        $level  = (int) self::wValue($p, 'pPr/w:numPr/w:ilvl');
        if ($num_id === '' && $style !== null) {
            $num_id = $style['num_id'];
            $level  = $style['num_level'];
        }
        if ($num_id !== '' && $num_id !== '0') {
            $blocks[] = [
                'type'     => 'item',
                'depth'    => min(self::MAX_LIST_DEPTH, $level + 1),
                'numbered' => $this->docx_numbering[$num_id][$level] ?? false,
                'list'     => 'docx-' . $num_id,
                'text'     => $text,
            ];
            return;
        }

        $heading  = $this->docxHeadingLevel($style_id, self::wValue($p, 'pPr/w:outlineLvl'));
        $blocks[] = $heading !== null ? ['type' => 'heading', 'level' => $heading, 'text' => $text] : ['type' => 'paragraph', 'text' => $text];
    }

    /**
     * DOCX: heading level (1…6) of a paragraph, or null: from its outline level (0 = level 1,
     * 9 = body text), else from the name of its style or a style it is based on ("heading 2",
     * "title"; the names are English in every language of Word, the ids are not).
     */
    private function docxHeadingLevel(string $style_id, string $outline): ?int
    {
        if ($outline !== '') {
            return (int) $outline < 9 ? min(6, (int) $outline + 1) : null;
        }
        // Up the chain of base styles (at most 10 steps, against loops)
        for ($i = 0; $i < 10 && isset($this->docx_styles[$style_id]); $i++) {
            $style = $this->docx_styles[$style_id];
            if ($style['outline'] !== null) {
                return $style['outline'] < 9 ? min(6, $style['outline'] + 1) : null;
            }
            // "heading" + space + digits, e.g. "heading 1"
            if (preg_match('/^heading (\d+)$/', $style['name'], $match) === 1) {
                return min(6, max(1, (int) ($match[1] ?? 1)));
            }
            if ($style['name'] === 'title') {
                return 1;
            }
            $style_id = $style['based_on'];
        }

        return null;
    }

    /**
     * ODT: one element of the body (heading, paragraph, list, table, section, …).
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function odtBlock(DOMElement $node, array &$blocks, int $depth, ?string $list_style): void
    {
        $ns   = $node->namespaceURI;
        $name = $node->localName;

        if ($ns === self::NS_TEXT && ($name === 'h' || $name === 'p')) {
            $text = self::clean($this->inlineText($node));
            if ($text === '') {
                return;
            }
            if ($name === 'h') {
                $level    = (int) ($node->getAttributeNS(self::NS_TEXT, 'outline-level') ?: 1);
                $blocks[] = ['type' => 'heading', 'level' => min(6, max(1, $level)), 'text' => $text];
            } else {
                $blocks[] = ['type' => 'paragraph', 'text' => $text];
            }
            return;
        }
        if ($ns === self::NS_TEXT && $name === 'list') {
            // A nested list without own style continues the style of the list around it
            $style = $node->getAttributeNS(self::NS_TEXT, 'style-name') ?: $list_style;
            // A list that continues an earlier one (text:continue-list = its xml:id, or
            // text:continue-numbering = the list before) counts on with its numbers
            $continues = $this->odt_list_xml_ids[$node->getAttributeNS(self::NS_TEXT, 'continue-list')] ?? null;
            if ($continues === null && $depth === 0 && $node->getAttributeNS(self::NS_TEXT, 'continue-numbering') === 'true' && $this->odt_last_list !== '') {
                $continues = $this->odt_last_list;
            }
            $list_id = $continues ?? 'odt-' . ++$this->odt_lists;
            $xml_id  = $node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id');
            if ($xml_id !== '') {
                $this->odt_list_xml_ids[$xml_id] = $list_id;
            }
            if ($depth === 0) {
                $this->odt_last_list = $list_id;
            }
            foreach ($node->childNodes as $item) {
                if (!$item instanceof DOMElement || $item->namespaceURI !== self::NS_TEXT) {
                    continue;
                }
                if ($item->localName === 'list-item') {
                    $this->odtListItem($item, $blocks, $depth + 1, $style, $list_id);
                } elseif ($item->localName === 'list-header') {
                    // Heading of a list, without bullet or number: a paragraph (the list counts on after it)
                    $this->walkBlocks($item, $blocks, 0, null);
                }
            }
            return;
        }
        if ($ns === self::NS_TABLE && $name === 'table') {
            $blocks[] = ['type' => 'table', 'rows' => $this->tableRows($node, self::NS_TABLE, 'table-row', 'table-cell')];
            return;
        }
        if (($ns === self::NS_TEXT && in_array($name, ['tracked-changes', 'sequence-decls', 'variable-decls', 'user-field-decls', 'table-of-content', 'alphabetical-index'], true))
            || ($ns === self::NS_OFFICE && in_array($name, ['annotation', 'forms'], true))) {
            // Deleted text of tracked changes, declarations, generated tables of contents and
            // indexes (they repeat the headings), comments, form definitions
            return;
        }
        // Sections, soft page breaks, frames, …: their content
        $this->walkBlocks($node, $blocks, $depth, $list_style);
    }

    /**
     * ODT: a list item: its first paragraph is the item, further paragraphs are further lines of
     * the same item, nested lists go one level deeper.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function odtListItem(DOMElement $item, array &$blocks, int $depth, ?string $list_style, string $list_id): void
    {
        $item_index = null;
        foreach ($item->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->namespaceURI === self::NS_TEXT && ($child->localName === 'p' || $child->localName === 'h')) {
                $text = self::clean($this->inlineText($child));
                if ($text === '') {
                    continue;
                }
                if ($item_index === null) {
                    $blocks[] = [
                        'type'     => 'item',
                        'depth'    => min(self::MAX_LIST_DEPTH, $depth),
                        'numbered' => $this->odt_list_styles[(string) $list_style][$depth] ?? false,
                        'list'     => $list_id,
                        'text'     => $text,
                    ];
                    $item_index = array_key_last($blocks);
                } else {
                    $blocks[$item_index]['text'] = Cast::string($blocks[$item_index]['text']) . "\n" . $text;
                }
                continue;
            }
            // Nested list, table in an item, …
            $this->odtBlock($child, $blocks, $depth, $list_style);
            // Text after a nested list starts a new item of this level
            $item_index = null;
        }
    }

    /**
     * Rows of a table: per cell the text of its paragraphs (separated by spaces); nested tables
     * and lists in a cell are taken as their text.
     *
     * @return list<list<string>>
     */
    private function tableRows(DOMElement $table, string $ns, string $row_name, string $cell_name): array
    {
        $rows = [];
        foreach ($table->getElementsByTagNameNS($ns, $row_name) as $row) {
            // Only rows of this table, not of a table nested in one of its cells
            if (self::closest($row, $ns, (string) $table->localName) !== $table) {
                continue;
            }
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && $cell->namespaceURI === $ns && $cell->localName === $cell_name) {
                    $cells[] = self::clean(str_replace("\n", ' ', $this->cellText($cell)));
                }
            }
            if (array_filter($cells, static fn(string $cell) => $cell !== '') !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * Text of a table cell: the text of its paragraphs (also in lists and nested tables),
     * separated by spaces. Text boxes in a paragraph are part of its text (inlineText()), so the
     * walk does not go into paragraphs; copies for old programs (mc:Fallback), comments and
     * footnotes are skipped.
     */
    private function cellText(DOMNode $node): string
    {
        $parts = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $ns   = $child->namespaceURI;
            $name = $child->localName;
            if (($ns === self::NS_W && $name === 'p') || ($ns === self::NS_TEXT && ($name === 'p' || $name === 'h'))) {
                $parts[] = self::clean($this->inlineText($child));
            } elseif (!(($ns === self::NS_MC && $name === 'Fallback')
                || ($ns === self::NS_OFFICE && $name === 'annotation')
                || ($ns === self::NS_TEXT && in_array($name, ['note', 'tracked-changes'], true)))) {
                $parts[] = $this->cellText($child);
            }
        }

        return implode(' ', array_filter($parts, static fn(string $part) => $part !== ''));
    }

    /** Nearest ancestor of $node with the given name (namespace + local name), or null. */
    private static function closest(DOMNode $node, string $ns, string $name): ?DOMElement
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && $parent->namespaceURI === $ns && $parent->localName === $name) {
                return $parent;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ inline text

    /**
     * Text of a paragraph with its tabs and line breaks. Skipped: deleted text of tracked
     * changes, field codes, comments, footnotes and endnotes, copies for old programs.
     */
    private function inlineText(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($this->left <= 0) {
                $this->cut = true;
                break;
            }
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                if ($this->format === self::ODT) {
                    // In ODF, runs of spaces, tabs and line breaks in the XML count as one space
                    // (real ones are text:s, text:tab, text:line-break). [ \t\r\n]+ = one or more
                    $out .= $this->take(preg_replace('/[ \t\r\n]+/', ' ', (string) $child->nodeValue));
                } elseif (self::isDocxText($child)) {
                    $out .= $this->take((string) $child->nodeValue);
                }
                continue;
            }
            if ($child instanceof DOMElement) {
                $out .= $this->format === self::DOCX ? $this->docxInline($child) : $this->odtInline($child);
            }
        }

        return $out;
    }

    /** DOCX: text nodes count only inside w:t (elsewhere there is only indentation). */
    private static function isDocxText(DOMNode $node): bool
    {
        $parent = $node->parentNode;

        return $parent instanceof DOMElement && $parent->namespaceURI === self::NS_W && $parent->localName === 't';
    }

    private function docxInline(DOMElement $element): string
    {
        if ($element->namespaceURI === self::NS_MC && $element->localName === 'Fallback') {
            return '';
        }
        if ($element->namespaceURI === self::NS_W) {
            switch ($element->localName) {
                case 'tab':
                case 'ptab':
                    return $this->take("\t");
                case 'br':
                case 'cr':
                    return $this->take("\n");
                case 'noBreakHyphen':
                    return $this->take('-');
                case 'p':
                    // Paragraph of a text box inside the paragraph: on a new line
                    return $this->take("\n") . $this->inlineText($element);
                case 'del':
                case 'moveFrom':
                case 'delText':
                case 'instrText':
                case 'delInstrText':
                case 'pPr':
                case 'rPr':
                case 'footnoteReference':
                case 'endnoteReference':
                case 'commentReference':
                    return '';
            }
        }

        // Runs, hyperlinks, inserted text, content controls, drawings with text boxes, …
        return $this->inlineText($element);
    }

    private function odtInline(DOMElement $element): string
    {
        $ns   = $element->namespaceURI;
        $name = $element->localName;
        if ($ns === self::NS_TEXT) {
            switch ($name) {
                case 's':
                    // text:c = number of spaces (default 1), at most 1000 per element and never
                    // more than the budget left (many such elements would multiply the 1000)
                    $count = max(1, min(1000, (int) ($element->getAttributeNS(self::NS_TEXT, 'c') ?: 1)));
                    return $this->take(str_repeat(' ', min($count, max(0, $this->left))));
                case 'tab':
                    return $this->take("\t");
                case 'line-break':
                    return $this->take("\n");
                case 'p':
                case 'h':
                    // Paragraph of a text frame inside the paragraph: on a new line
                    return $this->take("\n") . $this->inlineText($element);
                case 'note':
                case 'tracked-changes':
                case 'change':
                    // Footnotes and endnotes, change marks
                    return '';
            }
        }
        if ($ns === self::NS_OFFICE && $name === 'annotation') {
            return '';
        }

        // Spans, links, bookmarks, fields, frames, …
        return $this->inlineText($element);
    }

    /**
     * Trims a text of the document: no spaces or tabs at the start and end of its lines, no
     * empty lines. Spaces inside a line are kept (also several in a row: they are intended).
     */
    private static function clean(string $text): string
    {
        // Line by line (no regular expression: a pattern around line breaks would need time
        // growing with the square of a long run of spaces)
        $lines = array_map(static fn(string $line) => trim($line, " \t"), explode("\n", $text));

        return implode("\n", array_filter($lines, static fn(string $line) => $line !== ''));
    }

    /**
     * Counts a piece of text against the budget and returns it, or only its beginning (cut at a
     * whole UTF-8 character) when the budget does not reach that far.
     */
    private function take(string $piece): string
    {
        if ($this->left <= 0) {
            $this->cut = $this->cut || $piece !== '';
            return '';
        }
        if (strlen($piece) > $this->left) {
            // mb_strcut: at most $this->left bytes, without splitting a character
            $piece     = mb_strcut($piece, 0, $this->left, 'UTF-8');
            $this->cut = true;
        }
        $this->left -= strlen($piece);

        return $piece;
    }
}
