<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Gettext\Headers;
use Gettext\Loader\PoLoader;
use Piwigo\Lang\Translator;

/**
 * Typed facade over the $lang global array.
 *
 * `self::$data` IS the same array as `$GLOBALS['lang']` (a reference,
 * established by `attachGlobals()`, matching `PageState`'s own bridge
 * pattern) -- the free function `l10n()` keeps reading `$lang` directly, so
 * old call sites and new call sites through `Lang::t()` see identical
 * translation strings without double-loading or divergence.
 *
 * `t()` delegates to `Piwigo\Lang\Translator` (gettext-backed, P16) rather
 * than reading `self::$data` directly -- `Translator::translate()` itself
 * falls back to the `$lang` array (via the same bridge) for keys with no
 * gettext entry, so both PHP-array-loaded and PO-loaded strings resolve
 * correctly through one call.
 *
 * P23 batch 8d: gained `load()` (ported from the legacy `load_language()`)
 * plus its two private helpers (`getParentLanguage()`/
 * `poHeadersToLangInfo()`, ported from `get_parent_language()`/
 * `po_headers_to_lang_info()` -- both had zero real external callers,
 * confirmed by grep, so neither needs to stay public) and `args()`/
 * `buildArgs()` (ported from `l10n_args()`/`get_l10n_args()`). See
 * `DefaultLanguageProviderInterface`'s own docblock for why `load()` needs
 * a static provider setter rather than a constructor dependency.
 */
final class Lang
{
    /**
     * $lang['day']/$lang['month'] are the two legacy exceptions to an
     * otherwise string-valued array -- a nested list<string> keyed by
     * day-of-week/month-of-year index (see Piwigo\Lang\Translator::
     * mirrorToGlobal(), which populates them from the piwigo_day_N/
     * piwigo_month_N PO entries). day()/month() below read them back out.
     *
     * @var array<string, string|array<int, string>>
     */
    private static array $data = [];

    private static ?DefaultLanguageProviderInterface $defaultLanguageProvider = null;

    private static ?HtmlRenderingInterface $htmlRenderer = null;

    /**
     * Set once by include/common.inc.php (legacy, not subject to deptrac) --
     * same shape as setDefaultLanguageProvider() above, needed because this
     * L1Infrastructure class may not depend on L3Presentation's HtmlService
     * directly (deptrac).
     */
    public static function setHtmlRenderer(HtmlRenderingInterface $renderer): void
    {
        self::$htmlRenderer = $renderer;
    }

    private static function fatalError(string $msg): never
    {
        if (self::$htmlRenderer !== null) {
            self::$htmlRenderer->fatalError($msg);
        }
        throw new \RuntimeException($msg);
    }

    /**
     * Called by CommonBootstrap::run() after include/common.inc.php's
     * load_language() calls have populated $GLOBALS['lang'] -- HTTP-path
     * only, mirroring PageState::attachGlobals()'s own placement and
     * reasoning (no $lang concept on the CLI path).
     */
    public static function attachGlobals(): void
    {
        $raw = $GLOBALS['lang'] ?? [];
        if (is_array($raw)) {
            self::$data = self::filterLangValues($raw);
        }
        $GLOBALS['lang'] = &self::$data;
    }

    /**
     * Set once by include/common.inc.php (legacy, not subject to deptrac),
     * at the same point its own former load_language() calls already ran --
     * every later load() call in the request reuses the same provider
     * instance instead of reconstructing UserService on every call the way
     * the original free function did.
     */
    public static function setDefaultLanguageProvider(DefaultLanguageProviderInterface $provider): void
    {
        self::$defaultLanguageProvider = $provider;
    }

    public static function t(string $key, mixed ...$args): string
    {
        return Translator::get()->translate($key, ...$args);
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
    }

    /**
     * Day name by day-of-week index (0 = Sunday).
     */
    public static function day(int $dayOfWeek): string
    {
        return self::fromLangArray('day', $dayOfWeek);
    }

    /**
     * Month name by month number (1 = January).
     */
    public static function month(int $month): string
    {
        return self::fromLangArray('month', $month);
    }

