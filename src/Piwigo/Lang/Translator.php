<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Gettext\Generator\ArrayGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translator as GettextTranslator;
use Gettext\Translations;

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

    private GettextTranslator $inner;

    private ArrayGenerator $generator;

    public function __construct()
    {
        $this->inner     = new GettextTranslator();
        $this->generator = new ArrayGenerator();
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

        $translations = (new PoLoader())->loadFile($poFile);

        // Merge into the inner translator via its array format
        $this->inner->addTranslations($this->generator->generateArray($translations));

        // Mirror into $GLOBALS['lang'] so plugin/theme code that reads the
        // global directly still gets the translated strings.
        $this->mirrorToGlobal($translations);
    }

    public function translate(string $key, mixed ...$args): string
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

        $scalarArgs = array_map(
            static fn (mixed $a): string => is_scalar($a) || $a === null ? (string) $a : '',
            $args
        );
        return vsprintf($val, $scalarArgs);
    }

    public function plural(string $singular, string $plural, int $n, mixed ...$args): string
    {
        $val = $this->inner->ngettext($singular, $plural, $n);

        if (empty($args)) {
            return sprintf($val, $n);
        }

        $scalarArgs = array_map(
            static fn (mixed $a): string => is_scalar($a) || $a === null ? (string) $a : '',
            $args
        );
        return vsprintf($val, [$n, ...$scalarArgs]);
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
    }
}
