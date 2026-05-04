<?php

/**
 * Convert a Piwigo .lang.php file to a PO file.
 *
 * Usage:
 *   php tools/i18n/php-to-po.php <source.lang.php> <locale> [pairs-json]
 *
 * Outputs the PO content to stdout.
 */

declare(strict_types=1);

require __DIR__ . '/extract-pairs.php';
require __DIR__ . '/plural-forms.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php php-to-po.php <source.lang.php> <locale> [pairs.json]\n");
    exit(1);
}

$sourceFile = $argv[1];
$locale     = $argv[2];
$pairsFile  = $argv[3] ?? null;

if (!is_readable($sourceFile)) {
    fwrite(STDERR, "Cannot read: $sourceFile\n");
    exit(1);
}

// Load plural pairs
$pairs = [];
if ($pairsFile && is_readable($pairsFile)) {
    $pairs = json_decode(file_get_contents($pairsFile), true) ?: [];
} else {
    $rootDir = dirname(__DIR__, 2); // repo root
    $pairs = extract_plural_pairs($rootDir);
}

// Load the lang file in an isolated scope
$lang = [];
$lang_info = [];
(static function () use ($sourceFile, &$lang, &$lang_info): void {
    include $sourceFile;
})();

$pluralForm = get_plural_form($locale);
$langName   = is_string($lang_info['language_name'] ?? null) ? $lang_info['language_name'] : '';
$country    = is_string($lang_info['country'] ?? null) ? $lang_info['country'] : '';
$direction  = is_string($lang_info['direction'] ?? null) ? $lang_info['direction'] : 'ltr';
$code       = is_string($lang_info['code'] ?? null) ? $lang_info['code'] : '';
$zeroPl     = !empty($lang_info['zero_plural']) ? 'true' : 'false';

// PO header
$po = '';
$po .= "# Piwigo translation — {$locale}\n";
$po .= "# Source: " . basename($sourceFile) . "\n";
$po .= "msgid \"\"\n";
$po .= "msgstr \"\"\n";
$po .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$po .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$po .= "\"Language: {$locale}\\n\"\n";
$po .= "\"Plural-Forms: {$pluralForm}\\n\"\n";
if ($langName !== '') {
    $po .= "\"X-Piwigo-Language-Name: {$langName}\\n\"\n";
}
if ($country !== '') {
    $po .= "\"X-Piwigo-Country: {$country}\\n\"\n";
}
if ($direction !== '') {
    $po .= "\"X-Piwigo-Direction: {$direction}\\n\"\n";
}
if ($code !== '') {
    $po .= "\"X-Piwigo-Code: {$code}\\n\"\n";
}
$po .= "\"X-Piwigo-Zero-Plural: {$zeroPl}\\n\"\n";
$po .= "\n";

// Track which keys we've already emitted (singular of a pair must not be re-emitted standalone)
$emitted = [];

// Reverse pairs map: plural → singular
$pluralToSingular = array_flip($pairs);

foreach ($lang as $msgid => $msgstr) {
    if (!is_string($msgid) || !is_string($msgstr)) {
        continue;
    }

    // Skip special array-valued keys (e.g. 'day', 'month')
    if (is_array($msgstr)) {
        continue;
    }

    if (isset($emitted[$msgid])) {
        continue;
    }

    // Is this key the singular of a known plural pair?
    if (isset($pairs[$msgid])) {
        $pluralKey = $pairs[$msgid];
        $msgstrSingular = $msgstr;
        $msgstrPlural   = isset($lang[$pluralKey]) && is_string($lang[$pluralKey])
            ? $lang[$pluralKey]
            : $pluralKey;

        $po .= "msgid " . po_quote($msgid) . "\n";
        $po .= "msgid_plural " . po_quote($pluralKey) . "\n";
        $po .= "msgstr[0] " . po_quote($msgstrSingular) . "\n";
        $po .= "msgstr[1] " . po_quote($msgstrPlural) . "\n";
        $po .= "\n";

        $emitted[$msgid] = true;
        $emitted[$pluralKey] = true;
        continue;
    }

    // Is this key the plural of a known pair whose singular we haven't seen yet?
    // (Shouldn't happen if lang files are complete, but guard anyway.)
    if (isset($pluralToSingular[$msgid])) {
        // Will be handled when we encounter the singular key. Skip for now — but if
        // the singular doesn't exist in this file, emit standalone.
        $singularKey = $pluralToSingular[$msgid];
        if (isset($lang[$singularKey])) {
            // Singular exists — it will handle this entry when reached.
            $emitted[$msgid] = true;
            continue;
        }
    }

    // Standalone entry
    $po .= "msgid " . po_quote($msgid) . "\n";
    $po .= "msgstr " . po_quote($msgstr) . "\n";
    $po .= "\n";
    $emitted[$msgid] = true;
}

echo $po;

// ---------------------------------------------------------------------------

function po_quote(string $s): string
{
    $s = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $s);
    return '"' . $s . '"';
}
