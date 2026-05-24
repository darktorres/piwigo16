<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Extension;
use Latte\Runtime\Html;
use Piwigo\Asset\ViteManifest;
use Piwigo\Common\Enum\UserStatus;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Http\DeviceDetectionService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;

/**
 * Piwigo Latte template API.
 *
 * Registers the filter + function set that core and plugin `.latte`
 * templates may call. The same set is allowlisted in
 * [[\Piwigo\Template\Latte\PiwigoPolicy]] — plugin templates compile
 * through `LatteEngine::sandboxed()` with that policy, so anything
 * declared here is callable from plugins by definition.
 *
 * The surface has three groups:
 *
 *  - **Translation pair** — `translate` / `translate_dec` (+ `l10n`
 *    alias) bound to `Lang::t()` / `Translator`.
 *  - **PHP passthroughs as filters** — `sprintf`, `urlencode`,
 *    `htmlspecialchars`, etc. The pipe-first arg-order convention
 *    (`{$x|sprintf:'%d',$n}`) only registers functions whose PHP
 *    signature already takes the piped value as its first argument;
 *    pipe-incompatible cases (`implode`, `str_replace`, `preg_match`,
 *    …) stay un-registered and templates call them inline via
 *    `{=implode(',', $arr)}`.
 *  - **Custom helpers + stateful asset functions** — small
 *    domain-specific filters (`is_admin`, `get_device`, `url_is_remote`,
 *    …) and the asset-pipeline functions (`combineScript`,
 *    `combineCss`, `derivative`, …) that delegate to
 *    [[TemplateRegistry::current()]].
 *
 * When adding a new filter / function: register it here AND add it
 * to the matching `PiwigoPolicy::PLUGIN_FILTERS` / `PLUGIN_FUNCTIONS`
 * (or `CORE_FILTERS` / `CORE_FUNCTIONS` if it should stay core-only)
 * so plugin templates can use it under the sandbox.
 */
final class PiwigoExtension extends Extension
{
    /** @return array<string, callable> */
    #[\Override]
    public function getFilters(): array
    {
        return [
            // Translation pair.
            'translate' => self::translate(...),
            'translate_dec' => self::translateDec(...),

            // PHP passthroughs whose first argument is the piped value.
            // Pipe-incompatible PHP functions (implode, str_replace,
            // preg_match, strstr/stristr — those take the needle/glue
            // first) intentionally stay un-registered; templates call
            // them inline via `{=implode(',', $arr)}` instead.
            'sprintf' => sprintf(...),
            'urlencode' => urlencode(...),
            'intval' => intval(...),
            'json_encode' => json_encode(...),
            'htmlspecialchars' => htmlspecialchars(...),
            'stripslashes' => stripslashes(...),
            'in_array' => in_array(...),
            'ucfirst' => ucfirst(...),
            'nl2br' => nl2br(...),
            'number_format' => self::numberFormat(...),
            'cat' => self::cat(...),
            'count' => count(...),
            'strip_tags' => self::stripTags(...),
            'str_repeat' => str_repeat(...),
            'default' => self::defaultFilter(...),
            'date_format' => self::dateFormat(...),

            // Custom helpers — one-liners delegating to existing services.
            'l10n' => self::translate(...),
            'explode' => self::explode(...),
            'ternary' => self::ternary(...),
            'url_is_remote' => UrlService::urlIsRemote(...),
            'is_admin' => self::isAdmin(...),
            'is_classic_user' => self::isClassicUser(...),
            'get_device' => self::getDevice(...),
            'get_gallery_home_url' => self::getGalleryHomeUrl(...),
        ];
    }

