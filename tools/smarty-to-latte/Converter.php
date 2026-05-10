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
        // Strip nested `{$X}` print sub-tags before the keyword/operator
        // passes — those passes can't see past an inner `}` byte, so the
        // unwrap has to land first.
        $source = $this->rewriteEmbeddedPrintInTag($source);
        $source = $this->rewriteBacktickInterpolation($source);
        // Resolve Smarty dot-access (`$x.foo` → `$x['foo']`) early so the
        // pipe-in-if and other identifier-shaped rewrites below can match
        // on the canonical bracket form rather than the dotted Smarty form.
        $source = $this->rewriteSmartyDotAccess($source);
        $source = $this->rewriteOperatorKeywords($source);
        $source = $this->rewriteIfNotKeyword($source);
        $source = $this->rewritePipeFilterInIf($source);
        $source = $this->rewriteIteratorAttribute($source);
        $source = $this->rewriteIfBreakIdiom($source);
        $source = $this->rewriteGetCombinedCssTag($source);
        $source = $this->rewriteEscapeFilters($source);
        $source = $this->rewriteCombineScript($source);
        $source = $this->rewriteCombineCss($source);
        $source = $this->rewriteDefineDerivative($source);
        $source = $this->rewriteHtmlOptions($source);
        $source = $this->rewriteHtmlRadios($source);
        $source = $this->rewriteMath($source);
        $source = $this->rewriteCounter($source);
        $source = $this->rewriteIncludePath($source);
        // User-defined function call rewrite needs the {define} blocks
        // already rewritten (the rewriteFunctionDefinition pass above).
        $source = $this->rewriteUserDefinedFunctionCall($source);
        $source = $this->rewriteFilterArgBraces($source);
        // regex_replace must rewrite before the generic multi-arg pipe
        // filter rule converts its colons to commas (the regex_replace
        // → replaceRe rename relies on the original colon shape).
        $source = $this->rewriteRegexReplaceFilter($source);
        $source = $this->rewriteMultiArgPipeFilters($source);
        $source = $this->rewriteSmartyForeachIterator($source);
        $source = $this->rewritePrintedLiteralFilter($source);
        $source = $this->passForeachLocalsToIncludes($source);
        $source = $this->addNoescapeToJsonScriptBlocks($source);
        $source = $this->addNoescapeToHtmlBearingTranslations($source);
        $source = $this->addNoescapeToHtmlLiteralRepeats($source);

        return $source;
    }

    /**
     * `{='</ul></li>'|str_repeat:N}` — Smarty (escape_html=false) printed
     * the repeated HTML raw; Latte auto-escapes the filter output. Detect
     * a string literal containing an HTML start- or end-tag piped through
     * `str_repeat` and append `|noescape`. Kept narrow on purpose: only
     * fires when the source literal contains an HTML tag, so plain-text
     * `'-'|str_repeat:5` is left alone.
     */
    private function addNoescapeToHtmlLiteralRepeats(string $source): string
    {
        return preg_replace_callback(
            "/\\{=((?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\\|str_repeat(?:[^|}]*)?(?:\\|[^|}]+)*?)\\}/",
            static function (array $m): string {
                $body = $m[1];
                if (str_contains($body, '|noescape')) {
                    return $m[0];
                }
                if (preg_match("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", $body, $litM) !== 1) {
                    return $m[0];
                }
                $htmlTagPattern = '#</?[a-zA-Z][a-zA-Z0-9]*(?:\\s[^>]*)?>#';
                if (preg_match($htmlTagPattern, $litM[0]) !== 1) {
                    return $m[0];
                }
                return '{=' . $body . '|noescape}';
            },
            $source
        ) ?? $source;
    }

    /**
     * Translation strings that embed HTML (`<a href="%s">...`,
     * `<em>...</em>`, `<strong>...`) must render raw — Smarty's
     * `escape_html=false` printed them raw; Latte auto-escapes the
     * filter return and produces visible markup-as-text. Detect
     * `{=...|translate...}` whose source string literal contains
     * an HTML start tag and append `|noescape`.
     */
    private function addNoescapeToHtmlBearingTranslations(string $source): string
    {
        return preg_replace_callback(
            "/\\{=((?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\\|translate(?:[^|}]*)?(?:\\|[^|}]+)*?)\\}/",
            static function (array $m): string {
                $body = $m[1];
                if (str_contains($body, '|noescape')) {
                    return $m[0];
                }
                $htmlTagPattern = '/<[a-zA-Z][a-zA-Z0-9]*(?:\\s[^>]*)?>/';
                // HTML in any quoted-string literal (template or arg).
                if (preg_match_all("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", $body, $allLits) > 0) {
                    foreach ($allLits[0] as $lit) {
                        if (preg_match($htmlTagPattern, $lit) === 1) {
                            return '{=' . $body . '|noescape}';
                        }
                    }
                }
                return $m[0];
            },
            $source
        ) ?? $source;
    }

    /**
     * `<script type="application/json">{$VAR}</script>` —
     *
     * Latte applies JS-context auto-escape inside `<script>` tags by
     * default; for `application/json` data blocks that turns valid
     * JSON quotes into `\"`-escaped JS literals and breaks
     * `JSON.parse(document.querySelector(...).textContent)`. The fix
     * is to bypass auto-escape on the JSON expression with `|noescape`.
     */
    private function addNoescapeToJsonScriptBlocks(string $source): string
    {
        return preg_replace_callback(
            '@(<script[^>]*type=[\'"]application/json[\'"][^>]*>)\{([^}]+)\}(</script>)@',
            static function (array $m): string {
                $body = trim($m[2]);
                if (str_contains($body, '|noescape')) {
                    return $m[0];
                }
                // `{$VAR}` — print of a single variable. Append filter.
                if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $body) === 1) {
                    return $m[1] . '{' . $body . '|noescape}' . $m[3];
                }
                // Generic expression — wrap with `=` print prefix.
                $expr = str_starts_with($body, '=') ? substr($body, 1) : $body;
                return $m[1] . '{=' . trim($expr) . '|noescape}' . $m[3];
            },
            $source
        ) ?? $source;
    }

    /**
     * Smarty's `{include}` propagates the parent template's local
     * variables (including foreach iterator vars) into the included
     * template; Latte's `{include}` only propagates the original
     * `render()` params, NOT the parent's local scope. Templates that
     * include a sub-template from inside a foreach and expect the
     * iterator vars to be visible break at runtime ("Undefined
     * variable" warnings).
     *
     * This pass walks the converted source, tracks the open `{foreach}`
     * scope stack, and appends the iterator vars as named args to any
     * `{include EXPR}` (or `{include EXPR, …}`) found inside one. The
     * iterator vars are taken from `{foreach $arr as $v}` (single-arg)
     * or `{foreach $arr as $k => $v}` (key+value).
     *
     * If the include already explicitly passes a var of the same name,
     * the explicit form wins (we don't override it).
     */
    private function passForeachLocalsToIncludes(string $source): string
    {
        // Tokenise on {foreach …}, {/foreach}, and {include …}; preserve
        // everything else as literal pass-through.
        $pattern = '/(\{foreach\s+\$[\w\[\]\->\']+\s+as\s+(?:\$(\w+)\s*=>\s*)?\$(\w+)\s*\})|(\{\/foreach\})|(\{include\s+(?:[^{}]|\{[^{}]*\})*\})/s';
        $stack = [];
        $result = preg_replace_callback(
            $pattern,
            function (array $m) use (&$stack): string {
                /** @var list<array{0: string, 1: string}> $stack */
                if ($m[1] !== '') {
                    // {foreach $arr as [$k =>] $v}
                    array_push($stack, [$m[2], $m[3]]); // [$k, $v] — $k may be ''
                    return $m[1];
                }
                if (($m[4] ?? '') !== '') {
                    array_pop($stack);
                    return $m[4];
                }
                if (($m[5] ?? '') === '') {
                    return $m[0];
                }
                $tag = $m[5];
                if ($stack === []) {
                    return $tag;
                }
                // Strip the wrapping `{include …}` so we can inspect the args.
                $inner = substr($tag, strlen('{include'), -1);
                $inner = ltrim($inner);
                // Split on the first top-level comma to separate the
                // template-name expression from existing named args.
                [$head, $existing] = $this->splitFirstTopLevelComma($inner);
                $existingVars = [];
                if ($existing !== '') {
                    if (preg_match_all('/(\w+)\s*:/', $existing, $em) === 1 && isset($em[1]) && is_array($em[1])) {
                        foreach ($em[1] as $name) {
                            if (is_string($name)) {
                                $existingVars[$name] = true;
                            }
                        }
                    }
                }
                $additions = [];
                /** @var list<array{0: string, 1: string}> $stack */
                foreach ($stack as [$k, $v]) {
                    if ($v !== '' && !isset($existingVars[$v])) {
                        $additions[] = "$v: \$$v";
                    }
                    if ($k !== '' && !isset($existingVars[$k])) {
                        $additions[] = "$k: \$$k";
                    }
                }
                if ($additions === []) {
                    return $tag;
                }
                $extra = implode(', ', $additions);
                if ($existing === '') {
                    return '{include ' . trim($head) . ', ' . $extra . '}';
                }
                return '{include ' . trim($head) . ', ' . $extra . ', ' . trim($existing) . '}';
            },
            $source,
        );
        return is_string($result) ? $result : $source;
    }

    /**
     * Split a string at the first top-level (non-paren-nested) comma.
     * Used to separate `{include EXPR, args…}` into `EXPR` and `args…`.
     *
     * @return array{0: string, 1: string} `[head, rest]` — `rest` is `''`
     *     if no top-level comma exists.
     */
    private function splitFirstTopLevelComma(string $s): array
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }
            if (!$inDouble && $c === "'") {
                $inSingle = !$inSingle;
                continue;
            }
            if (!$inSingle && $c === '"') {
                $inDouble = !$inDouble;
                continue;
            }
            if ($inSingle || $inDouble) {
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
                continue;
            }
            if ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                continue;
            }
            if ($depth === 0 && $c === ',') {
                return [substr($s, 0, $i), substr($s, $i + 1)];
            }
        }
        return [$s, ''];
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
                // `<expr> is odd` / `<expr> is even` → `(<expr>) % 2 != 0` / `== 0`.
                // The expr starts with `$` (variable) or a word char (function /
                // bareword) so the regex doesn't mis-anchor on a trailing `]`.
                $tag = preg_replace_callback(
                    '/((?:\$\w+|\w+\()[\w\[\]\->\.\(\)\'"]*)\s+is\s+(not\s+)?(odd|even)\b/',
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
                // Stop at a Latte tag-named-arg boundary like `, foo: $bar`
                // — the multi-arg pipe rewrite belongs only inside the
                // filter's own arg list, not the surrounding tag's named
                // args. Without this, an `{include 'x'|get_extent:'y',
                // navbar: $v}` would have its `navbar:` colon clobbered.
                $rest = '';
                if (preg_match('/^(.*?)(,\s+\w+\s*:.*)$/s', $args, $bm) === 1) {
                    $args = $bm[1];
                    $rest = $bm[2];
                }
                $rewritten = preg_replace_callback(
                    '/(\'(?:\\\\.|[^\'])*\'|"(?:\\\\.|[^"])*"|:)/',
                    static fn (array $mm): string => $mm[1] === ':' ? ',' : $mm[1],
                    $args,
                ) ?? $args;
                return '|' . $filter . ':' . $rewritten . $rest;
            },
            $source,
        ) ?? $source;
    }

    /**
     * `{$x|escape}` → `{$x}` (Latte auto-escapes), `{$x|escape:'none'}` →
     * `{$x|noescape}`. The `escape` filter is reserved by Latte (its
     * compiler throws if you try to register one), so it must be removed
     * or replaced.
     *
     * Smarty's escape:url maps to URL-percent-encoding, NOT Latte's HTML
     * auto-escape — Latte auto-escapes for HTML/JS/CSS contexts but does
     * NOT URL-encode automatically when emitting into URL contexts. So
     * `|escape:url` is rewritten to `|urlencode`, which is registered in
     * PiwigoExtension as the PHP `urlencode()` function.
     *
     * Other argumented forms (`|escape:'html'`, `|escape:htmlall`,
     * `|escape:'javascript'`) and the bare `|escape` are dropped, since
     * Latte's auto-escape covers those cases contextually.
     */
    private function rewriteEscapeFilters(string $source): string
    {
        // {$x|escape:'none'} → {$x|noescape}
        $source = preg_replace(
            "/\\|escape:['\"]none['\"]/",
            '|noescape',
            $source,
        ) ?? $source;
        // {$x|escape:'url'} or |escape:url → |urlencode (URL-percent-encoding,
        // not the same as HTML auto-escape).
        $source = preg_replace(
            "/\\|escape:['\"]?url['\"]?/",
            '|urlencode',
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
            '/\{combine_script\s+((?:[^{}]|\{[^{}]*\})+)\}/',
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
            '/\{combine_css\s+((?:[^{}]|\{[^{}]*\})+)\}/',
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
     * `{html_options name='x' options=$o selected=$s}` →
     *   `{=htmlOptions(name: 'x', options: $o, selected: $s)|noescape}`.
     *
     * Smarty's `html_options` plugin emits `<option>` (or wrapped
     * `<select>`) markup; the equivalent PiwigoExtension function
     * returns the same HTML and therefore needs `|noescape` to keep
     * Latte's auto-escape from double-encoding the angle brackets.
     */
    private function rewriteHtmlOptions(string $source): string
    {
        return preg_replace_callback(
            '/\{html_options\s+([^}]+)\}/',
            fn (array $m): string => '{=htmlOptions(' . $this->parseSmartyArgs($m[1]) . ')|noescape}',
            $source,
        ) ?? $source;
    }

    /**
     * `{html_radios name='x' options=$o selected=$s}` →
     *   `{=htmlRadios(name: 'x', options: $o, selected: $s)|noescape}`.
     */
    private function rewriteHtmlRadios(string $source): string
    {
        return preg_replace_callback(
            '/\{html_radios\s+([^}]+)\}/',
            fn (array $m): string => '{=htmlRadios(' . $this->parseSmartyArgs($m[1]) . ')|noescape}',
            $source,
        ) ?? $source;
    }

    /**
     * `{math equation="abs(x)" x=$v}` → `{=math(equation: "abs(x)", x: $v)}`.
     * The math result is a number, no escape concern; bare `{=…}` keeps
     * it consistent with the other Smarty function ports.
     */
    private function rewriteMath(string $source): string
    {
        return preg_replace_callback(
            '/\{math\s+([^}]+)\}/',
            fn (array $m): string => '{=math(' . $this->parseSmartyArgs($m[1]) . ')}',
            $source,
        ) ?? $source;
    }

    /**
     * `{counter [start=N] [assign=X] [...]}` → drop. Smarty's `{counter}`
     * was a stateful row-numbering helper; Piwigo only used it to assign
     * an unread `$i` variable, so the converter strips it entirely.
     * If a future template starts reading the counter value the rule
     * would need to track per-name state.
     */
    private function rewriteCounter(string $source): string
    {
        return preg_replace(
            '/\{counter(?:\s+[^}]*)?\}\s*(?:\{\*[^*]*\*\})?\s*\n?/',
            '',
            $source,
        ) ?? $source;
    }

    /**
     * Calls to user-defined functions become `{include funcName, k: v, …}`.
     * Looks up `{define X}…{/define}` blocks already produced by
     * `rewriteFunctionDefinition` above and rewrites every other
     * `{X args…}` occurrence to `{include X[, k: v[, …]]}`. Latte's
     * `{include}` for `{define}` blocks expects PHP-style named args
     * (comma-separated, `:` between key and value).
     */
    private function rewriteUserDefinedFunctionCall(string $source): string
    {
        if (preg_match_all('/\{define\s+(\w+)\b/', $source, $defs) === false) {
            return $source;
        }
        $names = array_unique($defs[1]);
        if ($names === []) {
            return $source;
        }
        foreach ($names as $name) {
            // The call site can wrap onto multiple lines, so we anchor on
            // `{name<ws>` and let the body run until the matching `}`.
            $pattern = '/\{(?!define\b|include\b|call\b|\/)' . preg_quote($name, '/') . '(\s+[^}]*?)?\}/s';
            $source = preg_replace_callback(
                $pattern,
                function (array $m) use ($name): string {
                    $rawArgs = isset($m[1]) ? trim($m[1]) : '';
                    if ($rawArgs === '') {
                        return '{include ' . $name . '}';
                    }
                    $args = $this->parseSmartyArgs($rawArgs);
                    if ($args === '') {
                        // Couldn't recognise the args — surface as residue.
                        return $m[0];
                    }
                    return '{include ' . $name . ', ' . $args . '}';
                },
                $source,
            ) ?? $source;
        }
        return $source;
    }

    /**
     * Smarty's backtick-in-string variable interpolation:
     *   `"prefix`$expr`suffix"` → `"prefix" . $expr . "suffix"`.
     * Only runs inside `{…}` tag bodies so we don't disturb literal
     * backtick characters in HTML text (e.g. inside `<code>` blocks).
     * Uses PHP's `.` concat operator — Latte accepts `~` too in some
     * contexts but rejects it inside function-call argument positions
     * (where the converter primarily lands these).
     */
    private function rewriteBacktickInterpolation(string $source): string
    {
        return preg_replace_callback(
            '/\{[^{}]*\}/',
            static function (array $m): string {
                $tag = $m[0];
                if (!str_contains($tag, '`')) {
                    return $tag;
                }
                return preg_replace_callback(
                    '/(["\'])([^"\'`]*)`([^`]+)`([^"\'`]*)\1/',
                    static function (array $sm): string {
                        $q = $sm[1];
                        $parts = [];
                        if ($sm[2] !== '') {
                            $parts[] = $q . $sm[2] . $q;
                        }
                        $parts[] = $sm[3];
                        if ($sm[4] !== '') {
                            $parts[] = $q . $sm[4] . $q;
                        }
                        return implode(' . ', $parts);
                    },
                    $tag,
                ) ?? $tag;
            },
            $source,
        ) ?? $source;
    }

    /**
     * Smarty 5 introduced `$item@index/@iteration/@first/@last/@total/@key`
     * as the per-element iterator-attribute syntax (alongside the older
     * `$smarty.foreach.NAME.x` form). Latte exposes the same data via
     * the implicit `$iterator` object, identical to `rewriteSmarty­Foreach­Iterator`.
     */
    private function rewriteIteratorAttribute(string $source): string
    {
        $map = [
            'index' => '($iterator->getCounter() - 1)',
            'iteration' => '$iterator->getCounter()',
            'first' => '$iterator->isFirst()',
            'last' => '$iterator->isLast()',
            'total' => '$iterator->getTotalCount()',
            'key' => '$iterator->key',
        ];
        foreach ($map as $key => $replacement) {
            $source = preg_replace(
                '/\$\w+@' . $key . '\b/',
                $replacement,
                $source,
            ) ?? $source;
        }
        return $source;
    }

    /**
     * Latte rejects bare `{break}` inside `{foreach}`; the idiomatic
     * shape is `{breakIf <expr>}`. Smarty's `{if X}{break}{/if}` block
     * collapses cleanly to a single `{breakIf X}` tag — same for the
     * `{continue}` counterpart, even though it isn't currently used.
     */
    private function rewriteIfBreakIdiom(string $source): string
    {
        $source = preg_replace(
            '/\{if\s+([^{}]+?)\}\s*\{break\}\s*\{\/if\}/',
            '{breakIf $1}',
            $source,
        ) ?? $source;
        return preg_replace(
            '/\{if\s+([^{}]+?)\}\s*\{continue\}\s*\{\/if\}/',
            '{continueIf $1}',
            $source,
        ) ?? $source;
    }

    /**
     * Smarty allowed `{if $x|filter < N}`; Latte rejects pipes inside
     * `{if}`. Rewrite the pipe to a function call by walking the tag
     * body and converting each `$expr|filter` (no args) into `filter($expr)`.
     * Multi-arg pipes are rare inside `{if}` and left as residue.
     */
    private function rewritePipeFilterInIf(string $source): string
    {
        return preg_replace_callback(
            '/\{(if|elseif)\s+([^{}]*?)\}/s',
            static function (array $m): string {
                $body = $m[2];
                $previous = '';
                while ($previous !== $body) {
                    $previous = $body;
                    $body = preg_replace(
                        '/(\$\w+(?:\[[^\]]+\]|\->\w+|\[\'\w+\'\])*)\|(\w+)(?![\w:])/',
                        '$2($1)',
                        $body,
                    ) ?? $body;
                }
                return '{' . $m[1] . ' ' . $body . '}';
            },
            $source,
        ) ?? $source;
    }

    /**
     * Strip nested `{$X}` print sub-tags out of control-tag bodies:
     *   `{if {$X} eq 1}` → `{if $X eq 1}`
     *   `{if "first" == {$POS_PREF}}` → `{if "first" == $POS_PREF}`
     *
     * Smarty 5 tolerates the embedded print expression as syntactic
     * sugar for a bare variable reference; Latte rejects it because
     * `{` opens a new tag. The rewrite peels one nested `{$X}` at a
     * time inside `{if|elseif|while|var}` openers (the unbalanced
     * brace prevents a single greedy regex from spanning the body
     * in one shot).
     */
    private function rewriteEmbeddedPrintInTag(string $source): string
    {
        $previous = '';
        while ($previous !== $source) {
            $previous = $source;
            $source = preg_replace(
                '/(\{(?:if|elseif|while|var)\b[^{}]*)\{(\$\w+(?:\.\w+|\[[^\]]+\])*)\}/',
                '$1$2',
                $source,
            ) ?? $source;
        }
        return $source;
    }

    /**
     * Strip `{…}` wrappers from expressions used inside filter args:
     *   `{"%s"|translate:{round($x, 2)}}` → `{"%s"|translate:round($x, 2)}`
     *
     * Smarty parsed the inner `{round(...)}` as a sub-print and inlined
     * its value into the filter argument; Latte's tag parser sees the
     * unmatched `{` instead. This pass walks each `{…}` tag and unwraps
     * `{round(...)}` / `{func(...)}` only when it follows a `:` argument
     * separator, so we don't disturb literal braces in HTML text.
     */
    private function rewriteFilterArgBraces(string $source): string
    {
        // Operate globally — the wrapping tag has unbalanced braces from
        // the perspective of `[^{}]*`, so peel one nested `:{…}` at a
        // time until the source stabilises.
        $previous = '';
        while ($previous !== $source) {
            $previous = $source;
            $source = preg_replace(
                '/(\|\w[\w]*(?::[^{}|]*)?:)\{([^{}]+)\}/',
                '$1$2',
                $source,
            ) ?? $source;
        }
        return $source;
    }

    /**
     * `{include file='foo.tpl' [k=v] [...]}` → `{include 'foo.latte'[, k: v]}`.
     * Renames `.tpl` to `.latte` in path literals.
     *
     * The template-name expression is rewritten to function-call shape
     * `getExtent(EXPR, ARG)` when the original used the
     * `EXPR|get_extent:ARG` pipe filter — Latte's parser conflates
     * trailing `, name: $value` named args with extra arguments to the
     * filter (the `parser swallows named args into the filter` regression
     * we hit in §1.2 Wave 2 Phase F.0). Function-call shape is
     * paren-bounded, so trailing tag-named-args stay outside.
     */
    private function rewriteIncludePath(string $source): string
    {
        // Body allows nested `{…}` once because Smarty include args of
        // shape `title={'X'|translate}` carry an embedded print expression.
        return preg_replace_callback(
            '/\{include\s+file=([^\s}]+)((?:[^{}]|\{[^{}]*\})*)\}/s',
            function (array $m): string {
                $path = preg_replace('/\.tpl([\'"])/', '.latte$1', $m[1]) ?? $m[1];
                $path = $this->rewriteGetExtentFilterToCall($path);
                $rest = trim($m[2]);
                if ($rest === '') {
                    return "{include $path}";
                }
                $extras = $this->parseSmartyArgs($rest);
                if ($extras === '') {
                    return "{include $path}";
                }
                return "{include $path, $extras}";
            },
            $source,
        ) ?? $source;
    }

    /**
     * `EXPR|get_extent:ARG` → `getExtent(EXPR, ARG)`. ARG is captured
     * by `[^|}]+?` to allow embedded function calls or quoted strings;
     * EXPR is the chunk that precedes the pipe.
     */
    private function rewriteGetExtentFilterToCall(string $expr): string
    {
        return preg_replace_callback(
            '/^(.+?)\|get_extent:([^|}]+)$/s',
            static function (array $m): string {
                return 'getExtent(' . trim($m[1]) . ', ' . trim($m[2]) . ')';
            },
            $expr,
        ) ?? $expr;
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
            // Raw dotted form (in case dot-access didn't run yet — tests
            // call convert() in isolation).
            $source = preg_replace(
                '/\$smarty\.foreach\.\w+\.' . $key . '\b/',
                $replacement,
                $source,
            ) ?? $source;
            // Bracketed form left behind by `rewriteSmartyDotAccess`,
            // which runs before this rule in the convert() pipeline.
            $source = preg_replace(
                "/\\\$smarty\\['foreach'\\]\\['\\w+'\\]\\['" . $key . "'\\]/",
                $replacement,
                $source,
            ) ?? $source;
        }

        // Other documented residues that the dot-access pass leaves as
        // `$smarty['X']['Y']` — Latte has no implicit `$smarty` global.
        // - `$smarty.now` → `time()`. Used by `|date_format` to render
        //   "today" links; the filter itself accepts an int timestamp.
        $source = str_replace("\$smarty['now']", 'time()', $source);
        // - `$smarty.server.X` → `$_SERVER['X'] ?? ''`. Smarty's
        //   `$smarty.server` exposes the superglobal verbatim; in Latte
        //   we route through PHP's superglobal directly.
        $source = preg_replace(
            "/\\\$smarty\\['server'\\]\\['(\\w+)'\\]/",
            "(\$_SERVER['$1'] ?? '')",
            $source,
        ) ?? $source;
        // - `$smarty.cookies.X` → `$_COOKIE['X']`. The `?? null` shape
        //   would seem safer for missing-cookie reads, but Latte rejects
        //   `isset(($x ?? null))` — and the affected templates lean on
        //   `isset($smarty.cookies.X)` to guard reads. Bare `$_COOKIE['X']`
        //   keeps the isset() callers working; the templates that read
        //   without a guard would have raised the same notice under
        //   Smarty (it returns null for missing keys).
        $source = preg_replace(
            "/\\\$smarty\\['cookies'\\]\\['(\\w+)'\\]/",
            "\$_COOKIE['$1']",
            $source,
        ) ?? $source;
        // - `$smarty.capture.NAME` → `$NAME`. Latte's `{capture $NAME}`
        //   binds the result to a regular template-var, no `$smarty`
        //   indirection needed.
        $source = preg_replace(
            "/\\\$smarty\\['capture'\\]\\['(\\w+)'\\]/",
            '\$$1',
            $source,
        ) ?? $source;

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
                    // `$arr.$key` → `$arr[$key]` (variable index).
                    // Leading expr can chain `[...]` indices and `->prop`
                    // PHP-style accesses before the dotted suffix.
                    $tag = preg_replace(
                        '/(\$\w+(?:\[[^\]]+\]|->\w+)*)\.(\$\w+)/',
                        '$1[$2]',
                        $tag,
                    ) ?? $tag;
                    // `$arr.foo` → `$arr['foo']` (literal-key dot access).
                    $tag = preg_replace(
                        '/(\$\w+(?:\[[^\]]+\]|->\w+)*)\.(\w+)/',
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
            "/\\{(?!=)((?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|\\w+\\([^()]*\\)|\\([^()]*\\))\\|\\w[^}]*)\\}/",
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

        // Smarty 3+ shorthand: {$foo = value} or {$foo='value'}.
        // Latte interprets {$expr} as a print, so a literal `{$foo='bar'}`
        // emits 'bar'. Disambiguate from comparisons (==, !=, <=, >=) and
        // attribute fragments by requiring a single `=` immediately after
        // the variable name.
        $source = preg_replace_callback(
            '/\{\$(\w+)\s*=(?![=])\s*([^}]+?)\}/',
            static fn (array $m): string => $wrap($m[1], $m[2]),
            $source,
        ) ?? $source;

        return $source;
    }

    /**
     * `{section name=NAME loop=$ARR}…{/section}` →
     *   `{foreach $ARR as $NAME => $val}…{/foreach}`.
     *
     * Numeric-range form `{section name=NAME start=N loop=M}` →
     *   `{foreach range(N, N + M - 1) as $NAME}…{/foreach}`. In Smarty,
     *   `start=N loop=M` produces M iterations with index N, N+1, …,
     *   N+M-1. `range()` matches that 1:1. The body's
     *   `$smarty.section.NAME.index` → `$NAME` rewrite is handled by the
     *   block below.
     *
     * Smarty's `{section}` also exposes `iteration/total/first/last/...`
     * via `$smarty.section.NAME.X`; only `.index` has a clean Latte
     * equivalent in the numeric-range form (the loop var IS the index).
     * The other counters land in the residue check.
     */
    private function rewriteSection(string $source): string
    {
        // Array iteration form: `loop=$arr`.
        $source = preg_replace_callback(
            '/\{section\s+name=(\w+)\s+loop=(\$[\w\[\]\->]+)\s*\}/',
            static fn (array $m): string => sprintf('{foreach %s as $%s => $val}', $m[2], $m[1]),
            $source,
        ) ?? $source;
        // Numeric-range form: `start=N loop=M` (or `loop=M start=N`).
        $rangeCb = static function (array $m): string {
            $name = $m['name'];
            $start = (int) $m['start'];
            $count = (int) $m['count'];
            return sprintf('{foreach range(%d, %d) as $%s}', $start, $start + $count - 1, $name);
        };
        $source = preg_replace_callback(
            '/\{section\s+name=(?<name>\w+)\s+start=(?<start>\d+)\s+loop=(?<count>\d+)\s*\}/',
            $rangeCb,
            $source,
        ) ?? $source;
        $source = preg_replace_callback(
            '/\{section\s+name=(?<name>\w+)\s+loop=(?<count>\d+)\s+start=(?<start>\d+)\s*\}/',
            $rangeCb,
            $source,
        ) ?? $source;
        // Body: `$smarty.section.NAME.index` (or its bracketed form left
        // by rewriteSmartyDotAccess) → `$NAME`. Only valid for the
        // numeric-range form, but it's also a no-op when no matching
        // `{section}` exists, so the rewrite is safe to apply blindly.
        $source = preg_replace(
            '/\$smarty\.section\.(\w+)\.index\b/',
            '\$\1',
            $source,
        ) ?? $source;
        $source = preg_replace(
            "/\\\$smarty\\['section'\\]\\['(\\w+)'\\]\\['index'\\]/",
            '\$\1',
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
        // Smarty wrote both `name=` and `assign=` to bind the captured
        // body to a template variable; Latte exposes a single capture
        // target via `{capture $var}…{/capture}`.
        $source = preg_replace_callback(
            '/\{capture\s+(?:name|assign)=[\'"]?(\w+)[\'"]?\s*\}/',
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
        // Tokenise into `key = value` pairs. The value spans until the
        // next `<ident>=` lookahead (or end of input), allowing values
        // to contain whitespace, parens, pipes, colons and commas — all
        // of which appear in real Piwigo template args.
        $pattern = '/(\w+)\s*=\s*((?:\'[^\']*\'|"[^"]*"|\([^)]*\)|[^\s])+?)(?=\s+\w+\s*=|\s*$)/s';
        if (preg_match_all($pattern, $rawArgs, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $args[$match[1]] = $this->normalizeArgValue($match[2]);
            }
        }
        return $args;
    }

    /**
     * Strip Smarty's `{...}` print wrap from arg values: a Smarty author
     * could write `title={'Title'|translate}` to "embed the rendered
     * expression as the arg"; in Latte the same arg accepts the bare
     * expression directly. The mechanical converter's print-literal pass
     * promotes the inner pipe to `{=...|filter}` first, so here we peel
     * either form off the value before quoting.
     */
    private function normalizeArgValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        if (preg_match('/^\{=(.+)\}$/s', $value, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/^\{(.+)\}$/s', $value, $m) === 1
            && (str_contains($m[1], '|') || str_contains($m[1], '$'))
        ) {
            return trim($m[1]);
        }
        // Smarty allows bareword args (e.g. `name=theme` is a string
        // `'theme'`); Latte's named-arg syntax requires real PHP
        // expressions, so an unquoted identifier resolves to a constant
        // or undefined variable. Re-quote it.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1
            && !in_array(strtolower($value), ['true', 'false', 'null'], true)
        ) {
            return "'$value'";
        }
        return $value;
    }
}
