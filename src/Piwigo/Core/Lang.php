<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Gettext\Headers;
use Gettext\Loader\PoLoader;
use Gettext\Translations;
use Piwigo\Lang\Translator;

/**
 * Typed facade over the legacy $lang array.
 *
 * `self::$data` used to be a live reference to `$GLOBALS['lang']`
 * (established by `attachGlobals()`), justified by "the free function
 * `l10n()` keeps reading `$lang` directly." "Nothing is frozen" gap-closure
 * (2026-07-22): that free function is deleted (see `Piwigo\Lang\
 * Translator::translate()`'s own docblock) and nothing else reads
 * `$GLOBALS['lang']` independently of this class and `Translator` -- the
 * bridge was two classes using a global as their own private IPC channel,
 * not a real external contract. `attachGlobals()` now pulls a one-time
 * snapshot from `$translator->mirroredStrings()` instead.
 *
 * `t()` delegates to `Piwigo\Lang\Translator` (gettext-backed, P16) rather
 * than reading `self::$data` directly -- `Translator::translate()` itself
 * falls back to its own mirrored string map for keys with no gettext
 * entry, so both PHP-array-loaded and PO-loaded strings resolve correctly
 * through one call. `self::$data` backs `has()`/`day()`/`month()` only.
 *
 * P23 batch 8d: gained `load()` (ported from the legacy `load_language()`)
 * plus its two private helpers (`getParentLanguage()`/
 * `poHeadersToLangInfo()`, ported from `get_parent_language()`/
 * `po_headers_to_lang_info()` -- both had zero real external callers,
 * confirmed by grep, so neither needs to stay public) and `args()`/
 * `buildArgs()` (ported from `l10n_args()`/`get_l10n_args()`). See
 * `DefaultLanguageProviderInterface`'s own docblock for why `load()` needs
 * a provider it can tolerate not having yet, rather than a required
 * constructor dependency.
 *
 * Singleton/service-locator elimination campaign, Phase 8: converted to a
 * real, container-shared instance -- `Translator`/`HtmlRenderingInterface`/
 * `Paths`/`InstallationFlag` are constructor-injected (all already bound in
 * `container.php`). `DefaultLanguageProviderInterface` deliberately stays a
 * real, mutable, nullable instance property with its own
 * `setDefaultLanguageProvider()` instance method instead -- unlike this
 * class's other collaborators, it resolves (via its own `container.php`
 * binding) to `UserService`, whose own dependency chain reaches a Doctrine
 * repository; constructing a Doctrine repository for the first time in a
 * process makes Doctrine eagerly connect to auto-detect the DB platform
 * (the exact landmine `AccessControl::currentForCaching()`'s own docblock
 * documents finding during Phase 7's close-out). Since `Lang::t()` is
 * reached from `Template::parse()` on essentially every real page render,
 * and `Admin\Install\InstallWizard::render()` calls it directly before any
 * DB credentials exist at all, making this collaborator a required eager
 * dependency would mean the install page could never render even once on
 * a fresh install. `load()`/`currentUserLanguage()` already null-coalesce
 * around an unset provider (`AppInfo::DEFAULT_LANGUAGE` fallback) -- "not
 * yet wired" was already a real, tolerated state before this phase, so
 * this collaborator keeps that shape instead of following the "becomes an
 * ordinary constructor param" pattern the other 4 do.
 */
final class Lang
{
    /**
     * $lang['day']/$lang['month'] are the two legacy exceptions to an
     * otherwise string-valued array -- a nested list<string> keyed by
     * day-of-week/month-of-year index (see Piwigo\Lang\Translator::
     * mirror(), which populates them from the piwigo_day_N/
     * piwigo_month_N PO entries). day()/month() below read them back out.
     *
     * @var array<string, string|array<int, string>>
     */
    private array $data = [];

