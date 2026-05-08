<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Gettext\Generator\ArrayGenerator;
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
 * Multiple load() calls accumulate translations (common + admin + upgrade etc.).
 * The $GLOBALS['lang'] array is kept in sync for plugin/theme code that reads
 * it directly.
 */
final class Translator
{
    private static ?self $instance = null;

    /** @var list<self> */
    private static array $stack = [];

    /** @var array<string, self> saved Translator per language code */
    private static array $saved = [];

    private readonly GettextTranslator $inner;

    private readonly ArrayGenerator $generator;

    public function __construct()
    {
        $this->inner     = new GettextTranslator();
        $this->generator = new ArrayGenerator();
        // Seed an empty domain so GettextTranslator::$domain is never null,
        // which would trigger "Using null as array offset" deprecation in PHP 8.x
        // when translate() is called before any PO file has been loaded.
        $this->inner->addTranslations(['domain' => '', 'messages' => [], 'plural-forms' => '']);
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function set(self $translator): void
    {
        self::$instance = $translator;
    }

    public static function reset(): void
    {
        self::$instance = null;
        self::$stack    = [];
        self::$saved    = [];
    }

    /**
     * Push a fresh Translator onto the stack (used by switch_lang_to).
     * The current instance is saved so pop() can restore it.
     */
    public static function pushFresh(): void
    {
        self::$stack[] = self::get();
        self::$instance = new self();
    }

    /**
     * Restore the Translator that was active before the last pushFresh().
     */
    public static function pop(): void
    {
        if (!empty(self::$stack)) {
            self::$instance = array_pop(self::$stack);
        }
    }

    /**
     * Associate the current Translator instance with a language code so it
     * can be restored later without reloading PO files.
     */
    public static function saveForLanguage(string $language): void
    {
        self::$saved[$language] = self::get();
    }

    /**
     * Restore the Translator previously saved for $language.
     * If none was saved, restores from the stack top (so $GLOBALS['lang'] takes over).
     */
    public static function restoreForLanguage(string $language): void
    {
        if (isset(self::$saved[$language])) {
            self::$instance = self::$saved[$language];
        }
    }

    /**
     * Load a PO file and merge its translations into the active set.
     * Also mirrors all strings into $GLOBALS['lang'] for backward compat.
     */
    public function load(string $locale, string $poFile): void
    {
        if (!is_readable($poFile)) {
            return;
        }

        $translations = new PoLoader()->loadFile($poFile);

        // Merge into the inner translator via its array format
        $this->inner->addTranslations($this->generator->generateArray($translations));

        // Mirror into $GLOBALS['lang'] so plugin/theme code that reads the
        // global directly still gets the translated strings.
        $this->mirrorToGlobal($translations);
    }

    public function translate(string $key, string|int|float|bool|null ...$args): string
    {
        $val = $this->inner->gettext($key);

        // gettext() returns the original when not found — fall back to $lang global
        // which may have been populated by PHP lang files (e.g. from plugins)
        if ($val === $key) {
            $raw    = $GLOBALS['lang'] ?? [];
            $global = is_array($raw) ? $raw : [];
            if (isset($global[$key]) && is_string($global[$key])) {
                $val = $global[$key];
            }
        }

        if (empty($args)) {
            return $val;
        }

        return vsprintf($val, array_map(strval(...), $args));
    }

    public function plural(string $singular, string $plural, int $n, string|int|float|bool|null ...$args): string
    {
        $val = $this->inner->ngettext($singular, $plural, $n);

        if (empty($args)) {
            return sprintf($val, $n);
        }

        return vsprintf($val, [$n, ...array_map(strval(...), $args)]);
    }

    private function mirrorToGlobal(Translations $translations): void
    {
        $ref = &$GLOBALS['lang'];
        if (!is_array($ref)) {
            $GLOBALS['lang'] = [];
            $ref             = &$GLOBALS['lang'];
        }

        foreach ($translations->getTranslations() as $entry) {
            if (!($entry instanceof Translation)) {
                continue;
            }

            $original = $entry->getOriginal();
            if ($original === '') {
                continue;
            }

            $str = $entry->getTranslation();
            if ($str !== null && $str !== '') {
                $ref[$original] = $str;
            }

            $pluralOriginal = $entry->getPlural();
            $pluralForms    = $entry->getPluralTranslations();

            if ($pluralOriginal !== null && $pluralOriginal !== '') {
                if (isset($pluralForms[0]) && is_string($pluralForms[0]) && $pluralForms[0] !== '') {
                    $ref[$original] = $pluralForms[0];
                }
                if (isset($pluralForms[1]) && is_string($pluralForms[1]) && $pluralForms[1] !== '') {
                    $ref[$pluralOriginal] = $pluralForms[1];
                }
            }
        }

        // Rebuild the $lang['day'] and $lang['month'] arrays that legacy callers
        // (Lang::day(), Lang::month(), admin/stats.php, etc.) read directly.
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $key = 'piwigo_day_' . $i;
            if (isset($ref[$key]) && is_string($ref[$key]) && $ref[$key] !== '') {
                $days[$i] = $ref[$key];
            }
        }
        if (!empty($days)) {
            $ref['day'] = $days;
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = 'piwigo_month_' . $m;
            if (isset($ref[$key]) && is_string($ref[$key]) && $ref[$key] !== '') {
                $months[$m] = $ref[$key];
            }
        }
        if (!empty($months)) {
            $ref['month'] = $months;
        }
    }
}
