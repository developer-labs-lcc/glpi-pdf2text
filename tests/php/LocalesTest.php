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

// Translations: every .po file has all texts of pdf2text.pot and its compiled .mo, GLPI loads
// the plugin texts in each language (no fallback to the system language), and the plural rules
// pick the right form (Russian and Polish have three forms: 1 / 2–4 / 5 and more, 12–14 too).

require __DIR__ . '/bootstrap.php';

$locales = dirname(__DIR__, 2) . '/locales';

/**
 * The msgids of a .po or .pot file ("msgid" lines, without the header).
 *
 * @return list<string>
 */
$msgids = static function (string $file): array {
    // ^msgid "(.+)"$ = a msgid line with a non-empty text (the header has an empty msgid)
    preg_match_all('/^msgid "(.+)"$/m', (string) file_get_contents($file), $match);
    return $match[1];
};

section('files');
$template = $msgids("$locales/pdf2text.pot");
check('template has texts', count($template) > 30, (string) count($template));
$files = glob("$locales/*.po") ?: [];
foreach ($files as $po) {
    $lang = basename($po, '.po');
    $missing = array_diff($template, $msgids($po));
    check("$lang: all texts of the template", $missing === [], implode(' | ', $missing));
    check("$lang: compiled .mo not older than the .po", is_file("$locales/$lang.mo") && filemtime("$locales/$lang.mo") >= filemtime($po));
}
// Every regional variant GLPI offers of the translated languages has its own file
global $CFG_GLPI;
$languages = array_unique(array_map(static fn(string $po) => substr(basename($po, '.po'), 0, 2), $files));
foreach (array_keys($CFG_GLPI['languages']) as $code) {
    if (in_array(substr((string) $code, 0, 2), $languages, true) && !in_array(substr((string) $code, 0, 2), ['ca', 'eu', 'gl'], true)) {
        check("GLPI language $code has a file", is_file("$locales/$code.po"));
    }
}

section('texts in GLPI');
$expected = [
    'fr_FR'  => 'Ajouter à nouveau',
    'fr_CA'  => 'Ajouter à nouveau',
    'pt_BR'  => 'Adicionar novamente',
    'pt_PT'  => 'Adicionar novamente',
    'es_ES'  => 'Añadir de nuevo',
    'es_419' => 'Añadir de nuevo',
    'es_MX'  => 'Añadir de nuevo',
    'ru_RU'  => 'Добавить снова',
    'pl_PL'  => 'Dodaj ponownie',
    'it_IT'  => 'Aggiungi di nuovo',
    'de_DE'  => 'Erneut hinzufügen',
    'en_US'  => 'Add again',
];
foreach ($expected as $lang => $text) {
    Session::loadLanguage($lang);
    check("$lang: plugin text in this language", __('Add again', 'pdf2text') === $text, __('Add again', 'pdf2text'));
}

section('plural forms');
$plural = static fn(int $n): string => sprintf(_n('%d followup added', '%d followups added', $n, 'pdf2text'), $n);
Session::loadLanguage('ru_RU');
$russian = implode(' | ', array_map($plural, [1, 2, 5, 11, 12, 21, 22, 25]));
check('Russian: 1, 2, 5, 11, 12, 21, 22, 25', $russian === '1 комментарий добавлен | 2 комментария добавлено | 5 комментариев добавлено | 11 комментариев добавлено | 12 комментариев добавлено | 21 комментарий добавлен | 22 комментария добавлено | 25 комментариев добавлено', $russian);
Session::loadLanguage('pl_PL');
$polish = implode(' | ', array_map($plural, [1, 2, 5, 12, 22, 25]));
check('Polish: 1, 2, 5, 12, 22, 25', $polish === '1 dalsze działanie dodane | 2 dalsze działania dodane | 5 dalszych działań dodanych | 12 dalszych działań dodanych | 22 dalsze działania dodane | 25 dalszych działań dodanych', $polish);
Session::loadLanguage('fr_FR');
check('French: 0 and 1 singular, 2 plural', implode(' | ', array_map($plural, [0, 1, 2])) === '0 suivi ajouté | 1 suivi ajouté | 2 suivis ajoutés', implode(' | ', array_map($plural, [0, 1, 2])));
Session::loadLanguage('es_ES');
check('Spanish: 1 singular, 0 and 2 plural', implode(' | ', array_map($plural, [0, 1, 2])) === '0 seguimientos añadidos | 1 seguimiento añadido | 2 seguimientos añadidos', implode(' | ', array_map($plural, [0, 1, 2])));
Session::loadLanguage('en_GB');

finish();