    /**
     * Backs load()'s $lang_info (parent/code/direction/jquery_code/... --
     * see poHeadersToLangInfo()'s own docblock for the full key list).
     * Legacy Coupling Retirement Track A gap-fill batch G5: every real
     * external reader (MailService, IntroSubController, Template,
     * Bootstrap\RedirectService::redirectHtml()) goes through langInfo()/
     * setLangInfo()/isLangInfoInitialized() instead of a raw global -- unlike
     * $data above, nothing outside this class reads $GLOBALS['lang_info']
     * directly, so no $GLOBALS bridge is needed here.
     *
     * @var array<string, string|bool>
     */
    private array $langInfo = [];

    /**
     * True once load() has populated $langInfo at least once in this
     * request. Bootstrap\RedirectService::redirectHtml() uses this to detect
     * "called before common.inc.php finished bootstrapping" (a real early-
     * fatal path) -- the same role CurrentTemplate::isInitialized() plays a
     * few lines below it in that same method. Deliberately a separate
     * flag rather than `$langInfo !== []`: a real load() call can legitimately
     * produce an empty array-shaped result, and that must still count as
     * "initialised", matching the original global's isset() semantics.
     */
    private bool $langInfoInitialized = false;

    /**
     * Tracks which plugin/theme language files load() has already loaded in
     * this request, keyed by dirname then filename -- MailService::
     * switchLangTo() replays this list to reload every plugin/theme
     * translation when the user switches language mid-request.
     *
     * @var array<string, array<string, array{language?: string, return?: bool, no_fallback?: bool, force_fallback?: bool|string, local?: bool}>>
     */
    private array $languageFiles = [];

    private ?DefaultLanguageProviderInterface $defaultLanguageProvider = null;

    public function __construct(
        private readonly Translator $translator,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly Paths $paths,
        private readonly InstallationFlag $installationFlag,
    ) {}

    private function fatalError(string $msg): never
    {
        // fatalError() is `never`-typed -- always terminates -- and
        // $htmlRenderer is now a required constructor collaborator
        // (singleton/service-locator elimination campaign, Phase 8), so
        // there's no reachable fallthrough left to throw a plain
        // RuntimeException from, unlike the old nullable-static design.
        // PHPStan proves this (deadCode.unreachable) -- same real,
        // permanent simplification AccessControl::checkStatus() got in
        // Phase 7.
        $this->htmlRenderer->fatalError($msg);
    }

    /**
     * Called by RequestBootstrap::finalize() (and bootConfigOnly(), its
     * lighter standalone-callable equivalent) after finalize()'s own
     * load() calls have populated Translator's own mirror -- HTTP-path
     * only, mirroring PageState's own resolution placement in
     * RequestBootstrap and reasoning (no $lang concept on the CLI path).
     */
    public function attachGlobals(): void
    {
        $this->data = $this->filterLangValues($this->translator->mirroredStrings());
    }

    /**
     * Set once by RequestBootstrap::finalize(), at the same point its own
     * former load_language() calls already ran -- every later load() call
     * in the request reuses the same provider instance instead of
     * reconstructing UserService on every call the way the original free
     * function did. See this class's own docblock for why this stays a
     * real, mutable instance method instead of a constructor dependency.
     */
    public function setDefaultLanguageProvider(DefaultLanguageProviderInterface $provider): void
    {
        $this->defaultLanguageProvider = $provider;
    }

    /**
     * Current (logged-in or guest) user's language preference, for other
     * L1Infrastructure callers (e.g. DateHelper) that need it but may not
     * depend on Piwigo\Users\CurrentUser directly (deptrac) -- see
     * DefaultLanguageProviderInterface's own docblock. Null when no
     * provider has been set yet or it has no preference to report.
     */
    public function currentUserLanguage(): ?string
    {
        return $this->defaultLanguageProvider?->getCurrentLanguage();
    }

    public function t(string $key, mixed ...$args): string
    {
        return $this->translator->translate($key, ...$args);
    }

