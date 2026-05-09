<?php

declare(strict_types=1);

namespace Piwigo\Tools\SmartyToLatte;

/**
 * Mechanical Smarty → Latte template converter.
 *
 * §1.2 Wave 2 Phase C. Applies the regex-replaceable rewrites from the
 * conversion table; constructs that don't fit a regex (custom modifiers,
 * complex assignments, broken-on-purpose patterns) need hand-fix and are
 * surfaced via the residue check on the result.
 *
 * The converter is intentionally faithful — it does NOT add `|noescape`
 * to bare prints. Smarty ran with `escape_html = false` and Latte
 * auto-escapes by default, so converted templates may render escaped
 * HTML where the original rendered raw. That's a security improvement;
 * templates that legitimately hold pre-rendered HTML should mark the
 * print explicitly with `|noescape` after review, and controllers
 * should pass HTML payloads as `Latte\Runtime\Html` objects.
 */
final class Converter
{
    public function convert(string $smartySource): string
    {
        $source = $smartySource;

        // Order matters: foreach rewrite must run before dot-access so the
        // `from=$arr` part is normalized; combine_css/script rewrites must
        // run before the generic `=` print prefix so the function calls
        // aren't accidentally promoted to expression prints.
        $source = $this->rewriteForeach($source);
        $source = $this->rewriteIfNotKeyword($source);
        $source = $this->rewriteEscapeFilters($source);
        $source = $this->rewriteCombineScript($source);
        $source = $this->rewriteCombineCss($source);
        $source = $this->rewriteDefineDerivative($source);
        $source = $this->rewriteIncludePath($source);
        $source = $this->rewriteSmartyDotAccess($source);
        $source = $this->rewritePrintedLiteralFilter($source);

        return $source;
    }

    /**
     * `{foreach from=$arr [key=k] item=v}` → `{foreach $arr as [$k =>] $v}`
     * Latte's `{foreach}` takes a PHP-like as-expression; Smarty's named
     * args don't survive.
     */
    private function rewriteForeach(string $source): string
    {
        // With key:    {foreach from=$arr key=name item=sheet}
        // Without key: {foreach from=$arr item=sheet}
        $source = preg_replace_callback(
            '/\{foreach\s+from=(\$[\w\[\]\->\.]+)\s+key=(\w+)\s+item=(\w+)\s*\}/',
            static fn (array $m): string => sprintf('{foreach %s as $%s => $%s}', $m[1], $m[2], $m[3]),
            $source,
        ) ?? $source;

        $source = preg_replace_callback(
            '/\{foreach\s+from=(\$[\w\[\]\->\.]+)\s+item=(\w+)\s*\}/',
            static fn (array $m): string => sprintf('{foreach %s as $%s}', $m[1], $m[2]),
            $source,
        ) ?? $source;

        return $source;
    }

    /**
     * `{if not $x}` → `{if !$x}`. Smarty accepts `not`, Latte rejects it.
     * The replacement is conservative: only rewrites the literal token
     * `not` between `{if` (or `{elseif`) and the next variable.
     */
    private function rewriteIfNotKeyword(string $source): string
    {
        return preg_replace(
            '/(\{(?:if|elseif)\s+(?:[^}]*?\s+)?)not\s+(\$)/',
            '$1!$2',
            $source,
        ) ?? $source;
    }

    /**
     * `{$x|escape}` → `{$x}` (Latte auto-escapes), `{$x|escape:'none'}` →
     * `{$x|noescape}`. The `escape` filter is reserved by Latte (its
     * compiler throws if you try to register one), so it must be removed
     * or replaced; the converter handles both shapes.
     */
    private function rewriteEscapeFilters(string $source): string
    {
        $source = preg_replace(
            "/\\|escape:['\"]none['\"]/",
            '|noescape',
            $source,
        ) ?? $source;
        $source = preg_replace(
            '/\|escape\b(?!:)/',
            '',
            $source,
        ) ?? $source;
        return $source;
    }

    /**
     * `{combine_script id='x' load='y' path='z' [require='r'] [version=v] [template=t]}`
     * → `{do combineScript(id: 'x', load: 'y', path: 'z'[, require: 'r'][, version: v][, template: t])}`
     */
    private function rewriteCombineScript(string $source): string
    {
        return preg_replace_callback(
            '/\{combine_script\s+([^}]+)\}/',
            fn (array $m): string => '{do combineScript(' . $this->parseSmartyArgs($m[1]) . ')}',
            $source,
        ) ?? $source;
    }

