<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Extension;
use Latte\Runtime\Html;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Core\DeviceHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;

/**
 * Piwigo's Latte template API -- the filter/function set `.latte` templates
 * may call, registered once per `Template` instance (see
 * `Template::latteEngine()`).
 *
 * Three groups, mirroring Smarty's own surface in `Template::__construct()`:
 *
 *  - **Translation pair** -- `translate`/`l10n`/`translate_dec`, bound to
 *    `Lang::t()`/`Lang::plural()`. Ported as plain runtime filters, not
 *    Smarty's compile-time modifiercompiler bake-in (see docs/PLAN.md's P31
 *    section, "Modifiers -> Latte filters" -- no correctness difference,
 *    revisit only if benchmarked).
 *  - **PHP passthroughs as filters** -- `sprintf`, `urlencode`, etc., plus
 *    Smarty's own built-in modifiers real templates rely on directly
 *    (`cat`, `count`, `date_format`, `default`, `join`, `lower`, `nl2br`,
 *    `number_format`, `replace`, `strip_tags`, `str_repeat`) that have no
 *    `registerPlugin()` call anywhere in `Template.php` to find by reading
 *    that file alone. Pipe-incompatible PHP functions (`implode`,
 *    `str_replace`, `preg_match`, ...) stay unregistered -- templates call
 *    them inline via `{=implode(',', $arr)}` instead (Latte's own
 *    print-expression tag), since a filter's piped value has to be the
 *    wrapped function's first argument and those don't fit that shape.
 *  - **Stateful asset/page functions** -- `combineScript`/`combineCss`/
 *    `getCombinedScripts`/`getCombinedCss`/`defineDerivative`/`htmlHead`/
 *    `htmlStyle`/`footerScript`/`localCssRules`/`getExtent` all delegate to
 *    the owning `Template` instance's own (renamed, same-body) methods --
 *    reusing its already-correct `ScriptLoader`/`CssLoader`/validation
 *    logic directly rather than re-deriving it here. `html_options`/
 *    `html_radios`/`math` are generic, stateless ports of Smarty's own
 *    stdlib plugins (no `Template` state involved).
 */