    /**
     * includes a language file or returns the content of a language file
     *
     * tries to load in descending order:
     *   param language, user language, default language
     *
     * @param array{language?: string, return?: bool, no_fallback?: bool, force_fallback?: bool|string, local?: bool} $options can contain
     *     @option string language - language to load
     *     @option bool return - if true the file content is returned
     *     @option bool no_fallback - if true do not load default language
     *     @option bool|string force_fallback - force pre-loading of another language
     *        default language if *true* or specified language
     *     @option bool local - if true load file from local directory
     */
    public static function load(string $filename, string $dirname = '', array $options = []): string|bool
    {
        /**
         * @var array<string, mixed> $user
         * @var array<string, array<string, mixed>> $language_files
         */
        global $user, $language_files;

        // keep trace of plugins loaded files for switch_lang_to() function
        if ($dirname !== '' && $filename !== '' && ! ($options['return'] ?? false)
          && ! isset($language_files[$dirname][$filename])) {
            $language_files[$dirname][$filename] = $options;
        }

        if (! ($options['return'] ?? false)) {
            $filename .= '.php';
        }
        if ($dirname === '') {
            $dirname = PHPWG_ROOT_PATH;
        }
        $dirname .= 'language/';

        $default_language = (defined('PHPWG_INSTALLED') and ! defined('UPGRADES_PATH'))
            ? (self::$defaultLanguageProvider?->getDefaultLanguage() ?? AppInfo::DEFAULT_LANGUAGE)
            : AppInfo::DEFAULT_LANGUAGE;

        // construct list of potential languages
        // Every element pushed here must be a real string: $user['language'] and
        // $options['force_fallback'] are the only entries whose static type
        // isn't already a plain string, so both get an explicit is_string()
        // guard before joining the list (array_unique()/implode() below need
        // string-castable elements, not just an array container).
        $languages = [];
        if (! empty($options['language'])) { // explicit language
            $languages[] = $options['language'];
        }
        if (! empty($user['language']) && is_string($user['language'])) { // use language
            $languages[] = $user['language'];
        }
        if (($parent = self::getParentLanguage()) != null) { // parent language
            // this is only for when the "child" language is missing
            $languages[] = $parent;
        }
        if (isset($options['force_fallback'])) { // fallback language
            // this is only for when the main language is missing
            if ($options['force_fallback'] === true) {
                $options['force_fallback'] = $default_language;
            }
            if (is_string($options['force_fallback'])) {
                $languages[] = $options['force_fallback'];
            }
        }
        if (! ($options['no_fallback'] ?? false)) { // default language
            $languages[] = $default_language;
        }

        $languages = array_unique($languages);

        // find first existing
        $source_file = '';
        $selected_language = '';
        foreach ($languages as $language) {
            $f = ($options['local'] ?? false) ?
              $dirname . $language . '.' . $filename :
              $dirname . $language . '/' . $filename;

            // Core language files were converted to .po in P16 -- $f is a
            // .lang.php-style path (the '.php' suffix appended above), which no
            // longer exists on disk for the ~322 converted core files (only
            // their .po sibling does now). Plugins/themes without a .po file
            // yet still ship the plain .lang.php this existence check originally
            // relied on exclusively.
            $po_sibling = preg_replace('/\.lang\.php$/', '.po', $f);

            if (file_exists($f) || ($po_sibling !== null && $po_sibling !== $f && file_exists($po_sibling))) {
                $selected_language = $language;
                $source_file = $f;
                break;
            }
        }

        if ($source_file === '') {
            return false;
        }

        if ($options['return'] ?? false) {
            $content = @file_get_contents($source_file);
            // Note: target charset is always utf-8 $content = convert_charset($content, 'utf-8', $target_charset);
            return $content;
        }

        // $source_file is a .lang.php path here (see the '.php' suffix
        // appended above); its sibling .po -- core content only, P16
        // converted all 322 real ones -- takes priority. Plugins/
        // themes without a .po file yet keep working via the PHP-
        // array include path below (Translator::translate()'s own
        // $GLOBALS['lang'] fallback is what makes that safe to mix
        // with PO-loaded core strings).
        $po_file = preg_replace('/\.lang\.php$/', '.po', $source_file);

        global $lang, $lang_info;
        if (! isset($lang) || ! is_array($lang)) {
            $lang = [];
        }
        if (! isset($lang_info) || ! is_array($lang_info)) {
            $lang_info = [];
        }

        if ($po_file !== null && is_readable($po_file)) {
            $translations = Translator::get()->load($selected_language, $po_file);
            $load_lang_info = $translations !== null ? self::poHeadersToLangInfo($translations->getHeaders()) : [];

            if (isset($options['force_fallback']) && is_string($options['force_fallback'])
              && $options['force_fallback'] !== $selected_language) {
                $fallback_po = $dirname . $options['force_fallback'] . '/' . basename($po_file);
                if (is_readable($fallback_po)) {
                    Translator::get()->load($options['force_fallback'], $fallback_po);
                }
            }

            $parent_language = is_string($load_lang_info['parent'] ?? null) && $load_lang_info['parent'] !== ''
                ? $load_lang_info['parent']
                : (is_string($lang_info['parent'] ?? null) ? $lang_info['parent'] : null);

            if (! empty($parent_language) && $parent_language !== $selected_language) {
                $parent_po = $dirname . $parent_language . '/' . basename($po_file);
                if (is_readable($parent_po)) {
                    // Load the parent, then re-load the child (already
                    // loaded above) -- Translator::load()'s merge
                    // (gettext/translator's addTranslations() ->
                    // array_replace_recursive(), and mirrorToGlobal()'s
                    // own $GLOBALS['lang'] writes) both give precedence
                    // to whichever load() call happens last. Loading
                    // only the parent here would let it silently
                    // override the child's own strings for any key both
                    // define; re-loading the child restores the correct
                    // "child overrides parent, parent fills the gaps"
                    // precedence (e.g. en_US inherits piwigo_day_N from
                    // its en_UK parent, but keeps its own overrides).
                    Translator::get()->load($parent_language, $parent_po);
                    Translator::get()->load($selected_language, $po_file);
                }
            }

            $lang_info = array_merge($lang_info, $load_lang_info);
            return true;
        }

        // load forced fallback
        if (isset($options['force_fallback']) && is_string($options['force_fallback'])
          && $options['force_fallback'] != $selected_language) {
            @include str_replace($selected_language, $options['force_fallback'], $source_file);
        }

        // load language content
        @include $source_file;
        // get_defined_vars() (rather than reading $lang/$lang_info
        // directly) keeps their real, include-dependent type visible to
        // static analysis instead of appearing to always be undefined.
        $included_vars = get_defined_vars();
        $load_lang = $included_vars['lang'] ?? null;
        $load_lang_info = $included_vars['lang_info'] ?? null;

        // load parent language content directly in global
        if (is_array($load_lang_info) && ! empty($load_lang_info['parent']) && is_string($load_lang_info['parent'])) {
            $parent_language = $load_lang_info['parent'];
        } elseif (! empty($lang_info['parent']) && is_string($lang_info['parent'])) {
            $parent_language = $lang_info['parent'];
        } else {
            $parent_language = null;
        }

        if (! empty($parent_language) && $parent_language != $selected_language) {
            @include str_replace($selected_language, $parent_language, $source_file);
        }

        // merge contents
        $lang = array_merge($lang, (array) $load_lang);
        $lang_info = array_merge($lang_info, (array) $load_lang_info);
        return true;
    }

