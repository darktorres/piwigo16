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

    /**
     * Backs load()'s $lang_info (parent/code/direction/jquery_code/... --
     * see poHeadersToLangInfo()'s own docblock for the full key list).
     * Legacy Coupling Retirement Track A gap-fill batch G5: every real
     * external reader (MailService, IntroSubController, Template,
     * Http/functions.php's redirect_html()) goes through langInfo()/
     * setLangInfo()/isLangInfoInitialized() instead of a raw global -- unlike
     * $data above, nothing outside this class reads $GLOBALS['lang_info']
     * directly, so no $GLOBALS bridge is needed here.
     *
     * @var array<string, mixed>
     */
    private static array $langInfo = [];

    /**
     * True once load() has populated $langInfo at least once in this
     * request. Http/functions.php's redirect_html() uses this to detect
     * "called before common.inc.php finished bootstrapping" (a real early-
     * fatal path) -- the same role CurrentTemplate::isInitialized() plays a
     * few lines below it in that same function. Deliberately a separate
     * flag rather than `$langInfo !== []`: a real load() call can legitimately
     * produce an empty array-shaped result, and that must still count as
     * "initialised", matching the original global's isset() semantics.
     */
    private static bool $langInfoInitialized = false;

    /**
     * Tracks which plugin/theme language files load() has already loaded in
     * this request, keyed by dirname then filename -- MailService::
     * switchLangTo() replays this list to reload every plugin/theme
     * translation when the user switches language mid-request.
     *
     * @var array<string, array<string, array{language?: string, return?: bool, no_fallback?: bool, force_fallback?: bool|string, local?: bool}>>
     */
    private static array $languageFiles = [];

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
        if (self::$htmlRenderer instanceof \Piwigo\Core\HtmlRenderingInterface) {
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

    /**
     * Current (logged-in or guest) user's language preference, for other
     * L1Infrastructure callers (e.g. DateHelper) that need it but may not
     * depend on Piwigo\Users\CurrentUser directly (deptrac) -- see
     * DefaultLanguageProviderInterface's own docblock. Null when no
     * provider has been set yet or it has no preference to report.
     */
    public static function currentUserLanguage(): ?string
    {
        return self::$defaultLanguageProvider?->getCurrentLanguage();
    }

    public static function t(string $key, mixed ...$args): string
    {
        return Translator::get()->translate($key, ...$args);
    }

    /**
     * Thin `Translator::get()->plural()` delegate carrying the one
     * behavioral difference the deleted `l10n_dec()` free function had:
     * `Translator::plural()` requires a strict native `int`, but this
     * boundary's real callers are Smarty-compiled-template expressions
     * (`Template::modcompiler_translate_dec()`'s generated code -- the
     * only real caller, confirmed by grep) whose runtime value can be a
     * numeric DB-row string (the exact real 500 `l10n_dec()`'s own
     * docblock already documented: menubar_categories.tpl passed one).
     * Every hand-written .php call site instead calls
     * `Translator::get()->plural()` directly with an explicit int already
     * in hand, per Legacy Coupling Retirement Phase 4d.
     */
    public static function plural(string $singular, string $plural, mixed $decimal): string
    {
        $n = is_numeric($decimal) ? (int) $decimal : 0;

        return Translator::get()->plural($singular, $plural, $n);
    }

    /**
     * Returns the currently active translation table -- for callers that
     * need to temporarily swap it out wholesale and restore it later (e.g.
     * MailService::switchLangTo()/switchLangBack(), building a
     * notification email in a language different from the current
     * request's), not for reading individual keys (use t()/has() instead).
     *
     * @return array<string, string|array<int, string>>
     */
    public static function snapshot(): array
    {
        return self::$data;
    }

    /**
     * Restores a translation table previously obtained from snapshot(), or
     * resets to empty -- ready for a fresh load() -- when $data is null.
     * Re-establishes the $GLOBALS['lang'] reference l10n() still reads
     * (see attachGlobals()'s own docblock), so old and new call sites stay
     * in sync.
     *
     * @param array<string, string|array<int, string>>|null $data
     */
    public static function restore(?array $data): void
    {
        self::$data = $data ?? [];
        $GLOBALS['lang'] = &self::$data;
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function langInfo(): array
    {
        return self::$langInfo;
    }

    /**
     * @see $langInfoInitialized
     */
    public static function isLangInfoInitialized(): bool
    {
        return self::$langInfoInitialized;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function setLangInfo(array $data): void
    {
        self::$langInfo = $data;
        self::$langInfoInitialized = true;
    }

    /**
     * @return array<string, array<string, array{language?: string, return?: bool, no_fallback?: bool, force_fallback?: bool|string, local?: bool}>>
     */
    public static function languageFiles(): array
    {
        return self::$languageFiles;
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
     * All day names, keyed by day-of-week index (0 = Sunday). Bulk
     * counterpart to day() for callers building a full labels map (e.g.
     * calendar navigation bars) instead of looking up a single index.
     *
     * @return array<int, string>
     */
    public static function days(): array
    {
        return self::langArrayGroup('day');
    }

    /**
     * All month names, keyed by month number (1 = January). Bulk
     * counterpart to month() -- see days().
     *
     * @return array<int, string>
     */
    public static function months(): array
    {
        return self::langArrayGroup('month');
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
        // keep trace of plugins loaded files for switch_lang_to() function
        if ($dirname !== '' && $filename !== '' && ! ($options['return'] ?? false)
          && ! isset(self::$languageFiles[$dirname][$filename])) {
            self::$languageFiles[$dirname][$filename] = $options;
        }

        if (! ($options['return'] ?? false)) {
            $filename .= '.php';
        }
        if ($dirname === '') {
            $dirname = CurrentPaths::get()->root;
        }
        $dirname .= 'language/';

        $default_language = (InstallationFlag::isActive() and ! UpgradeFlow::isActive())
            ? (self::$defaultLanguageProvider?->getDefaultLanguage() ?? AppInfo::DEFAULT_LANGUAGE)
            : AppInfo::DEFAULT_LANGUAGE;

        // construct list of potential languages
        // Every element pushed here must be a real string: $options['force_fallback']
        // is the only entry whose static type isn't already a plain string, so
        // it gets an explicit is_string() guard before joining the list
        // (array_unique()/implode() below need string-castable elements, not
        // just an array container).
        $languages = [];
        if (! empty($options['language'])) { // explicit language
            $languages[] = $options['language'];
        }
        $current_user_language = self::$defaultLanguageProvider?->getCurrentLanguage();
        if (! empty($current_user_language)) { // use language
            $languages[] = $current_user_language;
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
            // their .po sibling does now). The file_exists($f) branch below
            // is kept for a different reason since Legacy Coupling
            // Retirement Phase 8, 8l dropped .lang.php loading support
            // entirely (see the docblock a few lines below this loop): it
            // doubles as the generic existence check $options['return']
            // mode needs for arbitrary non-.lang.php filenames (e.g.
            // description.txt) -- a raw .lang.php match with no .po
            // sibling now dead-ends into load()'s own `return false;`
            // instead of being read.
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

        // $source_file is a .lang.php-style path here (see the '.php'
        // suffix appended above); only its .po sibling is ever actually
        // read now -- Legacy Coupling Retirement Phase 8, 8l dropped the
        // raw .lang.php PHP-array @include fallback that used to follow
        // (zero .lang.php files are bundled in-tree; sibling-repo plugin/
        // theme usage doesn't count as a reason to keep it, see this
        // class's own docblock). The existence-check loop above still
        // accepts a raw, non-.po path as "found" -- that's not dead .lang.php
        // support, it's the generic file-exists check $options['return']
        // mode relies on for arbitrary filenames like description.txt.
        $po_file = preg_replace('/\.lang\.php$/', '.po', $source_file);

        if ($po_file === null || ! is_readable($po_file)) {
            return false;
        }

        $lang_info = self::$langInfo;

        $translations = Translator::get()->load($selected_language, $po_file);
        $load_lang_info = $translations instanceof \Gettext\Translations ? self::poHeadersToLangInfo($translations->getHeaders()) : [];

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
        self::setLangInfo($lang_info);
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
        $group = self::langArrayGroup($key);
        $val = $group[$index] ?? null;

        return $val ?? '';
    }

    /**
     * @return array<int, string>
     */
    private static function langArrayGroup(string $key): array
    {
        $raw = $GLOBALS['lang'] ?? [];
        $lang = is_array($raw) ? $raw : [];
        $group = $lang[$key] ?? null;
        if (! is_array($group)) {
            return [];
        }

        $result = [];
        foreach ($group as $k => $v) {
            if (is_int($k) && is_string($v)) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    private static function getParentLanguage(?string $lang_id = null): ?string
    {
        if (empty($lang_id)) {
            $parent = self::$langInfo['parent'] ?? null;
            return (is_string($parent) && $parent !== '') ? $parent : null;
        }

        $f = CurrentPaths::get()->root . 'language/' . $lang_id . '/common.po';
        if (is_readable($f)) {
            $parent = new PoLoader()
                ->loadFile($f)
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
        $GLOBALS['lang'] = &self::$data;
    }

    public static function reset(): void
    {
        self::$data = [];
        $GLOBALS['lang'] = &self::$data;
        self::$langInfo = [];
        self::$langInfoInitialized = false;
        self::$languageFiles = [];
        self::$defaultLanguageProvider = null;
        self::$htmlRenderer = null;
    }
}
