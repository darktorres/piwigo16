<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\FilterNode;
use Latte\Compiler\Nodes\Php\IdentifierNode;
use Latte\Compiler\Nodes\PrintNode;
use Latte\Compiler\Tag;
use Latte\Compiler\TemplateParser;
use Latte\Compiler\Token;
use Latte\Extension;
use Latte\Runtime\Html;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
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
 *    (`default`, `lower`, `nl2br`, `replace`, `str_repeat`) that have no
 *    `registerPlugin()` call anywhere in `Template.php` to find by
 *    reading that file alone. `cat`/`count`/`join`/`strip_tags` were the
 *    same shape once, migrated onto Latte's own built-in `~`/`|length`/
 *    `|implode`/`|striptags` (P43-B, docs/PLAN.md) -- `nl2br` stays a
 *    registered passthrough rather than migrating fully to `|breakLines`,
 *    since 3 of its 5 real call sites need `|htmlspecialchars`'s
 *    `ENT_QUOTES` escaping for a double-quoted HTML attribute context
 *    that `|breakLines`'s own internal `ENT_NOQUOTES` escaping doesn't
 *    provide.
 *    Pipe-incompatible PHP functions (`implode`, `str_replace`,
 *    `preg_match`, ...) stay unregistered -- templates call them inline
 *    via `{=implode(',', $arr)}` instead (Latte's own print-expression
 *    tag), since a filter's piped value has to be the wrapped function's
 *    first argument and those don't fit that shape.
 *  - **Stateful asset/page functions** -- `combineScript`/`combineCss`/
 *    `getCombinedScripts`/`getCombinedCss`/`htmlHead`/`footerScript`/
 *    `exposeData`/`exposeString`/`getPageDataScript` all delegate to the
 *    owning `Template` instance's own (renamed, same-body) methods --
 *    reusing its already-correct `PageAssets`/`PageState` validation
 *    logic directly rather than re-deriving it here (the last three
 *    accumulate into `PageState`, docs/PLAN.md's P37). `html_options`/
 *    `html_radios` are generic, stateless ports of Smarty's own
 *    stdlib plugins (no `Template` state involved).
 */
final class PiwigoExtension extends Extension
{
    public function __construct(
        private readonly Template $template,
        private readonly Lang $lang,
        private readonly AccessLevelChecker $accessLevelChecker,
        private readonly UrlServiceInterface $urlService,
    ) {}

    /**
     * @return array<string, callable>
     */
    #[Override]
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
            // -- see vendor/smarty/smarty's own
            // EscapeModifierCompiler.php, not the reference's converter
            // comment. Using `|urlencode` for an `|escape:'url'`
            // conversion site is a real behavior change (`+` vs `%20` for
            // spaces), e.g. footer.latte's mailto subject.
            'rawurlencode' => rawurlencode(...),
            'intval' => intval(...),
            'json_encode' => json_encode(...),
            'htmlspecialchars' => htmlspecialchars(...),
            'in_array' => in_array(...),
            'ucfirst' => ucfirst(...),
            'is_null' => is_null(...),
            'str_replace' => self::strReplace(...),
            'lower' => strtolower(...),
            // Kept live: 3 of the 5 real nl2br sites are inside a
            // double-quoted HTML attribute, relying on the preceding
            // explicit |htmlspecialchars's ENT_QUOTES escaping -- Latte's
            // own |breakLines filter escapes with ENT_NOQUOTES
            // internally (quotes left unescaped), which would reopen an
            // attribute-breakout for those 3 sites specifically. The
            // other 2 real sites (plain HTML body text, no attribute
            // context) already migrated to |breakLines.
            'nl2br' => nl2br(...),
            'str_repeat' => str_repeat(...),
            'default' => self::defaultFilter(...),
            'replace' => self::replace(...),

            // Domain-specific helpers.
            'url_is_remote' => $this->urlService->urlIsRemote(...),
            'is_admin' => $this->isAdmin(...),
            'is_classic_user' => $this->isClassicUser(...),
        ];
    }

    /**
     * @return array<string, callable(Tag, TemplateParser):PrintNode>
     */
    #[Override]
    public function getTags(): array
    {
        return [
            '_' => $this->parseTranslate(...),
            'translate' => $this->parseTranslate(...),
        ];
    }

    /**
     * `{_ 'key', arg1, arg2}` / `{translate 'key', arg1, arg2}` -- compiles
     * to the same `translate` filter call `{='key'|translate:arg1:arg2}`
     * already produces (see getFilters()'s own `'translate' =>
     * $this->translate(...)` registration below); this is a shorter,
     * Latte-native call syntax on top of the existing mechanism, not a
     * second one. Modeled on Latte's own
     * `TranslatorExtension::parseTranslate()`
     * (vendor/latte/latte/src/Latte/Essential/TranslatorExtension.php),
     * minus its compile-time-baking branch -- `translate()` reads
     * `$this->lang` instance state, so nothing here is ever statically
     * resolvable at compile time.
     */
    private function parseTranslate(Tag $tag): PrintNode
    {
        $tag->outputMode = $tag::OutputKeepIndentation;
        $tag->expectArguments();
        $node = new PrintNode();
        $node->expression = $tag->parser->parseUnquotedStringOrExpression();
        $args = new ArrayNode();
        if ($tag->parser->stream->tryConsume(',') instanceof Token) {
            $args = $tag->parser->parseArguments();
        }

        $node->modifier = $tag->parser->parseModifier();
        $node->modifier->escape = ! $node->modifier->removeFilter('noescape') instanceof FilterNode;

        array_unshift($node->modifier->filters, new FilterNode(new IdentifierNode('translate'), $args->toArguments()));

        return $node;
    }

    /**
     * @return array<string, callable>
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            // Also registered as filters above -- Latte rejects filter
            // pipes inside {if} conditions (e.g.
            // header.latte: `{if $PAGE_TITLE != 'Home'|l10n}`), so the same
            // translate() needs to be callable both ways: `{='x'|l10n}`
            // as a filter, `{if $x != l10n('Home')}` as a function.
            'translate' => $this->translate(...),
            'l10n' => $this->translate(...),
            'translate_dec' => $this->translateDec(...),
            'combineScript' => $this->template->combineScript(...),
            'getCombinedScripts' => $this->template->getCombinedScripts(...),
            'combineCss' => $this->template->combineCss(...),
            'getCombinedCss' => $this->template->getCombinedCss(...),
            'htmlHead' => $this->template->htmlHead(...),
            'footerScript' => $this->template->footerScript(...),
            'exposeData' => $this->template->exposeData(...),
            'exposeString' => $this->template->exposeString(...),
            'getPageDataScript' => $this->template->getPageDataScript(...),
            'htmlOptions' => self::htmlOptions(...),
            'htmlRadios' => self::htmlRadios(...),
            'once' => $this->template->once(...),
            // Also registered as filters above, same names -- same
            // {if}-rejects-pipes reason as translate/l10n above. Real live
            // sites: search_filters.inc.latte's `{if ''|is_admin}` ->
            // `{if is_admin('')}`; picture_modify.latte's
            // `{if !($PATH|url_is_remote)}` -> `{if !url_is_remote($PATH)}`.
            'is_admin' => $this->isAdmin(...),
            'is_classic_user' => $this->isClassicUser(...),
            'url_is_remote' => $this->urlService->urlIsRemote(...),
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
     * already treats non-numeric input as 0 rather than rejecting it. A
     * strict `int|float` parameter type here would throw a real `TypeError`
     * on `gallery-home` the moment a fresh user has no `nb_total_images`
     * yet.
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
     * Smarty `|default:'fallback'` modifier -- returns the fallback when the
     * piped value is empty (null, '', 0, false, []).
     */
    public static function defaultFilter(mixed $value, mixed $fallback): mixed
    {
        return in_array($value, [null, false, 0, '0', '', []], true) ? $fallback : $value;
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
            $extraAttrs .= ' ' . $key . '="' . self::escapeHtmlOption((string) $val) . '"';
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
            $extraAttrs .= ' ' . $key . '="' . self::escapeHtmlOption((string) $val) . '"';
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
     * `html_options`/`html_radios`' shared escaping -- Smarty's own
     * `smarty_function_escape_special_chars()` (vendor/smarty/smarty/src/
     * functions.php) calls `htmlspecialchars($string, ENT_COMPAT,
     * Smarty::$_CHARSET, false)`: `ENT_COMPAT` (double quotes only, not
     * single -- every call site here only ever lands inside a
     * double-quoted attribute) and, critically, `$double_encode = false`.
     * A bare `htmlspecialchars($str, ENT_QUOTES)` (PHP's `double_encode`
     * default is `true`) double-encodes any option label that already
     * contains a real HTML entity -- e.g.
     * permalinks.latte: `CategoryAdminService`'s indentation prefix bakes in
     * literal `&nbsp;` sequences, which came out as `&amp;nbsp;` before
     * this fix, a real rendering regression
     * (not a cosmetic ENT_QUOTES-vs-ENT_COMPAT difference).
     */
    private static function escapeHtmlOption(string $value): string
    {
        return htmlspecialchars($value, ENT_COMPAT, 'UTF-8', false);
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
                    $map[self::escapeHtmlOption((string) $val)] = true;
                }
            }

            return $map;
        }

        return self::escapeHtmlOption((string) $selected);
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
            $body = '<optgroup label="' . self::escapeHtmlOption((string) $optKey) . '">' . "\n";
            foreach ($optVal as $k => $v) {
                $body .= self::htmlOption($k, $v, $selected, $id !== null ? $id . '-' . $idx : null, $class, $inner);
            }
            $idx++;

            return $body . "</optgroup>\n";
        }
        $key = self::escapeHtmlOption((string) $optKey);
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
        $label = is_scalar($optVal) ? self::escapeHtmlOption((string) $optVal) : '';

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
        $valueStr = self::escapeHtmlOption((string) $value);
        $checked = ($selected !== null && $valueStr === $selected) ? ' checked="checked"' : '';
        $labelStr = is_scalar($label) ? (string) $label : '';
        if ($escape) {
            $labelStr = self::escapeHtmlOption($labelStr);
        }
        $idAttr = '';
        if ($labelIds) {
            $idAttr = ' id="' . $name . '_' . preg_replace('/\W/', '_', $valueStr) . '"';
        }
        // Self-closing ' />' (XHTML style), not a plain '>' -- matches
        // Smarty's own html_radios/html_checkboxes shared implementation
        // (vendor/smarty/smarty/src/FunctionHandler/HtmlBase.php's
        // `$_output .= $extra . ' />' . $output;`). profile_content.latte's
        // "Expand all albums" radios is the first real caller to actually
        // exercise this.
        $input = '<input type="radio" name="' . $name . '" value="' . $valueStr . '"' . $idAttr . $checked . $extraAttrs . ' />' . $labelStr;
        if ($labels) {
            return '<label' . ($labelIds ? ' for="' . $name . '_' . preg_replace('/\W/', '_', $valueStr) . '"' : '') . '>' . $input . '</label>';
        }

        return $input;
    }
}
