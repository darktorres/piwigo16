<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Extension;
use Latte\Runtime\Html;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\Combinable;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;

/**
 * Latte extension wiring Piwigo-specific filters/functions.
 *
 * Coverage as of §1.2 Wave 2 Phase B (B.1 + B.2 + B.3):
 *  - Translation pair: translate, translate_dec.
 *  - PHP-passthrough modifiers whose first-arg matches Smarty's "pipe
 *    value goes first" convention (sprintf, urlencode, intval, …).
 *  - Custom stateless modifiers (l10n, explode, ternary, url_is_remote,
 *    is_admin, is_classic_user, get_device, get_gallery_home_url,
 *    get_extent).
 *  - Stateful asset functions delegating to TemplateRegistry::current()
 *    (combineScript, getCombinedScripts, combineCss, getCombinedCss,
 *    defineDerivative, htmlHead, htmlStyle, footerScript).
 *
 * Two Smarty filters from Template.php are intentionally not ported —
 * neither maps cleanly onto Latte's compilation model:
 *
 *  - prefilter_white_space: a regex pass over Smarty SOURCE that
 *    strips leading whitespace before specific Smarty tags ({if},
 *    {foreach}, {include}, {else}, {combine_script}, {html_head},
 *    {footer_script}, {section}). Latte preserves output whitespace
 *    by default but provides {spaceless} for explicit zones; the
 *    mechanical Smarty → Latte converter wraps tag-heavy blocks in
 *    {spaceless} where the prefilter would have stripped them, so the
 *    pass is unnecessary on the Latte side.
 *
 *  - postfilterLanguage: a regex pass over Smarty's COMPILED PHP that
 *    constant-folds `<?php echo 'literal';?>` after `Lang::t('key')`
 *    is bound at compile time when Config::compiledTemplateCacheLanguage()
 *    is on. Latte compiles |translate to a runtime filter call, so
 *    the constant-fold needs a Latte compiler pass (NodeVisitor) that
 *    rewrites `{=$x|translate}` to a literal string when $x is a
 *    string-literal expression and language caching is enabled. That's
 *    a future optimization once profiling justifies it.
 */
final class PiwigoExtension extends Extension
{
    /** @return array<string, callable> */
    #[\Override]
    public function getFilters(): array
    {
        return [
            // Phase A — translation pair.
            'translate' => self::translate(...),
            'translate_dec' => self::translateDec(...),

            // Phase B.1 — PHP passthroughs whose first argument matches
            // Smarty's "pipe value goes first" convention. Templates that
            // pipe `{$x|sprintf:'%d',$n}` keep working without a rename
            // after the Smarty → Latte conversion.
            //
            // Pipe-broken on PHP 8+ are deliberately omitted: implode
            // (PHP wants $glue,$array — Smarty pipes $array first),
            // str_replace / str_ireplace / preg_match (PHP wants
            // $search/$pattern first), strstr/stristr where the haystack
            // matches the pipe value but argument-only PHP signatures
            // differ. Verified against `themes/ plugins/`: the only
            // in-the-wild pipe use among these is `|in_array` (1 site,
            // PHP signature happens to match Smarty's), so the rest can
            // stay un-registered. Templates that need them in Latte call
            // the underlying function in an expression: `{=implode(',',
            // $arr)}` rather than `{$arr|implode:','}`.
            'sprintf' => sprintf(...),
            'urlencode' => urlencode(...),
            'intval' => intval(...),
            'file_exists' => file_exists(...),
            'constant' => constant(...),
            'json_encode' => json_encode(...),
            'json_decode' => json_decode(...),
            'htmlspecialchars' => htmlspecialchars(...),
            'stripslashes' => stripslashes(...),
            'in_array' => in_array(...),
            'ucfirst' => ucfirst(...),
            'trim' => trim(...),
            'md5' => md5(...),
            'strtolower' => strtolower(...),
            'is_null' => is_null(...),
            'is_file' => is_file(...),
            'strpos' => strpos(...),
            'sizeOf' => sizeof(...),
            'nl2br' => nl2br(...),
            'number_format' => self::numberFormat(...),
            'cat' => self::cat(...),
            'count' => count(...),
            'strip_tags' => self::stripTags(...),
            'str_repeat' => str_repeat(...),
            'default' => self::defaultFilter(...),
            'date_format' => self::dateFormat(...),

            // Phase B.2 — small custom modifiers. Each is a one-liner
            // delegating to an existing service.
            'l10n' => self::translate(...),
            'explode' => self::explode(...),
            'ternary' => self::ternary(...),
            'url_is_remote' => UrlService::urlIsRemote(...),
            'is_admin' => self::isAdmin(...),
            'is_classic_user' => self::isClassicUser(...),
            'get_device' => self::getDevice(...),
            'get_gallery_home_url' => self::getGalleryHomeUrl(...),

            // Phase B.3 — get_extent is registered as a Smarty modifier
            // (`{$filename|get_extent:$handle}`), so it stays a filter
            // here even though the rest of B.3 is functions.
            'get_extent' => self::getExtent(...),
        ];
    }