final class PiwigoExtension extends Extension
{
    public function __construct(
        private readonly Template $template,
        private readonly Lang $lang,
        private readonly AccessLevelChecker $accessLevelChecker,
        private readonly SessionService $sessionService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    /**
     * @return array<string, callable>
     */
    #[\Override]
    public function getFilters(): array
    {
        return [
            // Translation pair.
            'translate' => $this->translate(...),
            'l10n' => $this->translate(...),
            'translate_dec' => $this->translateDec(...),

            // PHP passthroughs whose first argument is the piped value.
            'sprintf' => sprintf(...),
            'urlencode' => urlencode(...),
            // Smarty's `|escape:'url'` (as opposed to its `|urlencode`
            // modifier, a straight `urlencode()` passthrough registered in
            // Template.php) compiles to `rawurlencode()`, not `urlencode()`
            // -- confirmed against vendor/smarty/smarty's own
            // EscapeModifierCompiler.php, not assumed from the reference's
            // converter comment. Using `|urlencode` for an `|escape:'url'`
            // conversion site is a real behavior change (`+` vs `%20` for
            // spaces) -- caught live via the golden-HTML diff on
            // `favorites` (footer.tpl's mailto subject).
            'rawurlencode' => rawurlencode(...),
            'intval' => intval(...),
            'json_encode' => json_encode(...),
            'json_decode' => json_decode(...),
            'htmlspecialchars' => htmlspecialchars(...),
            'stripslashes' => stripslashes(...),
            'in_array' => in_array(...),
            'ucfirst' => ucfirst(...),
            'strstr' => strstr(...),
            'stristr' => stristr(...),
            'trim' => trim(...),
            'md5' => md5(...),
            'strtolower' => strtolower(...),
            'str_ireplace' => self::strIreplace(...),
            'strpos' => strpos(...),
            'preg_match' => preg_match(...),
            'is_null' => is_null(...),
            'is_file' => is_file(...),
            'file_exists' => file_exists(...),
            'constant' => constant(...),
            'array_key_exists' => array_key_exists(...),
            'sizeOf' => sizeof(...),
            'str_replace' => self::strReplace(...),
            'lower' => strtolower(...),
            'nl2br' => nl2br(...),
            'join' => self::join(...),
            'explode' => self::explode(...),
            'ternary' => self::ternary(...),
            'cat' => self::cat(...),
            'count' => count(...),
            'strip_tags' => self::stripTags(...),
            'str_repeat' => str_repeat(...),
            'default' => self::defaultFilter(...),
            'date_format' => self::dateFormat(...),
            'number_format' => self::numberFormat(...),
            'replace' => self::replace(...),

            // Domain-specific helpers.
            'url_is_remote' => $this->urlService->urlIsRemote(...),
            'is_admin' => $this->isAdmin(...),
            'is_classic_user' => $this->isClassicUser(...),
            'get_device' => $this->getDevice(...),
            'get_gallery_home_url' => $this->urlService->getGalleryHomeUrl(...),
        ];
    }

    /**
     * @return array<string, callable>
     */
    #[\Override]
    public function getFunctions(): array
    {
        return [
            // Also registered as filters above -- Latte rejects filter
            // pipes inside {if} conditions (confirmed live converting
            // header.tpl: `{if $PAGE_TITLE != 'Home'|l10n}`), so the same
            // translate() needs to be callable both ways: `{='x'|l10n}`
            // as a filter, `{if $x != l10n('Home')}` as a function.
            'translate' => $this->translate(...),
            'l10n' => $this->translate(...),
            'translate_dec' => $this->translateDec(...),
            'combineScript' => $this->template->combineScript(...),
            'getCombinedScripts' => $this->template->getCombinedScripts(...),
            'combineCss' => $this->template->combineCss(...),
            'getCombinedCss' => $this->template->getCombinedCss(...),
            'defineDerivative' => $this->template->defineDerivative(...),
            'htmlHead' => $this->template->htmlHead(...),
            'htmlStyle' => $this->template->htmlStyle(...),
            'footerScript' => $this->template->footerScript(...),
            'localCssRules' => $this->template->localCssRules(...),
            // Template::getExtent(string $filename, string $handle): string
            // already has the exact (path, override-key) shape the
            // converter's `EXPR|get_extent:ARG` -> `getExtent(EXPR, ARG)`
            // rewrite produces -- bound directly, no wrapper needed.
            'getExtent' => $this->template->getExtent(...),
            'htmlOptions' => self::htmlOptions(...),
            'htmlRadios' => self::htmlRadios(...),
            'math' => self::math(...),
        ];
    }

    public function translate(string $key, string|int|float|bool|null|Html ...$args): string
    {
        // Html-wrapped substitution args are common when the surrounding
        // template does `|translate:$VAR|noescape` and $VAR was assigned by
        // a controller wrapping pre-escaped HTML. Cast to string so
        // sprintf's %s substitution works; the template's own `|noescape`
        // (or an outer Html wrap) controls whether the result is escaped.
        $stringArgs = array_map(
            static fn (string|int|float|bool|null|Html $a): string|int|float|bool|null => $a instanceof Html ? (string) $a : $a,
            $args,
        );

        return $this->lang->t($key, ...$stringArgs);
    }

    /**
     * `$count` is `mixed`, not `int|float`, to match `Lang::plural()`'s own
     * signature -- a template like `menubar_categories.latte` can genuinely
     * pipe a `null` count through here (`nb_total_images` is a real,
     * legitimate `?? null` fallback, not a bug), and `Lang::plural()`
     * already treats non-numeric input as 0 rather than rejecting it.
     * Confirmed live: a strict `int|float` parameter type here throws a
     * real `TypeError` on `gallery-home` the moment a fresh user has no
     * `nb_total_images` yet.
     */
    public function translateDec(mixed $count, string $singular, string $plural): string
    {
        return $this->lang->plural($singular, $plural, $count);
    }

    public function isAdmin(string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isAdmin($userStatus);
    }

    public function isClassicUser(string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isClassicUser($userStatus);
    }

    public function getDevice(): string
    {
        return DeviceHelper::getDevice($this->sessionService);
    }

    /**
     * Smarty's `|@str_replace` modifier is a bare PHP passthrough
     * (`str_replace($search, $replace, $subject)`), which puts the piped
     * value (the subject) third -- pipe-incompatible with Latte's
     * first-argument convention, so this thin wrapper reorders it back to
     * `(subject, search, replace)` for filter use: `{$x|str_replace:$search:$replace}`.
     */
    /**
     * @param list<string>|string $search
     * @param list<string>|string $replace
     */
    public static function strReplace(string $subject, array|string $search, array|string $replace): string
    {
        // $subject is a plain string (not an array), so str_replace()'s
        // return type is always string here -- no is_array() re-check
        // needed on the result.
        return str_replace($search, $replace, $subject);
    }

    /**
     * Same reordering as strReplace() above, for Smarty's `|@str_ireplace`.
     *
     * @param list<string>|string $search
     * @param list<string>|string $replace
     */
    public static function strIreplace(string $subject, array|string $search, array|string $replace): string
    {
        return str_ireplace($search, $replace, $subject);
    }

    /**
     * Smarty's `|@join:', '` modifier -- PHP's `implode($glue, $arr)` has
     * the glue first, pipe-incompatible; reordered to `(arr, glue)` here so
     * the piped array is the first argument.
     *
     * @param array<int, scalar> $pieces
     */
    public static function join(array $pieces, string $glue = ','): string
    {
        return implode($glue, $pieces);
    }

    /**
     * Behaves like Template::modExplode() -- falls back to ',' on an empty
     * delimiter, which explode('', $s) would otherwise reject.
     *
     * @return list<string>
     */
    public static function explode(string $text, string $delimiter = ','): array
    {
        return explode($delimiter !== '' ? $delimiter : ',', $text);
    }

    public static function ternary(mixed $param, mixed $true, mixed $false): mixed
    {
        return (bool) $param ? $true : $false;
    }

    /**
     * Smarty's `|cat:` modifier concatenates values onto the pipe head.
     * Multi-arg form (`{$x|cat:'a','b'}`) calls `cat($x, 'a', 'b')`.
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
     * Smarty's `|strip_tags[:bool]` modifier:
     *   - `|strip_tags` (default true) -- replaces every tag with a single
     *     space, so `<b>x</b><i>y</i>` becomes ` x  y `.
     *   - `|strip_tags:false` -- removes tags without replacement, identical
     *     to PHP's bare `strip_tags($string)`.
     *
     * PHP 8's native `strip_tags($string, $allowed)` rejects a `bool` second
     * argument with a TypeError, so this can't bind directly to
     * `strip_tags(...)` -- a real PHP-version landmine, not stylistic.
     */
    public static function stripTags(mixed $value, bool $replaceWithSpace = true): string
    {
        $s = is_scalar($value) ? (string) $value : '';

        return $replaceWithSpace
            ? (preg_replace('/<[^>]*>/', ' ', $s) ?? $s)
            : strip_tags($s);
    }

    /**
     * Smarty `|default:'fallback'` modifier -- returns the fallback when the
     * piped value is empty (null, '', 0, false, []).
     */
    public static function defaultFilter(mixed $value, mixed $fallback): mixed
    {
        return in_array($value, [null, false, 0, '0', '', []], true) ? $fallback : $value;
    }

    /**
     * Smarty `|date_format:"%d"` modifier -- maps the strftime-style format
     * to PHP `date()` for the subset Piwigo templates actually use. The
     * input value is coerced via PHP's own parsing rules (Unix timestamp,
     * ISO string, or 'now' for $smarty.now -> time()).
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
            '%d' => 'd',
            '%m' => 'm',
            '%Y' => 'Y',
            '%y' => 'y',
            '%H' => 'H',
            '%M' => 'i',
            '%S' => 's',
            '%B' => 'F',
            '%b' => 'M',
            '%A' => 'l',
            '%a' => 'D',
            '%e' => 'j',
            '%j' => 'z',
            '%p' => 'A',
            '%P' => 'a',
            '%%' => '%',
        ];

        return date(strtr($format, $map), $timestamp);
    }

    /**
     * Wrapper around PHP's number_format() with sane defaults -- Smarty's
     * `|number_format` was a plain passthrough, so Piwigo's templates either
     * call it bare (`{$n|number_format}`), relying on the zero-decimal
     * default, or with explicit args.
     */
    public static function numberFormat(int|float $number, int $decimals = 0, string $decimalSeparator = '.', string $thousandsSeparator = ','): string
    {
        return number_format($number, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    /**
     * Smarty's `|replace:$search:$replacement` built-in modifier -- a
     * plain, non-regex string replace (distinct from `str_replace`'s own
     * array-search/replace support above; Smarty's own `replace` only ever
     * takes scalar search/replacement pairs).
     */
    public static function replace(string $subject, string $search, string $replacement): string
    {
        return str_replace($search, $replacement, $subject);
    }

    /**
     * Port of Smarty's `{html_options}` plugin. Emits a list of `<option>`
     * tags from `options` (associative key=value) or from `values` paired
     * with `output`. When `name` is provided, the result is wrapped in a
     * `<select>` element.
     *
     * @param array<int|string, mixed>|null $options associative value->label map
     * @param list<string|int>|null $values raw option values (used with $output)
     * @param list<string>|null $output labels matching $values by index
     * @param array<int|string, mixed>|string|int|float|bool|null $selected
     * @param mixed ...$extra forwarded HTML attributes -- runtime
     *   is_scalar() guards which ones actually get emitted, no static
     *   narrowing needed here
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
        foreach ($extra as $key => $val) {
            if (! is_scalar($val)) {
                continue;
            }
            $extraAttrs .= ' ' . $key . '="' . htmlspecialchars((string) $val, ENT_QUOTES) . '"';
        }

        return new Html('<select name="' . $name . '"' . $extraAttrs . '>' . "\n" . $body . '</select>' . "\n");
    }

    /**
     * Port of Smarty's `{html_radios}` plugin. Emits a sequence of
     * `<label><input type="radio">...</label>` rows, one per entry in
     * `options` or `values`/`output`.
     *
     * @param array<int|string, mixed>|null $options
     * @param list<string|int>|null $values
     * @param list<string>|null $output
     * @param mixed ...$extra forwarded HTML attributes -- runtime
     *   is_scalar() guards which ones actually get emitted, no static
     *   narrowing needed here
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
        bool $labelIds = false,
        mixed ...$extra,
    ): Html {
        if ($options === null && $values === null) {
            return new Html('');
        }
        $sel = $selected ?? $checked;
        $selectedStr = $sel === null ? null : (string) $sel;
        $extraAttrs = '';
        foreach ($extra as $key => $val) {
            if (! is_scalar($val)) {
                continue;
            }
            $extraAttrs .= ' ' . $key . '="' . htmlspecialchars((string) $val, ENT_QUOTES) . '"';
        }
        $rows = [];
        if ($options !== null) {
            foreach ($options as $optKey => $optVal) {
                $rows[] = self::htmlRadioRow($name, $optKey, $optVal, $selectedStr, $extraAttrs, $labels, $labelIds, $escape);
            }
        } else {
            $output ??= [];
            foreach ($values as $i => $optKey) {
                $rows[] = self::htmlRadioRow($name, $optKey, $output[$i] ?? '', $selectedStr, $extraAttrs, $labels, $labelIds, $escape);
            }
        }

        return new Html(implode($separator === '' ? "\n" : $separator, $rows));
    }

    /**
     * Port of Smarty's `{math}` plugin. Evaluates an arithmetic `equation`
     * against named numeric vars passed via `...$vars`. Mirrors Smarty's
     * whitelist of math functions, balanced-paren check, and `$`/backtick
     * blocks.
     *
     * @param array<string, mixed>|float|int|string $vars values for free
     *     identifiers in the equation. May also include the `format` and
     *     `assign` reserved keys, which are ignored at this layer.
     */
    public static function math(string $equation, mixed ...$vars): string
    {
        /** @var array<string, true> $allowed */
        static $allowed = [
            'int' => true,
            'abs' => true,
            'ceil' => true,
            'acos' => true,
            'acosh' => true,
            'cos' => true,
            'cosh' => true,
            'deg2rad' => true,
            'rad2deg' => true,
            'exp' => true,
            'floor' => true,
            'log' => true,
            'log10' => true,
            'max' => true,
            'min' => true,
            'pi' => true,
            'pow' => true,
            'rand' => true,
            'round' => true,
            'asin' => true,
            'asinh' => true,
            'sin' => true,
            'sinh' => true,
            'sqrt' => true,
            'srand' => true,
            'atan' => true,
            'atanh' => true,
            'tan' => true,
            'tanh' => true,
        ];
        $eq = preg_replace('/\s+/', '', $equation) ?? $equation;
        $number = '-?(?:\d+(?:[,.]\d+)?|pi|\x{03c0})';
        $functionsOrVars = '((?:0x[a-fA-F0-9]+)|([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*))';
        $operators = '[,+\/*\^%-]';
        $regex = '/^((' . $number . '|' . $functionsOrVars . '|(' . $functionsOrVars . '\s*\((?1)*\)|\((?1)*\)))(?:' . $operators . '(?1))?)+$/u';
        if (preg_match($regex, $eq) !== 1
            || substr_count($eq, '(') !== substr_count($eq, ')')
            || str_contains($eq, '`')
            || str_contains($eq, '$')
        ) {
            return '';
        }
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
            if (! array_key_exists($ident, $numericVars)) {
                return '';
            }
        }
        foreach ($numericVars as $key => $val) {
            $eq = preg_replace('/\b' . preg_quote($key, '/') . '\b/', '(' . (string) $val . ')', $eq) ?? $eq;
        }
        // The validated equation contains only digits, math operators and
        // whitelisted PHP function names -- eval is a deliberate match of
        // Smarty's own plugin behavior, gated by the regex above.
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
