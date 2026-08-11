<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Gettext\Languages\Language;

// Returns the gettext Plural-Forms string for a given Piwigo locale
// directory name (e.g. 'ru_RU'). Backed by gettext/languages, a real
// CLDR-derived plural-rule database (transitive dependency of gettext/
// gettext). Preferred over a hand-maintained static table: fewer
// transcription-risk lines, and it picks up CLDR corrections
// automatically (e.g. French is 3 plural forms in current CLDR, not the
// 2-form rule older references hardcode).
//
// buildFormula(true) asks for the "standard gettext format" (unparenthesized
// per nesting level, e.g. `n%10==1 ? 0 : n%10>=2 ? 1 : 2`) rather than the
// PHP-compatible default -- gettext/translator's own Translator::
// fixTerseIfs() is what turns that into a PHP-evaluable closure at
// runtime, and double-parenthesizing an already PHP-compatible formula
// through it is untested territory this project doesn't need to risk.
function get_plural_form(string $locale): string
{
    $baseLanguage = explode('_', $locale)[0];
    $language = Language::getById($locale) ?? Language::getById($baseLanguage);

    if ($language === null) {
        // No CLDR entry at all for this locale or its base language
        // (e.g. 'kok' / Konkani) -- fall back to the universal 2-form
        // English-shaped rule, matching gettext's own convention for
        // "no plural data available".
        return 'nplurals=2; plural=(n != 1);';
    }

    $nplurals = count($language->categories);
    $formula = $language->buildFormula(true);

    return "nplurals={$nplurals}; plural=({$formula});";
}