    /**
     * returns a single element to use with args()
     *
     * @param string $key translation key
     * @param mixed $args arguments to use on sprintf($key, args)
     *   if args is a array, each values are used on sprintf
     * @return array{key_args: array<int, mixed>}
     */
    public static function buildArgs(string $key, mixed $args = ''): array
    {
        if (is_array($args)) {
            // array_values() guarantees a plain list even when $args carries
            // string keys, so the merged result stays an int-keyed list
            // matching the documented return shape (positional sprintf args).
            $key_arg = array_merge([$key], array_values($args));
        } else {
            $key_arg = [$key, $args];
        }
        return [
            'key_args' => $key_arg,
        ];
    }

    /**
     * returns a string formated with l10n elements.
     * it is usefull to "prepare" a text and translate it later
     * @see buildArgs()
     *
     * @param mixed $key_args one buildArgs() element or array of buildArgs()
     *   elements; the array shape isn't enforced by a native type, so the
     *   is_array() check below is a real runtime guard against malformed input,
     *   not a redundant one
     * @param string $sep used when translated elements are concatened
     */
    public static function args(mixed $key_args, string $sep = "\n"): string
    {
        $result = '';
        if (! is_array($key_args)) {
            self::fatalError('Lang::args: Invalid arguments');
        }

        $first = true;
        foreach ($key_args as $key => $element) {
            if ($first) {
                $first = false;
            } else {
                $result .= $sep;
            }

            if ($key === 'key_args') {
                // built by buildArgs(): array{key_args: array<int, mixed>}
                // -- 'key_args' is always an array here, but $key_args's
                // declared type is mixed, so the shape isn't provable
                // statically and needs a real runtime check.
                if (! is_array($element)) {
                    continue;
                }

                $l10n_key = array_shift($element);
                if (! is_string($l10n_key)) {
                    continue;
                }

                array_unshift($element, self::t($l10n_key)); // translate the key
                $formatted = call_user_func_array(sprintf(...), $element);
                $result .= is_string($formatted) ? $formatted : '';
            } else {
                $result .= self::args($element, $sep);
            }
        }

        return $result;
    }

