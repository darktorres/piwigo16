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
 * etc). $mirror is a flat string map kept in sync (via mirror()) as
 * translate()'s own fallback for keys gettext has no entry for -- "nothing
 * is frozen" gap-closure (2026-07-22): this used to be $GLOBALS['lang'],
 * justified as "for plugin/theme code that reads it directly," but nothing
 * in-tree (nor any *code*, as opposed to a docblock claim) ever read that
 * global independently of this class and Piwigo\Core\Lang -- external
 * plugin/theme compatibility is never a valid reason to keep an in-tree
 * global either way. Converted to a real private property; Piwigo\Core\
 * Lang now pulls from mirroredStrings() instead of reading the global.
 */
final class Translator
{
    private static ?self $instance = null;

    private readonly GettextTranslator $inner;

    /**
     * Flat string map, kept in sync by mirror() -- see this class's own
     * docblock. 'day'/'month' entries are nested list<string>, matching
     * Piwigo\Core\Lang::$data's own documented exception.
     *
     * @var array<string, string|array<int, string>>
     */
    private array $mirror = [];

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
     * mirrors all strings into $mirror, translate()'s own fallback.
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

        $this->mirror($translations);

        return $translations;
    }

    /**
     * The flat string map load() maintains as translate()'s own fallback --
     * see this class's own docblock. Piwigo\Core\Lang::attachGlobals()
     * pulls from here.
     *
     * @return array<string, string|array<int, string>>
     */
    public function mirroredStrings(): array
    {
        return $this->mirror;
    }

    public function translate(string $key, mixed ...$args): string
    {
        $val = $this->inner->gettext($key);

        // gettext() returns the original when not found -- fall back to
        // $mirror, which may have been populated by a PHP lang file (e.g.
        // from a plugin without a .po file yet).
        if ($val === $key && isset($this->mirror[$key]) && is_string($this->mirror[$key])) {
            $val = $this->mirror[$key];
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

    private function mirror(Translations $translations): void
    {
        // php-to-po-fn.php flattens $lang['day'][N]/$lang['month'][N] --
        // array-valued entries with no PO equivalent -- into piwigo_day_N/
        // piwigo_month_N string entries. Captured here and reassembled
        // below into the nested shape Lang::day()/month() (via
        // $mirror['day'][N]/['month'][N]) expect -- $mirror's own value
        // type is a string|array<int,string> union, so accumulating in a
        // plain local array first and assigning the whole nested value
        // once avoids PHPStan needing to narrow $mirror['day'] to its
        // array branch on every element write.
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
                $this->mirror[$original] = $str;

                if (preg_match('/^piwigo_day_(\d+)$/', $original, $matches) === 1) {
                    $days[(int) $matches[1]] = $str;
                } elseif (preg_match('/^piwigo_month_(\d+)$/', $original, $matches) === 1) {
                    $months[(int) $matches[1]] = $str;
                }
            }

            // getPluralTranslations()[0] is msgstr[1] -- the translation
            // matching the plural English key -- NOT msgstr[0] (that's
            // getTranslation(), handled above). See toDictionaryEntry()'s
            // docblock for how this was confirmed. gettext/gettext's own
            // Translation::$pluralTranslations has no property/return type
            // (bare `array`), so $pluralForms[0] is genuinely unverified
            // mixed to PHPStan -- a real gap $mirror's own now-real typed
            // property surfaces (previously hidden: $GLOBALS['lang'] was
            // mixed to PHPStan regardless).
            $pluralOriginal = $entry->getPlural();
            $pluralForms = $entry->getPluralTranslations();
            $pluralForm0 = $pluralForms[0] ?? null;

            if ($pluralOriginal !== null && $pluralOriginal !== '' && is_string($pluralForm0) && $pluralForm0 !== '') {
                $this->mirror[$pluralOriginal] = $pluralForm0;
            }
        }

        if ($days !== []) {
            $this->mirror['day'] = $days;
        }
        if ($months !== []) {
            $this->mirror['month'] = $months;
        }
    }

    /**
     * Test-only. Seeds $mirror directly, matching Piwigo\Core\Lang::
     * loadArray()'s own test-helper shape, for tests that need
     * translate()'s fallback populated without a real PO file.
     *
     * @param array<string, string|array<int, string>> $data
     */
    public function loadArray(array $data): void
    {
        $this->mirror = $data;
    }
}
