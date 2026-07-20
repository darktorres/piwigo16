<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use Gettext\Translator as GettextTranslator;

/**
 * Piwigo translation service backed by gettext PO files.
 *
 * Wraps gettext/translator's Translator (pure-PHP, no ext-gettext required)
 * and uses gettext/gettext's PoLoader to parse PO files.
 *
 * `Gettext\Generator\ArrayGenerator` -- the class gettext/translator's own
 * `Translator::createFromTranslations()` expects to bridge a `Translations`
 * object into its internal dictionary array -- was removed from
 * gettext/gettext by v5.7 (confirmed absent from the installed v5.7.3:
 * only Generator/GeneratorInterface/MoGenerator/PoGenerator remain under
 * `Generator/`), even though gettext/translator v1.2.3's own composer.json
 * still declares `"gettext/gettext": "^5.0.0"` as compatible. `load()`
 * below builds gettext/translator's expected `['domain', 'plural-forms',
 * 'messages']` array shape directly instead of going through the missing
 * class -- same dictionary shape, one fewer (broken) indirection.
 *
 * Multiple load() calls accumulate translations (common + admin + upgrade
 * etc). The $GLOBALS['lang'] array is kept in sync (via mirrorToGlobal())
 * for plugin/theme code that reads it directly.
 */
final class Translator
{
    private static ?self $instance = null;

    private readonly GettextTranslator $inner;