    /**
     * Latte function tags. Phase B.3: stateful asset-pipeline functions
     * that delegate to the active Template instance via TemplateRegistry.
     * Both engines share the same loaders so a Latte template's
     * `combineScript` accumulates into the same bundle a Smarty
     * template's `{combine_script}` would.
     *
     * @return array<string, callable>
     */
    #[\Override]
    public function getFunctions(): array
    {
        return [
            'combineScript' => self::combineScript(...),
            'getCombinedScripts' => self::getCombinedScripts(...),
            'combineCss' => self::combineCss(...),
            'getCombinedCss' => self::getCombinedCss(...),
            'defineDerivative' => self::defineDerivative(...),
            'htmlHead' => self::htmlHead(...),
            'htmlStyle' => self::htmlStyle(...),
            'footerScript' => self::footerScript(...),
            'htmlOptions' => self::htmlOptions(...),
            'htmlRadios' => self::htmlRadios(...),
            'math' => self::math(...),
            'url_is_remote' => UrlService::urlIsRemote(...),
            'l10n' => self::translate(...),
            // get_extent is also exposed as a function so converted
            // templates can use it inline in `{include}` template-name
            // expressions where Latte's parser doesn't accept a pipe-
            // filter chain alongside named-arg payloads. Templates that
            // want the filter shape (`{$file|get_extent:$handle}`) keep
            // working via the filter registration above.
            'getExtent' => self::getExtent(...),
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

    public static function translate(string $key, string|int|float|bool|null ...$args): string
    {
        return Lang::t($key, ...$args);
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
        return ServiceLocator::get(PermissionService::class)->isAdmin($userStatus);
    }

    public static function isClassicUser(string $userStatus = ''): bool
    {
        return ServiceLocator::get(PermissionService::class)->isClassicUser($userStatus);
    }

    public static function getDevice(): string
    {
        return Util::get()->getDevice();
    }

    public static function getGalleryHomeUrl(): string
    {
        return ServiceLocator::get(UrlGenerator::class)->gallery();
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

    /**
     * Mirrors Template::getExtent: when a plugin has registered an
     * extension override for `$handle`, return its absolute path;
     * otherwise return the original filename.
     */
    public static function getExtent(string $filename = '', string $handle = ''): string
    {
        $tpl = TemplateRegistry::current();
        $override = $tpl->extents[$handle] ?? null;
        return is_string($override) ? $override : $filename;
    }

    // ---- Phase B.3: stateful asset-pipeline functions --------------------

    /**
     * @param list<string>|string $require comma-separated string from the
     *     converter or list<string> from a hand-written Latte template.
     */
    public static function combineScript(
        string $id,
        string $load = 'header',
        ?string $path = null,
        array|string $require = [],
        string|int $version = 0,
        bool $template = false,
    ): void {
        $loadMode = match ($load) {
            'header' => 0,
            'footer' => 1,
            'async' => 2,
            default => throw new \ValueError("combineScript: invalid 'load' parameter: $load"),
        };
        $requireList = is_string($require)
            ? ($require === '' ? [] : explode(',', $require))
            : $require;

        TemplateRegistry::current()->scriptLoader->add(
            $id,
            $loadMode,
            $requireList,
            $path,
            $version,
            $template,
        );
    }

    /**
     * Returns the marker the ScriptLoader rewrites to <script> tags at
     * flush time for the header pass; for the footer pass, returns the
     * already-serialised <script> markup. Mirrors Template::funcGetCombinedScripts.
     *
     * Returns `Latte\Runtime\Html` so the HTML payload propagates through
     * Latte's auto-escape without needing `|noescape` at every call site.
     */
    public static function getCombinedScripts(string $load = 'header'): Html
    {
        if ($load === 'header') {
            return new Html(Template::COMBINED_SCRIPTS_TAG);
        }

        $tpl = TemplateRegistry::current();
        $scripts = $tpl->scriptLoader->getFooterScripts();
        $content = [];

        foreach ($scripts[0] as $script) {
            $src = self::makeScriptSrc($script);
            $type = self::isModuleScript($script) ? 'module' : 'text/javascript';
            $content[] = '<script type="' . $type . '" src="' . $src . '"></script>';
        }

        if (count($tpl->scriptLoader->inline_scripts) > 0) {
            $content[] = '<script type="module">';
            foreach ($tpl->scriptLoader->inline_scripts as $inline) {
                $content[] = $inline;
            }
            $content[] = '</script>';
        }

        if (count($scripts[1]) > 0) {
            $content[] = '<script type="text/javascript">';
            $content[] = "(function() {\nvar s,after = document.getElementsByTagName('script')[document.getElementsByTagName('script').length-1];";
            foreach ($scripts[1] as $script) {
                $src = self::makeScriptSrc($script);
                $stype = self::isModuleScript($script) ? 'module' : 'text/javascript';
                $content[] = "s=document.createElement('script'); s.type='{$stype}'; s.async=true; s.src='{$src}';";
                $content[] = 'after = after.parentNode.insertBefore(s, after);';
            }
            $content[] = '})();';
            $content[] = '</script>';
        }

        return new Html(implode("\n", $content));
    }

    private static function isModuleScript(Combinable $script): bool
    {
        return str_starts_with($script->path, 'dist/');
    }

    private static function makeScriptSrc(Combinable $script): string
    {
        if ($script->isRemote()) {
            $src = $script->path;
        } else {
            $src = UrlService::getRootUrl() . $script->path;
            $src .= '?v' . ($script->version !== 0 && $script->version !== '' ? $script->version : AppInfo::VERSION);
        }
        // The `combined_script` event contract is "first arg is the URL,
        // mutated in place"; PHPStan's TriggerChangeDynamicReturnType
        // extension narrows dispatch's return to the first-arg type, but
        // psalm sees `mixed`, hence the `(string)` cast — defensive against
        // a misbehaving plugin and load-bearing for psalm narrowing.
        $src = (string) EventDispatcher::dispatch('combined_script', $src, $script);
        $embellished = UrlService::embellishUrl($src);
        // embellishUrl returns string|array, keyed by input shape
        // (string→string, array→array). We always pass a string in,
        // so the array branch is unreachable — guard explicitly so
        // the static return type stays `string`.
        if (is_array($embellished)) {
            return $src;
        }
        return $embellished;
    }

    public static function combineCss(
        string $path,
        ?string $id = null,
        string|int $version = 0,
        int $order = 0,
        bool $template = false,
    ): void {
        TemplateRegistry::current()->cssLoader->add(
            $id ?? md5($path),
            $path,
            $version,
            $order,
            $template,
        );
    }

    public static function getCombinedCss(): Html
    {
        return new Html(Template::COMBINED_CSS_TAG);
    }

    /**
     * Returns the configured DerivativeParams. Smarty's
     * {define_derivative name=foo type=thumb} would assign $foo via the
     * engine; in Latte the caller does {var $foo = defineDerivative(type:
     * 'thumb')}.
     */
    public static function defineDerivative(
        ?string $type = null,
        ?int $width = null,
        ?int $height = null,
        bool|int|float|string $crop = false,
        ?int $minWidth = null,
        ?int $minHeight = null,
    ): DerivativeParams {
        if ($type !== null) {
            return ImageStdParams::getByType($type);
        }
        if ($width === null || $height === null) {
            throw new \InvalidArgumentException('defineDerivative requires either type, or width and height');
        }
        if (is_bool($crop)) {
            $cropFraction = $crop ? 1 : 0;
        } else {
            $cropFraction = round((float) $crop / 100.0, 2);
        }
        $effMinW = $cropFraction !== 0 ? ($minWidth ?? $width) : null;
        $effMinH = $cropFraction !== 0 ? ($minHeight ?? $height) : null;
        if ($effMinW !== null && $effMinW > $width) {
            throw new \InvalidArgumentException('defineDerivative: min_width > width');
        }
        if ($effMinH !== null && $effMinH > $height) {
            throw new \InvalidArgumentException('defineDerivative: min_height > height');
        }
        return ImageStdParams::getCustom($width, $height, $cropFraction, $effMinW, $effMinH);
    }

    public static function htmlHead(string|Html $content): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed !== '') {
            TemplateRegistry::current()->html_head_elements[] = $trimmed;
        }
    }

    public static function htmlStyle(string|Html $content): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed !== '') {
            $tpl = TemplateRegistry::current();
            // html_style is private on Template; the field's only mutator is
            // this exact pattern (Smarty's blockHtmlStyle), so expose via
            // public method on Template would be cleaner long-term — wired
            // through a getter for now.
            $tpl->appendHtmlStyle($trimmed);
        }
    }

    /**
     * @param list<string>|string $require comma-separated string from the
     *     converter or list<string> from a hand-written Latte template.
     */
    public static function footerScript(string|Html $content, array|string $require = []): void
    {
        $trimmed = trim((string) $content);
        if ($trimmed === '') {
            return;
        }
        $requireList = is_string($require)
            ? ($require === '' ? [] : explode(',', $require))
            : $require;
        TemplateRegistry::current()->scriptLoader->addInline($trimmed, $requireList);
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