    /**
     * Latte function tags — stateful asset-pipeline functions that
     * delegate to the active Template instance via TemplateRegistry,
     * plus a handful of pre-existing form helpers (`htmlOptions`,
     * `htmlRadios`, `math`) used by 24 / 2 / 1 templates respectively.
     *
     * @return array<string, callable>
     */
    #[\Override]
    public function getFunctions(): array
    {
        return [
            'viteEntry' => self::viteEntry(...),
            'cssLink' => self::cssLink(...),
            'combineScript' => self::combineScript(...),
            'getCombinedScripts' => self::getCombinedScripts(...),
            'combineCss' => self::combineCss(...),
            'getCombinedCss' => self::getCombinedCss(...),
            'derivative' => self::derivative(...),
            'htmlHead' => self::htmlHead(...),
            'htmlOptions' => self::htmlOptions(...),
            'htmlRadios' => self::htmlRadios(...),
            'math' => self::math(...),
            'url_is_remote' => UrlService::urlIsRemote(...),
            'l10n' => self::translate(...),
        ];
    }

    /**
     * Smarty `|default:'fallback'` modifier — returns the fallback when
     * the piped value is empty (null, '', 0, false, []).
     */
    public static function defaultFilter(mixed $value, mixed $fallback): mixed
    {
        return empty($value) ? $fallback : $value;
    }

    /**
     * Smarty's `|strip_tags[:bool]` modifier:
     *   - `|strip_tags` (default `true`) — replaces every tag with a single
     *     space, so `<b>x</b><i>y</i>` becomes ` x  y `.
     *   - `|strip_tags:false` — removes tags without replacement, identical
     *     to PHP's bare `strip_tags($string)`.
     *
     * PHP 8 native `strip_tags($string, $allowed)` rejects a `bool` second
     * argument with a TypeError, so we cannot bind the filter directly to
     * `strip_tags(...)`. This wrapper preserves Smarty semantics.
     */
    public static function stripTags(mixed $value, bool $replaceWithSpace = true): string
    {
        $s = is_scalar($value) ? (string) $value : '';
        return $replaceWithSpace
            ? (preg_replace('/<[^>]*>/', ' ', $s) ?? $s)
            : strip_tags($s);
    }

    /**
     * Smarty `|date_format:"%d"` modifier — maps the strftime-style format
     * to PHP `date()` for the subset Piwigo templates actually use. The
     * input value is coerced via PHP's parsing rules (Unix timestamp, ISO
     * string, or `'now'` for `$smarty.now`).
     */
    public static function dateFormat(mixed $value, string $format = '%b %e, %Y'): string
    {
        if (is_int($value)) {
            $timestamp = $value;
        } else {
            $coerced = is_scalar($value) ? (string) $value : 'now';
            $timestamp = strtotime($coerced);
            if ($timestamp === false) {
                return '';
            }
        }
        $map = [
            '%d' => 'd', '%m' => 'm', '%Y' => 'Y', '%y' => 'y',
            '%H' => 'H', '%M' => 'i', '%S' => 's',
            '%B' => 'F', '%b' => 'M', '%A' => 'l', '%a' => 'D',
            '%e' => 'j', '%j' => 'z', '%p' => 'A', '%P' => 'a',
            '%%' => '%',
        ];
        return date(strtr($format, $map), $timestamp);
    }

    public static function translate(string $key, string|int|float|bool|null|Html ...$args): string
    {
        // Html-wrapped substitution args are common when the surrounding
        // template does `|translate:$VAR|noescape` and $VAR was assigned
        // by a controller wrapping pre-escaped HTML (e.g. InstallController's
        // $EMAIL). Cast to string so sprintf's %s substitution works; the
        // template's `|noescape` (or an outer Html wrap) controls whether
        // the result is auto-escaped.
        $stringArgs = array_map(
            static fn (string|int|float|bool|null|Html $a): string|int|float|bool|null => $a instanceof Html ? (string) $a : $a,
            $args,
        );
        return Lang::t($key, ...$stringArgs);
    }

    public static function translateDec(int $count, string $singular, string $plural): string
    {
        return Translator::get()->plural($singular, $plural, $count);
    }

    /**
     * Behaves like Template::modExplode — falls back to ',' on empty
     * delimiter, which `explode('', $s)` would otherwise reject.
     *
     * @return list<string>
     */
    public static function explode(string $text, string $delimiter = ','): array
    {
        return explode($delimiter !== '' ? $delimiter : ',', $text);
    }

    public static function ternary(mixed $param, mixed $true, mixed $false): mixed
    {
        return $param ? $true : $false;
    }