    /**
     * Thin `$this->translator->plural()` delegate carrying the one
     * behavioral difference the deleted `l10n_dec()` free function had:
     * `Translator::plural()` requires a strict native `int`, but this
     * boundary's real callers are Smarty-compiled-template expressions
     * (`Template::modcompiler_translate_dec()`'s generated code -- the
     * only real caller, confirmed by grep) whose runtime value can be a
     * numeric DB-row string (the exact real 500 `l10n_dec()`'s own
     * docblock already documented: menubar_categories.tpl passed one).
     * Every hand-written .php call site instead calls its own
     * constructor-injected `Translator::plural()` directly with an
     * explicit int already in hand, per Legacy Coupling Retirement
     * Phase 4d.
     */
    public function plural(string $singular, string $plural, mixed $decimal): string
    {
        $n = is_numeric($decimal) ? (int) $decimal : 0;

        return $this->translator->plural($singular, $plural, $n);
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
    public function snapshot(): array
    {
        return $this->data;
    }

    /**
     * Restores a translation table previously obtained from snapshot(), or
     * resets to empty -- ready for a fresh load() -- when $data is null.
     *
     * @param array<string, string|array<int, string>>|null $data
     */
    public function restore(?array $data): void
    {
        $this->data = $data ?? [];
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * @return array<string, string|bool>
     */
    public function langInfo(): array
    {
        return $this->langInfo;
    }

    /**
     * @see $langInfoInitialized
     */
    public function isLangInfoInitialized(): bool
    {
        return $this->langInfoInitialized;
    }

    /**
     * @param array<string, string|bool> $data
     */
    public function setLangInfo(array $data): void
    {
        $this->langInfo = $data;
        $this->langInfoInitialized = true;
    }

    /**
     * @return array<string, array<string, array{language?: string, return?: bool, no_fallback?: bool, force_fallback?: bool|string, local?: bool}>>
     */
    public function languageFiles(): array
    {
        return $this->languageFiles;
    }

    /**
     * Day name by day-of-week index (0 = Sunday).
     */
    public function day(int $dayOfWeek): string
    {
        return $this->fromLangArray('day', $dayOfWeek);
    }

    /**
     * Month name by month number (1 = January).
     */
    public function month(int $month): string
    {
        return $this->fromLangArray('month', $month);
    }

    /**
     * All day names, keyed by day-of-week index (0 = Sunday). Bulk
     * counterpart to day() for callers building a full labels map (e.g.
     * calendar navigation bars) instead of looking up a single index.
     *
     * @return array<int, string>
     */
    public function days(): array
    {
        return $this->langArrayGroup('day');
    }

    /**
     * All month names, keyed by month number (1 = January). Bulk
     * counterpart to month() -- see days().
     *
     * @return array<int, string>
     */
    public function months(): array
    {
        return $this->langArrayGroup('month');
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
    public function load(string $filename, string $dirname = '', array $options = []): string|bool
    {
        // keep trace of plugins loaded files for switch_lang_to() function
        if ($dirname !== '' && $filename !== '' && ! ($options['return'] ?? false)
          && ! isset($this->languageFiles[$dirname][$filename])) {
            $this->languageFiles[$dirname][$filename] = $options;
        }

        if (! ($options['return'] ?? false)) {
            $filename .= '.php';
        }
        if ($dirname === '') {
            $dirname = $this->paths->root;
        }
        $dirname .= 'language/';

        $default_language = $this->installationFlag->isActive()
            ? ($this->defaultLanguageProvider?->getDefaultLanguage() ?? AppInfo::DEFAULT_LANGUAGE)
            : AppInfo::DEFAULT_LANGUAGE;

        // construct list of potential languages
        // Every element pushed here must be a real string: $options['force_fallback']
        // is the only entry whose static type isn't already a plain string, so
        // it gets an explicit is_string() guard before joining the list
        // (array_unique()/implode() below need string-castable elements, not
        // just an array container).
        $languages = [];
        if (! in_array($options['language'] ?? null, [null, false, 0, '0', '', []], true)) { // explicit language
            $languages[] = $options['language'];
        }
        $current_user_language = $this->defaultLanguageProvider?->getCurrentLanguage();
        if (! in_array($current_user_language, [null, ''], true)) { // use language
            $languages[] = $current_user_language;
        }
        if (($parent = $this->getParentLanguage()) !== null) { // parent language
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

        $lang_info = $this->langInfo;

        $translations = $this->translator->load($selected_language, $po_file);
        $load_lang_info = $translations instanceof Translations ? $this->poHeadersToLangInfo($translations->getHeaders()) : [];

        if (isset($options['force_fallback']) && is_string($options['force_fallback'])
          && $options['force_fallback'] !== $selected_language) {
            $fallback_po = $dirname . $options['force_fallback'] . '/' . basename($po_file);
            if (is_readable($fallback_po)) {
                $this->translator->load($options['force_fallback'], $fallback_po);
            }
        }

        $load_lang_info_parent = $load_lang_info['parent'] ?? null;
        $lang_info_parent = $lang_info['parent'] ?? null;
        $parent_language = is_string($load_lang_info_parent) && $load_lang_info_parent !== ''
            ? $load_lang_info_parent
            : (is_string($lang_info_parent) ? $lang_info_parent : null);

        if (! in_array($parent_language, [null, ''], true) && $parent_language !== $selected_language) {
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
                $this->translator->load($parent_language, $parent_po);
                $this->translator->load($selected_language, $po_file);
            }
        }

        $lang_info = array_merge($lang_info, $load_lang_info);
        $this->setLangInfo($lang_info);
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
    public function buildArgs(string $key, mixed $args = ''): array
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
    public function args(mixed $key_args, string $sep = "\n"): string
    {
        $result = '';
        if (! is_array($key_args)) {
            $this->fatalError('Lang::args: Invalid arguments');
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

                array_unshift($element, $this->t($l10n_key)); // translate the key
                $formatted = call_user_func_array(sprintf(...), $element);
                $result .= is_string($formatted) ? $formatted : '';
            } else {
                $result .= $this->args($element, $sep);
            }
        }

        return $result;
    }

    private function fromLangArray(string $key, int $index): string
    {
        $group = $this->langArrayGroup($key);
        $val = $group[$index] ?? null;

        return $val ?? '';
    }

    /**
     * @return array<int, string>
     */
    private function langArrayGroup(string $key): array
    {
        $group = $this->data[$key] ?? null;

        return is_array($group) ? $group : [];
    }

    private function getParentLanguage(?string $lang_id = null): ?string
    {
        if ($lang_id === null || $lang_id === '') {
            $parent = $this->langInfo['parent'] ?? null;
            return (is_string($parent) && $parent !== '') ? $parent : null;
        }

        $f = $this->paths->root . 'language/' . $lang_id . '/common.po';
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
    private function poHeadersToLangInfo(Headers $headers): array
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
     * $value is genuinely arbitrary by design -- Translator's own $mirror is
     * populated from legacy plugin-authored `.lang.php` files (plain PHP
     * assigning arbitrary values to $lang[...]) as well as .po data; this
     * method's whole job is normalizing that untrusted shape down to the
     * precise return type below, so it can't itself assume a clean input.
     *
     * @param array<mixed, mixed> $value
     * @return array<string, string|array<int, string>>
     */
    private function filterLangValues(array $value): array
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
     * Also seeds Translator's own mirror (t()'s fallback path for keys
     * with no gettext entry) -- matches this method's own pre-"nothing is
     * frozen" behavior, when self::$data and $GLOBALS['lang'] were the
     * same reference and Translator::translate() read that same global;
     * one loadArray() call making both has() and t() resolve is the real
     * test-ergonomics contract callers rely on, not an accident of how
     * the old bridge happened to be wired.
     *
     * @param array<string, string|array<int, string>> $data
     */
    public function loadArray(array $data): void
    {
        $this->data = $data;
        $this->translator->loadArray($data);
    }

    public function reset(): void
    {
        $this->data = [];
        $this->langInfo = [];
        $this->langInfoInitialized = false;
        $this->languageFiles = [];
        $this->defaultLanguageProvider = null;
    }
}
