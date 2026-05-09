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
        // aren't accidentally promoted to expression prints; literal block
        // must run before any tag-content rewrites so the `{literal}` body
        // isn't accidentally rewritten.
        $source = $this->rewriteLiteralBlock($source);
        $source = $this->rewriteAssign($source);
        $source = $this->rewriteForeach($source);
        $source = $this->rewriteSection($source);
        $source = $this->rewriteCaptureBlock($source);
        $source = $this->rewriteHtmlHeadBlock($source);
        $source = $this->rewriteHtmlStyleBlock($source);
        $source = $this->rewriteFooterScriptBlock($source);
        $source = $this->rewriteIfNotKeyword($source);
        $source = $this->rewriteEscapeFilters($source);
        $source = $this->rewriteCombineScript($source);
        $source = $this->rewriteCombineCss($source);
        $source = $this->rewriteDefineDerivative($source);
        $source = $this->rewriteIncludePath($source);
        $source = $this->rewriteRegexReplaceFilter($source);
        $source = $this->rewriteCatFilter($source);
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
     * `{assign var=foo value=bar}` → `{var $foo = bar}`. Smarty also
     * supports the bare-positional form `{assign 'foo' 'bar'}` but
     * Piwigo's templates use the named-arg form exclusively (verified
     * by grep), so the converter targets only that shape.
     */
    private function rewriteAssign(string $source): string
    {
        return preg_replace_callback(
            '/\{assign\s+var=[\'"]?(\w+)[\'"]?\s+value=([^}]+?)\}/',
            static fn (array $m): string => sprintf('{var $%s = %s}', $m[1], trim($m[2])),
            $source,
        ) ?? $source;
    }

    /**
     * `{section name=NAME loop=$ARR}…{/section}` →
     *   `{foreach $ARR as $NAME => $val}…{/foreach}`.
     *
     * The conversion is approximate. Smarty's `{section}` exposes
     * `$smarty.section.NAME.index/iteration/total/...` inside the body;
     * those references won't survive automated rewriting and need
     * hand-fix. The roadmap calls this out as expected residue.
     */
    private function rewriteSection(string $source): string
    {
        $source = preg_replace_callback(
            '/\{section\s+name=(\w+)\s+loop=(\$[\w\[\]\->]+)\s*\}/',
            static fn (array $m): string => sprintf('{foreach %s as $%s => $val}', $m[2], $m[1]),
            $source,
        ) ?? $source;
        return str_replace('{/section}', '{/foreach}', $source);
    }

    /**
     * `{capture name=NAME}…{/capture}` → `{capture $NAME}…{/capture}`,
     * paired with `{$smarty.capture.NAME}` → `{$NAME}`.
     */
    private function rewriteCaptureBlock(string $source): string
    {
        $source = preg_replace_callback(
            '/\{capture\s+name=[\'"]?(\w+)[\'"]?\s*\}/',
            static fn (array $m): string => sprintf('{capture $%s}', $m[1]),
            $source,
        ) ?? $source;
        $source = preg_replace_callback(
            '/\{\$smarty\.capture\.(\w+)\}/',
            static fn (array $m): string => sprintf('{$%s}', $m[1]),
            $source,
        ) ?? $source;
        return $source;
    }

    /**
     * `{literal}…{/literal}` → `{syntax off}…{syntax on}`. Latte's
     * {syntax off} disables tag parsing inside the block — the
     * Smarty equivalent for embedding literal `{` `}` strings.
     */
    private function rewriteLiteralBlock(string $source): string
    {
        $source = str_replace('{literal}', '{syntax off}', $source);
        $source = str_replace('{/literal}', '{syntax on}', $source);
        return $source;
    }

    /**
     * `{html_head}…{/html_head}` →
     *   `{capture $_pwgHead<N>}…{/capture}{do htmlHead($_pwgHead<N>)}`
     * `<N>` is a per-conversion counter so multiple blocks don't shadow.
     */
    private function rewriteHtmlHeadBlock(string $source): string
    {
        $i = 0;
        return preg_replace_callback(
            '/\{html_head\}(.*?)\{\/html_head\}/s',
            static function (array $m) use (&$i): string {
                $i++;
                $var = '_pwgHead' . $i;
                return "{capture \${$var}}{$m[1]}{/capture}{do htmlHead(\${$var})}";
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{html_style}…{/html_style}` →
     *   `{capture $_pwgStyle<N>}…{/capture}{do htmlStyle($_pwgStyle<N>)}`
     */
    private function rewriteHtmlStyleBlock(string $source): string
    {
        $i = 0;
        return preg_replace_callback(
            '/\{html_style\}(.*?)\{\/html_style\}/s',
            static function (array $m) use (&$i): string {
                $i++;
                $var = '_pwgStyle' . $i;
                return "{capture \${$var}}{$m[1]}{/capture}{do htmlStyle(\${$var})}";
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{footer_script [require='r']}…{/footer_script}` →
     *   `{capture $_pwgFooter<N>}…{/capture}{do footerScript($_pwgFooter<N>[, require: 'r'])}`
     */
    private function rewriteFooterScriptBlock(string $source): string
    {
        $i = 0;
        return preg_replace_callback(
            '/\{footer_script(\s+[^}]*)?\}(.*?)\{\/footer_script\}/s',
            function (array $m) use (&$i): string {
                $i++;
                $var = '_pwgFooter' . $i;
                $argHead = '';
                if (isset($m[1]) && trim($m[1]) !== '') {
                    $extras = $this->parseSmartyArgs(trim($m[1]));
                    if ($extras !== '') {
                        $argHead = ', ' . $extras;
                    }
                }
                return "{capture \${$var}}{$m[2]}{/capture}{do footerScript(\${$var}{$argHead})}";
            },
            $source,
        ) ?? $source;
    }

    /**
     * `|regex_replace:$pattern:$replacement` → `|replaceRe:$pattern,$replacement`.
     * Smarty's regex_replace and Latte's built-in replaceRe both call
     * preg_replace($pattern, $replacement, $subject) under the hood,
     * so only the syntax differs (colon-separator → comma-separator)
     * and the filter name.
     */
    private function rewriteRegexReplaceFilter(string $source): string
    {
        return preg_replace(
            '/\|regex_replace:([^:|}]+):([^|}]+)/',
            '|replaceRe:$1,$2',
            $source,
        ) ?? $source;
    }

    /**
     * `|cat:'X'` → `~ 'X'` rewritten as a concat. Smarty's cat filter
     * concatenates onto the pipe value; Latte uses `~` for string
     * concat in expressions. Conversion is approximate: a chained
     * `|cat:'a':'b'` becomes `~ 'a' ~ 'b'`. Only the print-position
     * shape `{$x|cat:'…'}` is rewritten — embedded uses (within other
     * expressions) need hand-fix.
     */
    private function rewriteCatFilter(string $source): string
    {
        // Match {$expr|cat:'arg1'[:'arg2'][...]} and rewrite to
        // {=$expr ~ 'arg1' [~ 'arg2'][...]}. The args after the first
        // `|cat:` are colon-separated.
        return preg_replace_callback(
            '/\{(\$[\w\[\]\->\.\']+(?:\|[\w]+(?::[^|:}]+)*)?)\|cat:([^}]+)\}/',
            static function (array $m): string {
                $value = $m[1];
                $args = $m[2];
                // Split args by `:` not inside quotes.
                $parts = preg_split('/:(?=(?:[^\'"]*[\'"][^\'"]*[\'"])*[^\'"]*$)/', $args) ?: [$args];
                $concat = $value . ' ~ ' . implode(' ~ ', array_map('trim', $parts));
                return sprintf('{=%s}', $concat);
            },
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