    public function __construct()
    {
        $this->inner = new GettextTranslator();
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    public static function set(self $translator): void
    {
        self::$instance = $translator;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Load a PO file and merge its translations into the active set. Also
     * mirrors all strings into $GLOBALS['lang'] for backward compat.
     *
     * Returns the parsed Translations (or null if the file wasn't
     * readable) so callers that also need header metadata --
     * load_language()'s $lang_info population, e.g. X-Piwigo-Parent/
     * X-Piwigo-Zero-Plural -- don't have to parse the same file twice.
     */
    public function load(string $locale, string $poFile): ?Translations
    {
        if (! is_readable($poFile)) {
            return null;
        }

        $translations = new PoLoader()
            ->loadFile($poFile);

        $this->inner->addTranslations($this->toDictionaryEntry($translations));

        $this->mirrorToGlobal($translations);

        return $translations;
    }

    public function translate(string $key, mixed ...$args): string
    {
        $val = $this->inner->gettext($key);

        // gettext() returns the original when not found -- fall back to
        // $lang global, which may have been populated by PHP lang files
        // (e.g. from plugins without a .po file yet).
        if ($val === $key) {
            $raw = $GLOBALS['lang'] ?? [];
            $global = is_array($raw) ? $raw : [];
            if (isset($global[$key]) && is_string($global[$key])) {
                $val = $global[$key];
            }
        }

        // Legacy Coupling Retirement Phase 4d: moved here (from the
        // deleted l10n() free function) rather than staying in Lang::t() --
        // testing $val === $key after both real resolution paths above
        // (gettext() then $GLOBALS['lang']) are exhausted is strictly more
        // accurate than l10n()'s own former shallow `! isset($lang[$key])`
        // check (which only ever looked at the fallback array), and this
        // way every Lang::t() caller gets the diagnostic too, not just
        // former l10n() callers.
        if (\Piwigo\Config\Config::debugL10n() && $val === $key && $key !== '') {
            trigger_error('[l10n] language key "' . $key . '" not defined', E_USER_WARNING);
        }

        if ($args === []) {
            return $val;
        }

        $scalarArgs = array_map(
            static fn (mixed $a): string => is_scalar($a) || $a === null ? (string) $a : '',
            $args,
        );

        return vsprintf($val, $scalarArgs);
    }

    public function plural(string $singular, string $plural, int $n, mixed ...$args): string
    {
        $val = $this->inner->ngettext($singular, $plural, $n);

        if ($args === []) {
            return sprintf($val, $n);
        }

        $scalarArgs = array_map(
            static fn (mixed $a): string => is_scalar($a) || $a === null ? (string) $a : '',
            $args,
        );

        return vsprintf($val, [$n, ...$scalarArgs]);
    }

    /**
     * Builds the array shape gettext/translator's Translator::
     * addTranslations() expects: `['domain' => string, 'plural-forms' =>
     * 'nplurals=N; plural=EXPR;', 'messages' => [context => [original =>
     * list<string>]]]`. Mirrors what the removed ArrayGenerator produced
     * (verified against gettext/translator's own dictionary reader,
     * `Translator::getTranslation()`/`getPluralIndex()`): msgstr[0]
     * (`getTranslation()`) is a slot of its own, NOT included in
     * `getPluralTranslations()` -- confirmed empirically (a 3-plural-form
     * PO file's `getPluralTranslations()` returned only `[msgstr1,
     * msgstr2]`, msgstr0 came from `getTranslation()`), contradicting an
     * assumption the reference's own equivalent code made.
     *
     * @return array{domain: string, plural-forms: string, messages: array<string, array<string, list<string>>>}
     */
    private function toDictionaryEntry(Translations $translations): array
    {
        $messages = [];

        foreach ($translations->getTranslations() as $entry) {
            if (! ($entry instanceof Translation)) {
                continue;
            }

            $original = $entry->getOriginal();
            if ($original === '') {
                continue;
            }

            $context = $entry->getContext() ?? '';
            $plural = $entry->getPlural();
            $singularForm = $entry->getTranslation() ?? '';

            $forms = ($plural !== null && $plural !== '')
                ? [$singularForm, ...$entry->getPluralTranslations()]
                : [$singularForm];

            $messages[$context][$original] = array_values(
                array_map(static fn (mixed $f): string => is_string($f) ? $f : '', $forms),
            );
        }

        return [
            'domain' => $translations->getDomain() ?? '',
            'plural-forms' => $translations->getHeaders()
                ->get('Plural-Forms') ?? '',
            'messages' => $messages,
        ];
    }

    private function mirrorToGlobal(Translations $translations): void
    {
        $ref = &$GLOBALS['lang'];
        if (! is_array($ref)) {
            $GLOBALS['lang'] = [];
            $ref = &$GLOBALS['lang'];
        }

        // php-to-po-fn.php flattens $lang['day'][N]/$lang['month'][N] --
        // array-valued entries with no PO equivalent -- into piwigo_day_N/
        // piwigo_month_N string entries. Captured here (rather than read
        // back out of $ref, a $GLOBALS reference PHPStan can only ever see
        // as mixed, which makes a nested offset read -- needed to write
        // into $ref['day'][$i] -- an unfixable "access on mixed" error)
        // and reassembled below into the nested shape Lang::day()/month()
        // (via $GLOBALS['lang']['day'][N]/['month'][N]) expect.
        $days = [];
        $months = [];

        foreach ($translations->getTranslations() as $entry) {
            if (! ($entry instanceof Translation)) {
                continue;
            }

            $original = $entry->getOriginal();
            if ($original === '') {
                continue;
            }

            $str = $entry->getTranslation();
            if ($str !== null && $str !== '') {
                $ref[$original] = $str;

                if (preg_match('/^piwigo_day_(\d+)$/', $original, $matches) === 1) {
                    $days[(int) $matches[1]] = $str;
                } elseif (preg_match('/^piwigo_month_(\d+)$/', $original, $matches) === 1) {
                    $months[(int) $matches[1]] = $str;
                }
            }

            // getPluralTranslations()[0] is msgstr[1] -- the translation
            // matching the plural English key -- NOT msgstr[0] (that's
            // getTranslation(), handled above). See toDictionaryEntry()'s
            // docblock for how this was confirmed.
            $pluralOriginal = $entry->getPlural();
            $pluralForms = $entry->getPluralTranslations();

            if ($pluralOriginal !== null && $pluralOriginal !== '' && isset($pluralForms[0]) && $pluralForms[0] !== '') {
                $ref[$pluralOriginal] = $pluralForms[0];
            }
        }

        if ($days !== []) {
            $ref['day'] = $days;
        }
        if ($months !== []) {
            $ref['month'] = $months;
        }
    }
}