    public static function isAdmin(string $userStatus = ''): bool
    {
        $status = $userStatus !== '' ? UserStatus::tryFrom($userStatus) : null;
        return Kernel::service(PermissionService::class)->isAdmin($status);
    }

    public static function isClassicUser(string $userStatus = ''): bool
    {
        $status = $userStatus !== '' ? UserStatus::tryFrom($userStatus) : null;
        return Kernel::service(PermissionService::class)->isClassicUser($status);
    }

    public static function getDevice(): string
    {
        return Kernel::service(DeviceDetectionService::class)->getDevice();
    }

    public static function getGalleryHomeUrl(): string
    {
        return Kernel::service(UrlGenerator::class)->gallery();
    }

    /**
     * Smarty's `|cat:` modifier concatenates values onto the pipe head.
     * Multi-arg form: `{$x|cat:'a':'b'}` calls cat($x, 'a', 'b'). The
     * converter's multi-arg-pipe rewrite turns the colons into commas
     * before this filter is invoked, so the runtime call is
     * cat($x, 'a', 'b').
     */
    public static function cat(string|int|float|bool|null $value, string|int|float|bool|null ...$pieces): string
    {
        $parts = [(string) $value];
        foreach ($pieces as $p) {
            $parts[] = (string) $p;
        }
        return implode('', $parts);
    }

    /**
     * Wrapper around PHP's number_format() with sane defaults — Smarty's
     * `|number_format` was registered as a plain passthrough, so
     * Piwigo's templates either call it bare (`{$n|number_format}`)
     * relying on the zero-decimal default, or with explicit args.
     */
    public static function numberFormat(int|float $number, int $decimals = 0, string $decimalSeparator = '.', string $thousandsSeparator = ','): string
    {
        return number_format($number, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    // ---- Asset helpers -------------------------------------------------------

    /** @var array<string, true> */
    private static array $seenEntries = [];

    public static function viteEntry(string $id): Html
    {
        if (isset(self::$seenEntries[$id])) {
            return new Html('');
        }
        self::$seenEntries[$id] = true;

        $entry = ViteManifest::entry($id);
        if ($entry === null) {
            return new Html('');
        }

        $root = UrlService::getRootUrl();
        $tags = [];

        foreach ($entry['css'] as $cssPath) {
            $tags[] = '<link rel="stylesheet" href="' . $root . 'dist/' . $cssPath . '">';
        }
        $tags[] = '<script type="module" src="' . $root . 'dist/' . $entry['file'] . '"></script>';

        return new Html(implode("\n", $tags));
    }

    public static function cssLink(string $path): Html
    {
        $href = UrlService::getRootUrl() . $path . '?v' . AppInfo::VERSION;
        return new Html('<link rel="stylesheet" href="' . $href . '">');
    }

    // ---- Legacy asset-pipeline (to be removed in Phase 4) ----------------

    public static function combineScript(
        string $id,
        ?string $load = null,
        ?string $path = null,
        array|string $require = [],
        string|int $version = 0,
    ): void {
        $tpl = TemplateRegistry::current();
        $tpl->scriptLoader->add($id, $path, $version);

        // Auto-register stylesheets bundled into this entry by Vite.
        // Mirrors Template::funcCombineScript — without this, side-effect
        // CSS imports like `import './tree.css'` never reach the page.
        $manifest = ScriptLoader::getManifest();
        $manifestEntry = ($manifest !== null && is_array($manifest[$id] ?? null)) ? $manifest[$id] : null;
        if ($manifestEntry !== null) {
            $cssList = is_array($manifestEntry['css'] ?? null) ? $manifestEntry['css'] : [];
            foreach ($cssList as $i => $cssPath) {
                $cssPathStr = is_scalar($cssPath) ? (string) $cssPath : '';
                if ($cssPathStr !== '') {
                    $tpl->cssLoader->add(
                        $id . '-vite-css-' . $i,
                        'dist/' . $cssPathStr,
                        $version,
                    );
                }
            }
        }
    }

    /**
     * Returns `Latte\Runtime\Html` so the HTML payload propagates through
     * Latte's auto-escape without needing `|noescape` at every call site.
     */
    public static function getCombinedScripts(): Html
    {
        $tpl = TemplateRegistry::current();
        $scripts = $tpl->scriptLoader->getScripts();
        $root = UrlService::getRootUrl();
        $content = [];

        foreach ($scripts as $script) {
            $content[] = '<script type="module" src="' . $root . $script->path . '"></script>';
        }

        return new Html(implode("\n", $content));
    }

    public static function combineCss(
        string $path,
        ?string $id = null,
        string|int $version = 0,
        int $order = 0,
    ): void {
        TemplateRegistry::current()->cssLoader->add(
            $id ?? md5($path),
            $path,
            $version,
            $order,
        );
    }

    public static function getCombinedCss(): Html
    {
        return new Html(Template::COMBINED_CSS_TAG);
    }

    /**
     * Builds a `DerivativeImage` from a derivative type/params object and a
     * source-image array (or SrcImage). The native Latte equivalent of
     * the legacy `$pwg->derivative(...)` template accessor.
     *
     * @param array<mixed>|SrcImage $img
     */
    public static function derivative(string|DerivativeParams $type, array|SrcImage $img): DerivativeImage
    {
        $src_image = ($img instanceof SrcImage) ? $img : new SrcImage($img);
        return new DerivativeImage($type, $src_image);
    }

    public static function htmlHead(string|Html $content): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed !== '') {
            TemplateRegistry::current()->html_head_elements[] = $trimmed;
        }
    }