    /**
     * `{combine_css path='x' [id='y'] [version=v] [order=o] [template=t]}`
     * → `{do combineCss(path: 'x'[, id: 'y'][, version: v][, order: o][, template: t])}`
     */
    private function rewriteCombineCss(string $source): string
    {
        return preg_replace_callback(
            '/\{combine_css\s+([^}]+)\}/',
            fn (array $m): string => '{do combineCss(' . $this->parseSmartyArgs($m[1]) . ')}',
            $source,
        ) ?? $source;
    }

    /**
     * `{define_derivative name='x' type='y'}` →
     *   `{var $x = defineDerivative(type: 'y')}`
     * `{define_derivative name='x' width=W height=H [crop=C] [...]}` →
     *   `{var $x = defineDerivative(width: W, height: H, ...)}`
     *
     * Smarty's `define_derivative` mutated the engine scope via the
     * `name` arg; Latte's function returns the value and the caller
     * binds it through `{var}`.
     */
    private function rewriteDefineDerivative(string $source): string
    {
        return preg_replace_callback(
            '/\{define_derivative\s+([^}]+)\}/',
            function (array $m): string {
                $args = $this->parseSmartyArgsAsArray($m[1]);
                $name = $args['name'] ?? null;
                if ($name === null) {
                    return $m[0]; // can't rewrite, surfaces as residue
                }
                $nameClean = trim($name, "'\"");
                unset($args['name']);
                $argList = [];
                foreach ($args as $k => $v) {
                    $argList[] = "$k: $v";
                }
                return sprintf('{var $%s = defineDerivative(%s)}', $nameClean, implode(', ', $argList));
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{include file='foo.tpl' [k=v] [...]}` → `{include 'foo.latte'[, k: v]}`.
     * Renames `.tpl` to `.latte` in path literals.
     */
    private function rewriteIncludePath(string $source): string
    {
        return preg_replace_callback(
            '/\{include\s+file=([^\s}]+)([^}]*)\}/',
            function (array $m): string {
                $path = preg_replace('/\.tpl([\'"])/', '.latte$1', $m[1]) ?? $m[1];
                $rest = trim($m[2]);
                if ($rest === '') {
                    return "{include $path}";
                }
                $extras = $this->parseSmartyArgs($rest);
                return "{include $path, $extras}";
            },
            $source,
        ) ?? $source;
    }

    /**
     * `$arr.key.key2` → `$arr['key']['key2']` inside template tags.
     * Smarty uses `.` for array key access; Latte uses `[]`. Iterates
     * until no `$word.identifier` patterns remain (handles chains).
     */
    private function rewriteSmartyDotAccess(string $source): string
    {
        // Only rewrite within {...} tags so we don't touch HTML text or
        // string literals outside tag context. Use \K to anchor without
        // consuming the surrounding {...}, and a callback to walk the
        // tag's contents.
        return preg_replace_callback(
            '/\{[^}]*\}/',
            function (array $m): string {
                $tag = $m[0];
                // Skip {literal}…{/literal} markers and Latte-style `=`.
                $previous = '';
                while ($previous !== $tag) {
                    $previous = $tag;
                    $tag = preg_replace(
                        '/(\$\w+(?:\[[^\]]+\])*)\.(\w+)/',
                        "$1['$2']",
                        $tag,
                    ) ?? $tag;
                }
                return $tag;
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{'literal'|filter}` → `{='literal'|filter}` — Latte rejects bare
     * print of a string-literal-piped expression; needs the `=` prefix.
     * Variable prints like `{$x|filter}` parse as expressions in Latte
     * by default and don't need rewriting.
     */
    private function rewritePrintedLiteralFilter(string $source): string
    {
        return preg_replace(
            "/\\{(?!=)((?:'[^']*'|\"[^\"]*\")\\|\\w[\\w:'\",\\s\\$]*)\\}/",
            '{=$1}',
            $source,
        ) ?? $source;
    }

    /**
     * Parse Smarty's `key=value key2='value2'` named-arg form into a
     * Latte/PHP-8 named-arg list: `key: value, key2: 'value2'`.
     */
    private function parseSmartyArgs(string $rawArgs): string
    {
        $args = $this->parseSmartyArgsAsArray($rawArgs);
        $parts = [];
        foreach ($args as $k => $v) {
            $parts[] = "$k: $v";
        }
        return implode(', ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private function parseSmartyArgsAsArray(string $rawArgs): array
    {
        $args = [];
        // Match either: key='quoted value' or key="quoted value" or key=bareword
        $pattern = '/(\w+)=(\'[^\']*\'|"[^"]*"|-?[\w\.\$\[\]\->]+)/';
        if (preg_match_all($pattern, $rawArgs, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $args[$match[1]] = $match[2];
            }
        }
        return $args;
    }
}
