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
        $source = $this->rewriteStripBlock($source);
        $source = $this->rewriteFunctionDefinition($source);
        $source = $this->rewriteElseIf($source);
        $source = $this->rewriteOperatorKeywords($source);
        $source = $this->rewriteIfNotKeyword($source);
        $source = $this->rewriteGetCombinedCssTag($source);
        $source = $this->rewriteEscapeFilters($source);
        $source = $this->rewriteCombineScript($source);
        $source = $this->rewriteCombineCss($source);
        $source = $this->rewriteDefineDerivative($source);
        $source = $this->rewriteIncludePath($source);
        // regex_replace must rewrite before the generic multi-arg pipe
        // filter rule converts its colons to commas (the regex_replace
        // → replaceRe rename relies on the original colon shape).
        $source = $this->rewriteRegexReplaceFilter($source);
        $source = $this->rewriteMultiArgPipeFilters($source);
        $source = $this->rewriteSmartyForeachIterator($source);
        $source = $this->rewriteSmartyDotAccess($source);
        $source = $this->rewritePrintedLiteralFilter($source);

        return $source;
    }

    /**
     * `{foreach from=$arr [key=k] item=v [name=loop]}` →
     * `{foreach $arr as [$k =>] $v}`. Latte's `{foreach}` takes a
     * PHP-like as-expression; Smarty's named args don't survive.
     * The `name=loop` arg is dropped — its only consumer was
     * `$smarty.foreach.NAME.index/iteration/total`, which Latte
     * exposes via the implicit `$iterator` (rewriting those
     * references is a separate residue pattern).
     */
    private function rewriteForeach(string $source): string
    {
        // Use a single permissive regex that accepts from/key/item/name
        // in any order (Smarty allows that).
        return preg_replace_callback(
            '/\{foreach\s+([^}]+?)\s*\}/',
            static function (array $m): string {
                $args = $m[1];
                if (!preg_match('/from=(\S+)/', $args, $fm) || !preg_match('/item=(\w+)/', $args, $im)) {
                    return $m[0];
                }
                $from = $fm[1];
                $item = $im[1];
                $key = preg_match('/key=(\w+)/', $args, $km) ? $km[1] : null;
                if ($key !== null) {
                    return sprintf('{foreach %s as $%s => $%s}', $from, $key, $item);
                }
                return sprintf('{foreach %s as $%s}', $from, $item);
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{if not <expr>}` → `{if !<expr>}`. Smarty accepts `not` as a
     * unary boolean operator; Latte rejects the keyword. The rewrite
     * targets `not` followed by a variable, function call, or `(`
     * inside `{if}` or `{elseif}` tags.
     */
    private function rewriteIfNotKeyword(string $source): string
    {
        return preg_replace_callback(
            '/(\{(?:if|elseif)[^}]*?)\bnot\s+(\$|\w|\()/',
            static fn (array $m): string => $m[1] . '!' . $m[2],
            $source,
        ) ?? $source;
    }

    /**
     * Smarty's `eq`/`neq`/`ne`/`gt`/`lt`/`gte`/`lte` keyword operators,
     * plus the `is odd`/`is even` membership tests, inside
     * `{if}`/`{elseif}` tags get rewritten to PHP symbols. Latte
     * accepts `and`/`or` as PHP keywords so those pass through.
     */
    private function rewriteOperatorKeywords(string $source): string
    {
        $map = [
            'gte' => '>=',
            'lte' => '<=',
            'neq' => '!=',
            'ne' => '!=',
            'eq' => '==',
            'gt' => '>',
            'lt' => '<',
        ];
        return preg_replace_callback(
            '/\{(?:if|elseif)\s+[^}]*\}/',
            static function (array $m) use ($map): string {
                $tag = $m[0];
                foreach ($map as $smarty => $php) {
                    $tag = preg_replace('/\s' . preg_quote($smarty, '/') . '\s/', " $php ", $tag) ?? $tag;
                }
                // `<expr> is odd` / `<expr> is even` → `(<expr>) % 2 != 0` / `== 0`
                $tag = preg_replace_callback(
                    '/(\$?[\w\[\]\->\.\(\)]+)\s+is\s+(not\s+)?(odd|even)\b/',
                    static function (array $im): string {
                        $expr = $im[1];
                        $parity = $im[3] === 'odd' ? '!=' : '==';
                        $negated = isset($im[2]) && $im[2] !== '';
                        if ($negated) {
                            $parity = $parity === '!=' ? '==' : '!=';
                        }
                        return "($expr) % 2 $parity 0";
                    },
                    $tag,
                ) ?? $tag;
                return $tag;
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{else if <expr>}` (Smarty allowed the space) → `{elseif <expr>}`.
     */
    private function rewriteElseIf(string $source): string
    {
        return preg_replace(
            '/\{else\s+if\s/',
            '{elseif ',
            $source,
        ) ?? $source;
    }

    /**
     * `{function name=foo}…{/function}` or `{function foo}…{/function}`
     * (positional shorthand) → `{define foo}…{/define}`. Latte's
     * user-template-function syntax mirrors Smarty's intent but uses
     * `{define}` as the keyword.
     *
     * Some Piwigo templates declare both forms back-to-back as a
     * Smarty 5 cross-version hack:
     *   {function name=tagContent}
     *   {function tagContent}
     *   ...body...
     *   {/function}
     *   {/function}
     *
     * After both rewrites land, the body lives between the second
     * opener and the first closer; the first opener and the last
     * closer are duplicates. The dedupe pass below collapses the
     * adjacent `{define X}\n{define X}` and `{/define}\n{/define}`
     * pairs into one each.
     */
    private function rewriteFunctionDefinition(string $source): string
    {
        $source = preg_replace_callback(
            '/\{function\s+name=[\'"]?(\w+)[\'"]?\s*\}/',
            static fn (array $m): string => sprintf('{define %s}', $m[1]),
            $source,
        ) ?? $source;
        // Positional: {function foo} (no `name=`)
        $source = preg_replace_callback(
            '/\{function\s+(\w+)\s*\}/',
            static fn (array $m): string => sprintf('{define %s}', $m[1]),
            $source,
        ) ?? $source;
        $source = str_replace('{/function}', '{/define}', $source);

        // Dedupe adjacent identical define-pair lines.
        $source = preg_replace(
            '/(\{define (\w+)\})\s*\n\s*\1/',
            '$1',
            $source,
        ) ?? $source;
        $source = preg_replace(
            '/(\{\/define\})\s*\n\s*\1/',
            '$1',
            $source,
        ) ?? $source;

        return $source;
    }

    /**
     * `{get_combined_css}` → `{=getCombinedCss()}` and
     * `{get_combined_scripts load='X'}` → `{=getCombinedScripts(load: 'X')}`.
     * Smarty registered them as compiler/function plugins; Latte calls
     * the same functions in PiwigoExtension::getFunctions().
     */
    private function rewriteGetCombinedCssTag(string $source): string
    {
        $source = str_replace('{get_combined_css}', '{=getCombinedCss()}', $source);
        return preg_replace_callback(
            '/\{get_combined_scripts(?:\s+([^}]+))?\}/',
            function (array $m): string {
                $args = isset($m[1]) ? $this->parseSmartyArgs($m[1]) : '';
                return '{=getCombinedScripts(' . $args . ')}';
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{strip}…{/strip}` → `{spaceless}…{/spaceless}`. Smarty's
     * whitespace-strip block; Latte's equivalent is `{spaceless}`.
     */
    private function rewriteStripBlock(string $source): string
    {
        $source = str_replace('{strip}', '{spaceless}', $source);
        return str_replace('{/strip}', '{/spaceless}', $source);
    }

    /**
     * Smarty multi-arg pipe filter syntax: `{$x|filter:a:b}` calls
     * filter($x, a, b). Latte uses comma-separated args:
     * `{$x|filter:a,b}`. Rewrites all `:` inside an arg list to `,`,
     * stopping at `|` (next filter) or `}` (end of tag), preserving
     * colons inside quoted strings.
     */
    private function rewriteMultiArgPipeFilters(string $source): string
    {
        return preg_replace_callback(
            '/\|(\w+):([^|}]+)/',
            static function (array $m): string {
                $filter = $m[1];
                $args = $m[2];
                $rewritten = preg_replace_callback(
                    '/(\'(?:\\\\.|[^\'])*\'|"(?:\\\\.|[^"])*"|:)/',
                    static fn (array $mm): string => $mm[1] === ':' ? ',' : $mm[1],
                    $args,
                ) ?? $args;
                return '|' . $filter . ':' . $rewritten;
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{$x|escape}` → `{$x}` (Latte auto-escapes), `{$x|escape:'none'}` →
     * `{$x|noescape}`. The `escape` filter is reserved by Latte (its
     * compiler throws if you try to register one), so it must be removed
     * or replaced; the converter handles all argumented forms — both
     * quoted (`|escape:'html'`) and unquoted (`|escape:html`) — by
     * dropping them, since Latte's auto-escape covers the common cases
     * (html, htmlall, url, javascript).
     */
    private function rewriteEscapeFilters(string $source): string
    {
        // {$x|escape:'none'} → {$x|noescape}
        $source = preg_replace(
            "/\\|escape:['\"]none['\"]/",
            '|noescape',
            $source,
        ) ?? $source;
        // {$x|escape:'html'} or |escape:html or |escape:'htmlall' etc. — drop.
        $source = preg_replace(
            "/\\|escape:['\"]?\\w+['\"]?/",
            '',
            $source,
        ) ?? $source;
        // Bare {$x|escape} — drop.
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
     * `$smarty.foreach.LOOP.index/iteration/first/last/total` →
     * Latte's implicit `$iterator->getCounter() - 1` / `getCounter()` /
     * `isFirst()` / `isLast()` / `getTotalCount()`. Latte exposes a
     * single `$iterator` per foreach scope; the LOOP name doesn't
     * matter, so we just drop it.
     */
    private function rewriteSmartyForeachIterator(string $source): string
    {
        $map = [
            'index' => '($iterator->getCounter() - 1)',
            'iteration' => '$iterator->getCounter()',
            'first' => '$iterator->isFirst()',
            'last' => '$iterator->isLast()',
            'total' => '$iterator->getTotalCount()',
        ];
        foreach ($map as $key => $replacement) {
            $source = preg_replace(
                '/\$smarty\.foreach\.\w+\.' . $key . '\b/',
                $replacement,
                $source,
            ) ?? $source;
        }
        return $source;
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
     * supports the positional form `{assign 'foo' value}` and the
     * `{$x|cat:'a'|...}` style — handle both. Wraps the right-hand
     * side in parens when it contains a pipe filter, since Latte's
     * `{var}` rejects bare pipes.
     */
    private function rewriteAssign(string $source): string
    {
        $wrap = static function (string $name, string $rawValue): string {
            $value = trim($rawValue);
            if (str_contains($value, '|')) {
                $value = "($value)";
            }
            return sprintf('{var $%s = %s}', $name, $value);
        };

        $source = preg_replace_callback(
            '/\{assign\s+var=[\'"]?(\w+)[\'"]?\s+value=([^}]+?)\}/',
            static fn (array $m): string => $wrap($m[1], $m[2]),
            $source,
        ) ?? $source;
        // Positional form: {assign 'name' value}
        $source = preg_replace_callback(
            '/\{assign\s+[\'"](\w+)[\'"]\s+([^}]+?)\}/',
            static fn (array $m): string => $wrap($m[1], $m[2]),
            $source,
        ) ?? $source;

        return $source;
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
