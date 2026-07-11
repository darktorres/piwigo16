<?php

declare(strict_types=1);

require_once __DIR__ . '/plural-forms.php';

// Converts a single .lang.php file to a PO string. Plural pairs (from
// extract-pairs.php) become msgid/msgid_plural entries with msgstr[0]/
// msgstr[1] -- only ever 2 forms, even for a 3+-plural-form locale (e.g.
// Russian, Arabic): the source .lang.php files only ever carried a flat
// singular/plural pair per concept (Piwigo's legacy l10n_dec() never
// supported more), so there is no 3rd/4th/5th/6th form to draw from. The
// PO header still declares the locale's real nplurals count (correct
// metadata for any consumer that reads it), it's only the msgstr[]
// content that stays 2-form. gettext/translator's own Translator::
// translatePlural() already degrades gracefully for a missing index
// (falls back to the untranslated English plural string), so this is a
// lossy-but-safe migration, not a crash risk -- confirmed via
// TranslatorTest's own 3-form coverage.

/**
 * @param array<string, string> $pairs singular => plural pairs from extract-pairs.php
 */
function convert_lang_php_to_po(string $phpFile, string $locale, array $pairs): ?string
{
    if (! is_readable($phpFile)) {
        return null;
    }

    $lang = [];
    $lang_info = [];

    (static function () use ($phpFile, &$lang, &$lang_info): void {
        include $phpFile;
    })();

    if (empty($lang)) {
        return null;
    }

    $pluralForm = get_plural_form($locale);
    $langName = is_string($lang_info['language_name'] ?? null) ? $lang_info['language_name'] : '';
    $country = is_string($lang_info['country'] ?? null) ? $lang_info['country'] : '';
    $direction = is_string($lang_info['direction'] ?? null) ? $lang_info['direction'] : 'ltr';
    $code = is_string($lang_info['code'] ?? null) ? $lang_info['code'] : '';
    $zeroPlural = ! empty($lang_info['zero_plural']) ? 'true' : 'false';
    // parent: real fallback-chain data (5 locales, e.g. en_GB -> en_UK),
    // read by get_parent_language()/load_language(). jquery_code/
    // plupload_code: real, actively read by admin Smarty templates
    // (datepicker.inc.tpl, photos_add_direct.tpl) to pick the right
    // jQuery UI/plupload locale JS file -- losing these would silently
    // break admin-panel JS localization for every non-English-code
    // locale that has one. 'charset' is NOT preserved: verified zero
    // real consumers anywhere outside the .lang.php files themselves.
    $parent = is_string($lang_info['parent'] ?? null) ? $lang_info['parent'] : '';
    $jqueryCode = is_string($lang_info['jquery_code'] ?? null) ? $lang_info['jquery_code'] : '';
    $pluploadCode = is_string($lang_info['plupload_code'] ?? null) ? $lang_info['plupload_code'] : '';

    $po = '';
    $po .= "# Piwigo translation — {$locale}\n";
    $po .= '# Source: ' . basename($phpFile) . "\n";
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
    if ($parent !== '') {
        $po .= "\"X-Piwigo-Parent: {$parent}\\n\"\n";
    }
    if ($jqueryCode !== '') {
        $po .= "\"X-Piwigo-Jquery-Code: {$jqueryCode}\\n\"\n";
    }
    if ($pluploadCode !== '') {
        $po .= "\"X-Piwigo-Plupload-Code: {$pluploadCode}\\n\"\n";
    }
    if ($direction !== '') {
        $po .= "\"X-Piwigo-Direction: {$direction}\\n\"\n";
    }
    if ($code !== '') {
        $po .= "\"X-Piwigo-Code: {$code}\\n\"\n";
    }
    $po .= "\"X-Piwigo-Zero-Plural: {$zeroPlural}\\n\"\n";
    $po .= "\n";

    // 'day'/'month' are the two array-valued special entries in $lang --
    // day-of-week/month names, read index-by-index (Lang::day()/month()'s
    // $GLOBALS['lang']['day'][N]/['month'][N] contract). PO has no array
    // concept, so each index becomes its own piwigo_day_N/piwigo_month_N
    // msgid/msgstr pair; Translator::mirrorToGlobal() reassembles them back
    // into the nested array shape on load.
    $days = is_array($lang['day'] ?? null) ? $lang['day'] : [];
    $months = is_array($lang['month'] ?? null) ? $lang['month'] : [];
    foreach ($days as $i => $name) {
        if (is_int($i) && is_string($name) && $name !== '') {
            $lang['piwigo_day_' . $i] = $name;
        }
    }
    foreach ($months as $m => $name) {
        if (is_int($m) && is_string($name) && $name !== '') {
            $lang['piwigo_month_' . $m] = $name;
        }
    }

    $emitted = [];
    $pluralToSingular = array_flip($pairs);

    foreach ($lang as $msgid => $msgstr) {
        if (! is_string($msgid) || ! is_string($msgstr)) {
            // Skip other array-valued special entries, if any.
            continue;
        }
        if (isset($emitted[$msgid])) {
            continue;
        }

        if (isset($pairs[$msgid])) {
            $pluralKey = $pairs[$msgid];
            $msgstrPlural = isset($lang[$pluralKey]) && is_string($lang[$pluralKey])
                ? $lang[$pluralKey]
                : $pluralKey;

            $po .= 'msgid ' . po_quote_fn($msgid) . "\n";
            $po .= 'msgid_plural ' . po_quote_fn($pluralKey) . "\n";
            $po .= 'msgstr[0] ' . po_quote_fn($msgstr) . "\n";
            $po .= 'msgstr[1] ' . po_quote_fn($msgstrPlural) . "\n";
            $po .= "\n";

            $emitted[$msgid] = true;
            $emitted[$pluralKey] = true;
            continue;
        }

        if (isset($pluralToSingular[$msgid]) && isset($lang[$pluralToSingular[$msgid]])) {
            // Plural half of a known pair -- already emitted above.
            $emitted[$msgid] = true;
            continue;
        }

        $po .= 'msgid ' . po_quote_fn($msgid) . "\n";
        $po .= 'msgstr ' . po_quote_fn($msgstr) . "\n";
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