    /**
     * Port of Smarty's `{html_options}` plugin. Emits a list of `<option>`
     * tags from `options` (associative key=value) or from `values` paired
     * with `output`. When `name` is provided, the result is wrapped in a
     * `<select>` element. Any extra named arguments (id, class, plus
     * arbitrary HTML attributes) are forwarded onto the wrapper.
     *
     * @param array<int|string, mixed>|null $options associative value→label map
     * @param list<string|int>|null $values raw option values (used with $output)
     * @param list<string>|null $output labels matching $values by index
     * @param array<int|string, mixed>|string|int|float|bool|null $selected
     * @param array<string, scalar> $extra forwarded HTML attributes
     */
    public static function htmlOptions(
        ?array $options = null,
        ?array $values = null,
        ?array $output = null,
        array|string|int|float|bool|null $selected = null,
        ?string $name = null,
        ?string $id = null,
        ?string $class = null,
        mixed ...$extra,
    ): Html {
        if ($options === null && $values === null) {
            return new Html('');
        }
        $selectedNorm = self::normalizeSelected($selected);
        $idx = 0;
        $body = '';
        if ($options !== null) {
            foreach ($options as $optKey => $optVal) {
                $body .= self::htmlOption($optKey, $optVal, $selectedNorm, $id, $class, $idx);
            }
        } else {
            // When `$options` is null the early-return above guarantees
            // `$values` is non-null; `$output` is optional in Smarty's
            // plugin, so pair-by-index falls back to the empty string.
            $output ??= [];
            foreach ($values as $i => $optKey) {
                $body .= self::htmlOption($optKey, $output[$i] ?? '', $selectedNorm, $id, $class, $idx);
            }
        }
        if ($name === null || $name === '') {
            return new Html($body);
        }
        $extraAttrs = '';
        if ($class !== null && $class !== '') {
            $extraAttrs .= ' class="' . $class . '"';
        }
        if ($id !== null && $id !== '') {
            $extraAttrs .= ' id="' . $id . '"';
        }
        /** @var mixed $val */
        foreach ($extra as $key => $val) {
            if (!is_scalar($val)) {
                continue;
            }
            $extraAttrs .= ' ' . $key . '="' . htmlspecialchars((string) $val, ENT_QUOTES) . '"';
        }
        return new Html('<select name="' . $name . '"' . $extraAttrs . '>' . "\n" . $body . '</select>' . "\n");
    }

