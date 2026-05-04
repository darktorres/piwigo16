<?php

declare(strict_types=1);

require_once __DIR__ . '/plural-forms.php';

/**
 * Convert a single .lang.php file to PO string.
 *
 * @param array<string,string> $pairs  singular→plural pairs from extract-pairs.php
 */
function convert_lang_php_to_po(string $phpFile, string $locale, array $pairs): ?string
{
    if (!is_readable($phpFile)) {
        return null;
    }

    $lang      = [];
    $lang_info = [];

    (static function () use ($phpFile, &$lang, &$lang_info): void {
        include $phpFile;
    })();

    if (empty($lang)) {
        return null;
    }

    $pluralForm = get_plural_form($locale);
    $langName   = is_string($lang_info['language_name'] ?? null) ? $lang_info['language_name'] : '';
    $country    = is_string($lang_info['country'] ?? null) ? $lang_info['country'] : '';
    $direction  = is_string($lang_info['direction'] ?? null) ? $lang_info['direction'] : 'ltr';
    $code       = is_string($lang_info['code'] ?? null) ? $lang_info['code'] : '';
    $zeroPl     = !empty($lang_info['zero_plural']) ? 'true' : 'false';

    $po = '';
    $po .= "# Piwigo translation — {$locale}\n";
    $po .= "# Source: " . basename($phpFile) . "\n";
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

    $emitted         = [];
    $pluralToSingular = array_flip($pairs);

    foreach ($lang as $msgid => $msgstr) {
        if (!is_string($msgid)) {
            continue;
        }
        // Skip array-valued special entries (e.g. 'day', 'month')
        if (!is_string($msgstr)) {
            continue;
        }
        if (isset($emitted[$msgid])) {
            continue;
        }

        // Singular of a known plural pair
        if (isset($pairs[$msgid])) {
            $pluralKey     = $pairs[$msgid];
            $msgstrSingular = $msgstr;
            $msgstrPlural   = isset($lang[$pluralKey]) && is_string($lang[$pluralKey])
                ? $lang[$pluralKey]
                : $pluralKey;

            $po .= "msgid " . po_quote_fn($msgid) . "\n";
            $po .= "msgid_plural " . po_quote_fn($pluralKey) . "\n";
            $po .= "msgstr[0] " . po_quote_fn($msgstrSingular) . "\n";
            $po .= "msgstr[1] " . po_quote_fn($msgstrPlural) . "\n";
            $po .= "\n";

            $emitted[$msgid]    = true;
            $emitted[$pluralKey] = true;
            continue;
        }

        // Plural key whose singular exists — skip, will be handled by singular
        if (isset($pluralToSingular[$msgid]) && isset($lang[$pluralToSingular[$msgid]])) {
            $emitted[$msgid] = true;
            continue;
        }

        // Standalone entry
        $po .= "msgid " . po_quote_fn($msgid) . "\n";
        $po .= "msgstr " . po_quote_fn($msgstr) . "\n";
        $po .= "\n";
        $emitted[$msgid] = true;
    }

    return $po;
}

function po_quote_fn(string $s): string
{
    $s = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $s);
    return '"' . $s . '"';
}