    private static function fromLangArray(string $key, int $index): string
    {
        $raw = $GLOBALS['lang'] ?? [];
        $lang = is_array($raw) ? $raw : [];
        $group = $lang[$key] ?? null;
        if (! is_array($group) || ! isset($group[$index])) {
            return '';
        }
        $val = $group[$index];

        return is_scalar($val) ? (string) $val : '';
    }

    private static function getParentLanguage(?string $lang_id = null): ?string
    {
        if (empty($lang_id)) {
            /** @var array<string, mixed> $lang_info */
            global $lang_info;
            $parent = $lang_info['parent'] ?? null;
            return (is_string($parent) && $parent !== '') ? $parent : null;
        }

        $f = PHPWG_ROOT_PATH . 'language/' . $lang_id . '/common.po';
        if (is_readable($f)) {
            $parent = (new PoLoader())->loadFile($f)
                ->getHeaders()
                ->get('X-Piwigo-Parent');
            return ($parent !== null && $parent !== '') ? $parent : null;
        }

        return null;
    }

    /**
     * Rebuilds the legacy $lang_info array shape from a .po file's headers --
     * load()'s PO path uses this so callers that still read
     * $lang_info['language_name']/['country']/['direction']/['code']/
     * ['zero_plural']/['parent']/['jquery_code']/['plupload_code'] (admin
     * Smarty templates, getParentLanguage()) keep working unchanged after
     * the .lang.php source files are gone -- see php-to-po-fn.php's own
     * X-Piwigo-* header list for what's preserved and why.
     *
     * @return array<string, string|bool>
     */
    private static function poHeadersToLangInfo(Headers $headers): array
    {
        $info = [];
        $map = [
            'X-Piwigo-Language-Name' => 'language_name',
            'X-Piwigo-Country' => 'country',
            'X-Piwigo-Direction' => 'direction',
            'X-Piwigo-Code' => 'code',
            'X-Piwigo-Parent' => 'parent',
            'X-Piwigo-Jquery-Code' => 'jquery_code',
            'X-Piwigo-Plupload-Code' => 'plupload_code',
        ];
        foreach ($map as $header => $key) {
            $value = $headers->get($header);
            if ($value !== null && $value !== '') {
                $info[$key] = $value;
            }
        }
        $info['zero_plural'] = $headers->get('X-Piwigo-Zero-Plural') === 'true';

        return $info;
    }

    /**
     * Like a plain string map, except 'day'/'month' (list<string>, see
     * $data's own docblock) are kept instead of being dropped -- an earlier
     * version filtered on `is_string($v)` alone, which silently discarded
     * both keys on every request and left Lang::day()/month() (and any
     * legacy $lang['day']/$lang['month'] reader, e.g. admin/configuration.
     * php, admin/intro.php's activity chart, format_date_legacy()) always
     * returning ''/empty.
     *
     * @param array<mixed, mixed> $value
     * @return array<string, string|array<int, string>>
     */
    private static function filterLangValues(array $value): array
    {
        $result = [];
        foreach ($value as $k => $v) {
            if (! is_string($k)) {
                continue;
            }
            if (is_string($v)) {
                $result[$k] = $v;
            } elseif (is_array($v)) {
                $strings = [];
                foreach ($v as $i => $s) {
                    if (is_int($i) && is_string($s)) {
                        $strings[$i] = $s;
                    }
                }
                if ($strings !== []) {
                    $result[$k] = $strings;
                }
            }
        }

        return $result;
    }

    // ---- Test helpers ----------------------------------------------------

    /**
     * @param array<string, string> $data
     */
    public static function loadArray(array $data): void
    {
        self::$data = $data;
    }

    public static function reset(): void
    {
        self::$data = [];
        self::$defaultLanguageProvider = null;
        self::$htmlRenderer = null;
    }
}