    /**
     * Port of Smarty's `{html_radios}` plugin. Emits a sequence of
     * `<label><input type="radio">…</label>` rows, one per entry in
     * `options` or `values`/`output`. The Smarty plugin shipped both
     * `selected` and `checked` aliases; we accept both for fidelity.
     *
     * @param array<int|string, mixed>|null $options
     * @param list<string|int>|null $values
     * @param list<string>|null $output
     * @param array<string, scalar> $extra forwarded HTML attributes
     */
    public static function htmlRadios(
        ?array $options = null,
        ?array $values = null,
        ?array $output = null,
        string|int|float|bool|null $selected = null,
        string|int|float|bool|null $checked = null,
        string $name = 'radio',
        string $separator = '',
        bool $escape = true,
        bool $labels = true,
        bool $label_ids = false,
        mixed ...$extra,
    ): Html {
        if ($options === null && $values === null) {
            return new Html('');
        }
        $sel = $selected ?? $checked;
        $selectedStr = $sel === null ? null : (string) $sel;
        $extraAttrs = '';
        /** @var mixed $val */
        foreach ($extra as $key => $val) {
            if (!is_scalar($val)) {
                continue;
            }
            $extraAttrs .= ' ' . $key . '="' . htmlspecialchars((string) $val, ENT_QUOTES) . '"';
        }
        $rows = [];
        if ($options !== null) {
            foreach ($options as $optKey => $optVal) {
                $rows[] = self::htmlRadioRow($name, $optKey, $optVal, $selectedStr, $extraAttrs, $labels, $label_ids, $escape);
            }
        } else {
            // Same `$values` non-null invariant as in `htmlOptions`.
            $output ??= [];
            foreach ($values as $i => $optKey) {
                $rows[] = self::htmlRadioRow($name, $optKey, $output[$i] ?? '', $selectedStr, $extraAttrs, $labels, $label_ids, $escape);
            }
        }
        return new Html(implode($separator === '' ? "\n" : $separator, $rows));
    }

    /**
     * Port of Smarty's `{math}` plugin. Evaluates an arithmetic
     * `equation` against named numeric vars passed via `...$vars`.
     * Mirrors Smarty's whitelist of math functions, balanced-paren
     * check, and `$`/backtick blocks.
     *
     * @param array<string, mixed>|float|int|string $vars values for free
     *     identifiers in the equation (string-keyed via PHP 8.1 named-args
     *     forwarding when called from Latte). May also include the `format`
     *     and `assign` reserved keys, which are ignored at this layer.
     */
    public static function math(string $equation, mixed ...$vars): string
    {
        /** @var array<string, true> $allowed */
        static $allowed = [
            'int' => true, 'abs' => true, 'ceil' => true, 'acos' => true,
            'acosh' => true, 'cos' => true, 'cosh' => true, 'deg2rad' => true,
            'rad2deg' => true, 'exp' => true, 'floor' => true, 'log' => true,
            'log10' => true, 'max' => true, 'min' => true, 'pi' => true,
            'pow' => true, 'rand' => true, 'round' => true, 'asin' => true,
            'asinh' => true, 'sin' => true, 'sinh' => true, 'sqrt' => true,
            'srand' => true, 'atan' => true, 'atanh' => true, 'tan' => true,
            'tanh' => true,
        ];
        $eq = preg_replace('/\s+/', '', $equation) ?? $equation;
        $number = '-?(?:\d+(?:[,.]\d+)?|pi|π)';
        $functionsOrVars = '((?:0x[a-fA-F0-9]+)|([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*))';
        $operators = '[,+\/*\^%-]';
        $regex = '/^((' . $number . '|' . $functionsOrVars . '|(' . $functionsOrVars . '\s*\((?1)*\)|\((?1)*\)))(?:' . $operators . '(?1))?)+$/';
        if (preg_match($regex, $eq) !== 1
            || substr_count($eq, '(') !== substr_count($eq, ')')
            || str_contains($eq, '`')
            || str_contains($eq, '$')
        ) {
            return '';
        }
        // `format` / `assign` are reserved in Smarty's plugin and not
        // numeric vars; drop them so the regex-rewrite below doesn't see
        // them as identifiers. Re-pin `$vars` so PHPStan tracks the
        // remaining values as numeric scalars (needed for the eval below).
        unset($vars['format'], $vars['assign']);
        $numericVars = [];
        foreach ($vars as $k => $v) {
            if (is_string($k) && is_numeric($v)) {
                $numericVars[$k] = $v;
            }
        }
        preg_match_all('!(?:0x[a-fA-F0-9]+)|([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)!', $eq, $matches);
        /** @var list<string> $idents */
        $idents = $matches[1];
        foreach ($idents as $ident) {
            if ($ident === '' || isset($allowed[$ident])) {
                continue;
            }
            if (!array_key_exists($ident, $numericVars)) {
                return '';
            }
        }
        foreach ($numericVars as $key => $val) {
            $eq = preg_replace('/\b' . preg_quote($key, '/') . '\b/', '(' . (string) $val . ')', $eq) ?? $eq;
        }
        // The validated equation contains only digits, math operators and
        // whitelisted PHP function names — eval is a deliberate match of
        // Smarty's plugin behaviour, gated by the regex above.
        $result = null;
        eval('$result = ' . $eq . ';');
        return (string) $result;
    }

    /**
     * @param array<int|string, mixed>|string|int|float|bool|null $selected
     * @return array<string, true>|string|null
     */
    private static function normalizeSelected(array|string|int|float|bool|null $selected): array|string|null
    {
        if ($selected === null) {
            return null;
        }
        if (is_array($selected)) {
            $map = [];
            foreach ($selected as $val) {
                if (is_scalar($val)) {
                    $map[htmlspecialchars((string) $val, ENT_QUOTES)] = true;
                }
            }
            return $map;
        }
        return htmlspecialchars((string) $selected, ENT_QUOTES);
    }

    /**
     * @param array<string, true>|string|null $selected
     */
    private static function htmlOption(
        int|string $optKey,
        mixed $optVal,
        array|string|null $selected,
        ?string $id,
        ?string $class,
        int &$idx,
    ): string {
        if (is_array($optVal)) {
            // Optgroup — emit a nested group with its own zeroed index.
            $inner = 0;
            $body = '<optgroup label="' . htmlspecialchars((string) $optKey, ENT_QUOTES) . '">' . "\n";
            foreach ($optVal as $k => $v) {
                $body .= self::htmlOption($k, $v, $selected, $id !== null ? $id . '-' . $idx : null, $class, $inner);
            }
            $idx++;
            return $body . "</optgroup>\n";
        }
        $key = htmlspecialchars((string) $optKey, ENT_QUOTES);
        $line = '<option value="' . $key . '"';
        if (is_array($selected)) {
            if (isset($selected[$key])) {
                $line .= ' selected="selected"';
            }
        } elseif ($selected !== null && $key === $selected) {
            $line .= ' selected="selected"';
        }
        if ($class !== null && $class !== '') {
            $line .= ' class="' . $class . ' option"';
        }
        if ($id !== null && $id !== '') {
            $line .= ' id="' . $id . '-' . $idx . '"';
        }
        $idx++;
        $label = is_scalar($optVal) ? htmlspecialchars((string) $optVal, ENT_QUOTES) : '';
        return $line . '>' . $label . '</option>' . "\n";
    }

    private static function htmlRadioRow(
        string $name,
        int|string $value,
        mixed $label,
        ?string $selected,
        string $extraAttrs,
        bool $labels,
        bool $labelIds,
        bool $escape,
    ): string {
        $valueStr = htmlspecialchars((string) $value, ENT_QUOTES);
        $checked = ($selected !== null && $valueStr === $selected) ? ' checked="checked"' : '';
        $labelStr = is_scalar($label) ? (string) $label : '';
        if ($escape) {
            $labelStr = htmlspecialchars($labelStr, ENT_QUOTES);
        }
        $idAttr = '';
        if ($labelIds) {
            $idAttr = ' id="' . $name . '_' . preg_replace('/\W/', '_', $valueStr) . '"';
        }
        $input = '<input type="radio" name="' . $name . '" value="' . $valueStr . '"' . $idAttr . $checked . $extraAttrs . '>' . $labelStr;
        if ($labels) {
            return '<label' . ($labelIds ? ' for="' . $name . '_' . preg_replace('/\W/', '_', $valueStr) . '"' : '') . '>' . $input . '</label>';
        }
        return $input;
    }
}
