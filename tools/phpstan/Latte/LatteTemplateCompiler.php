<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use Latte\Engine;
use LogicException;
use Piwigo\Tools\PhpStan\Latte\Generated\LatteAnalysisShims;

/**
 * Compiles one real `.latte` template with the real `Latte\Engine`
 * (nothing re-derived) and applies exactly three mechanical transforms to
 * the generated PHP before writing it to the analysis directory:
 *
 * 1. Filter/function property-invokes -> static calls on the generated
 *    `LatteAnalysisShims` class. Latte compiles every filter/function
 *    call as a closure-invoked property fetch
 *    (`($this->filters->translate)(...)`,
 *    `($this->global->fn->combineScript)($this, id: ...)`) -- a shape
 *    PHPStan can only type as bare `callable` (FilterExecutor::__get),
 *    losing all parameter/return information. Rewriting to the shims'
 *    real reflected signatures restores full checking, including named
 *    arguments against real default values. The leading `$this` Latte
 *    passes to functions is stripped for non-Template-aware callables,
 *    mirroring Latte's own runtime `FunctionExecutor::__get()` wrapping.
 *    Confirmed complete against all 135 production-compiled templates
 *    (only these two shapes exist; zero `filterContent` block-filter
 *    calls) -- and enforced: any surviving
 *    `$this->filters->`/`$this->global->fn` access hard-fails, so a
 *    future new calling convention surfaces instead of silently
 *    analysing as `callable`.
 *
 * 2. `/** @var Type $name *``/` docblocks after **every** `extract()`
 *    anchor -- `main()`, `prepare()`, and each compiled `{block}` method
 *    (up to 4 anchors in real templates). Live-probed: consecutive
 *    inline `@var` comments narrow extract()ed locals at level 10 under
 *    this repo's real phpstan.neon, no Nop separators needed.
 *
 * 3. `/** @var Html|string|false $name *``/` docblocks after every
 *    `{capture}` target assignment (`$name = $ʟ_tmp;`, always immediately
 *    preceded -- possibly several lines earlier, across the `try/finally`
 *    that flushes the output buffer -- by
 *    `$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();`,
 *    the one shape Latte's CaptureNode compiles to; confirmed against all
 *    4 real `{capture}` sites in this codebase). Every compiled `main()`/
 *    `prepare()`/`{block}` method starts with `extract()`, which makes
 *    PHPStan treat *every* local variable in that scope as possibly
 *    clobbered to `mixed` for the rest of the method (extract() can
 *    define any name) -- confirmed by direct repro: a capture target
 *    reassigned unconditionally right before use still typed `mixed` at
 *    that use, purely because an earlier `extract()` shares the scope.
 *    Transform #2 already solves this for real template parameters via
 *    the VariableMap; this solves it for capture-only locals, which
 *    aren't in that map, from Latte's own generated code shape instead
 *    of a guessed or hand-asserted type.
 *
 * 4. `/** @var Type $name *``/` docblocks after each parameter a
 *    `{define}` declares. Latte parses those types and discards them --
 *    `TemplateGenerator::buildParams()` emits
 *    `$x = $ʟ_args[0] ?? $ʟ_args['x'] ?? null;` and never reads
 *    `ParameterNode::$type` -- so without this a declared block
 *    parameter is `mixed` however it was written, and `{define}`'s type
 *    syntax is decorative. The declaration survives verbatim in the
 *    `/** {define ...} on line N *``/` comment Latte emits above the
 *    block method; the names read out of it must line up by index with
 *    the assignments underneath, which is what keeps this honest.
 *
 * All transforms are textual, deliberately not parse-and-reprint:
 * every generated line and its `/* pos L:C *``/` comment stays
 * byte-identical to Latte's own output (injection only inserts lines),
 * so the error formatter's pos-comment line mapping stays exact.
 *
 * Output is deterministic and write-only-if-changed so PHPStan's result
 * cache stays warm across runs -- the exact trap efabrica's own #426
 * performance discussion describes.
 */
final readonly class LatteTemplateCompiler
{
    // Leading backslash required: this constant is spliced directly into
    // generated PHP *source text* below (self::SHIMS . '::...(' ), not
    // just referenced within this file's own execution context --
    // ::class alone never carries one, which would leave the emitted
    // call namespace-relative instead of absolute. Concatenation of two
    // constant-expressions (a legal const initializer -- confirmed via
    // ReflectionClass::getConstant()), not a bare string literal, so
    // Rector's StringClassNameToClassConstantRector has nothing to
    // rewrite here (empirically verified).
    private const string SHIMS = '\\' . LatteAnalysisShims::class;

    /**
     * @param list<string> $templateAwareFunctions function names whose real
     *   callable takes the Latte template as its first parameter -- the
     *   `$this` argument stays for these
     */
    public function __construct(
        private Engine $engine,
        private string $root,
        private string $outputDir,
        private array $templateAwareFunctions = LatteAnalysisShims::TEMPLATE_AWARE,
    ) {}

    /**
     * @param array<string, string> $vars template variable name => PHPStan type
     */
    public function compile(string $templateRealPath, array $vars): CompiledTemplate
    {
        $code = $this->engine->compile($templateRealPath);

        $code = $this->rewriteInvocations($code, $templateRealPath);
        [$code, $notices] = $this->injectVarDocblocks($code, $vars, $templateRealPath);
        $code = $this->injectCaptureVarDocblocks($code);
        $code = $this->injectBlockParamDocblocks($code, $templateRealPath);

        $outputPath = $this->outputDir . '/' . $this->outputBasename($templateRealPath);
        $changed = ! is_file($outputPath) || file_get_contents($outputPath) !== $code;
        if ($changed) {
            if (! is_dir($this->outputDir) && ! mkdir($this->outputDir, 0o775, true) && ! is_dir($this->outputDir)) {
                throw new LogicException("Cannot create analysis directory: {$this->outputDir}");
            }
            if (file_put_contents($outputPath, $code) === false) {
                throw new LogicException("Cannot write: {$outputPath}");
            }
        }

        return new CompiledTemplate($outputPath, $changed, $notices);
    }

    private function rewriteInvocations(string $code, string $templateRealPath): string
    {
        // Known textual-transform limitation: a template whose *text
        // content* contains a verbatim `($this->filters->x)(` sequence
        // would have it rewritten inside the echo'd string literal. Zero
        // real occurrences across the 135-template tree (every match is a
        // genuine compiled call), and the output is analysis-only -- noted
        // rather than solved because the alternative (AST parse-and-
        // reprint) would destroy the byte-exact pos-comment fidelity the
        // error mapper depends on.
        $code = preg_replace(
            '/\(\$this->filters->([a-zA-Z_][a-zA-Z0-9_]*)\)\(/',
            self::SHIMS . '::$1(',
            $code,
        ) ?? $code;

        $code = preg_replace_callback(
            '/\(\$this->global->fn->([a-zA-Z_][a-zA-Z0-9_]*)\)\(\$this(\)|, )/',
            function (array $m): string {
                $call = self::SHIMS . '::' . $m[1] . '(';
                if (in_array($m[1], $this->templateAwareFunctions, true)) {
                    return $call . '$this' . $m[2];
                }

                return $m[2] === ')' ? $call . ')' : $call;
            },
            $code,
        ) ?? $code;

        foreach (['$this->filters->', '$this->global->fn'] as $residual) {
            if (str_contains($code, $residual)) {
                $lines = [];
                foreach (explode("\n", $code) as $i => $line) {
                    if (str_contains($line, $residual)) {
                        $lines[] = ($i + 1) . ': ' . trim($line);
                    }
                }
                throw new LogicException(
                    "Unrewritten `{$residual}` access in the compiled output of {$templateRealPath} -- "
                    . 'a calling convention this rewrite does not cover (new Latte version? block-level '
                    . 'filter?). Extend LatteTemplateCompiler::rewriteInvocations() rather than letting '
                    . "it analyse as bare `callable`:\n" . implode("\n", $lines),
                );
            }
        }

        return $code;
    }

    /**
     * @param array<string, string> $vars
     * @return array{string, list<string>}
     */
    private function injectVarDocblocks(string $code, array $vars, string $templateRealPath): array
    {
        $notices = [];
        $docLines = [];
        foreach ($vars as $name => $type) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
                $notices[] = "variable name '{$name}' in {$templateRealPath} is not a valid PHP identifier -- skipped (extract() skips it at runtime too)";
                continue;
            }
            $docLines[$name] = "/** @var {$type} \${$name} */";
        }

        if ($docLines === []) {
            return [$code, $notices];
        }

        $out = [];
        foreach (explode("\n", $code) as $line) {
            $out[] = $line;
            // Exactly the two anchor forms Latte's compiler emits
            // (verified against all 135 real compiled templates: 131x
            // $ʟ_args + 99x $this->params, nothing else) -- an exact match
            // both keeps a template that *echoes* text resembling an
            // extract() call from corrupting the injection and surfaces a
            // future new Latte codegen shape as a notice instead of
            // silently skipping its variables.
            if (preg_match('/^(\s*)extract\(\$(?:ʟ_args|this->params)\);$/u', $line, $m) === 1) {
                foreach ($docLines as $docLine) {
                    $out[] = $m[1] . $docLine;
                }
            } elseif (preg_match('/^\s*extract\(/', $line) === 1) {
                $notices[] = "unrecognized extract() shape in compiled {$templateRealPath}: '" . trim($line) . "' -- variables not injected at this anchor";
            }
        }

        return [implode("\n", $out), $notices];
    }

    /**
     * See class docblock transform #3: narrows every `{capture}` target
     * (`$name = $ʟ_tmp;`) right after its assignment, from the type
     * Latte's own CaptureNode ternary unambiguously produces.
     */
    private function injectCaptureVarDocblocks(string $code): string
    {
        $out = [];
        $sawCaptureTernary = false;
        foreach (explode("\n", $code) as $line) {
            $out[] = $line;
            if (preg_match('/\$ʟ_tmp = ob_get_length\(\) \? new LR\\\\Html\(ob_get_clean\(\)\) : ob_get_clean\(\);$/', $line) === 1) {
                $sawCaptureTernary = true;
                continue;
            }
            if ($sawCaptureTernary && preg_match('/^(\s*)\$([a-zA-Z_][a-zA-Z0-9_]*) = \$ʟ_tmp;$/u', $line, $m) === 1) {
                $out[] = $m[1] . "/** @var \\Latte\\Runtime\\Html|string|false \${$m[2]} */";
                $sawCaptureTernary = false;
            }
        }

        return implode("\n", $out);
    }

    /**
     * See class docblock transform #4: types every parameter a `{define}`
     * declares.
     *
     * Latte parses those types and then throws them away --
     * `TemplateGenerator::buildParams()` emits
     * `$x = $ʟ_args[0] ?? $ʟ_args['x'] ?? null;` and never reads
     * `ParameterNode::$type` -- so a declared block parameter reaches
     * PHPStan as `mixed` however carefully it was written. The
     * declaration does survive verbatim in the
     * `/** {define ...} on line N *``/` comment Latte emits above the
     * block method, which is what this reads.
     *
     * Two independent halves of Latte's own output cross-check each
     * other: the names parsed out of that comment must line up
     * one-for-one, by index, with the generated assignments underneath
     * it. A mismatch hard-fails rather than typing the wrong variable.
     */
    private function injectBlockParamDocblocks(string $code, string $templateRealPath): string
    {
        $out = [];
        /** @var list<array{type: ?string, name: string}> $params */
        $params = [];
        foreach (explode("\n", $code) as $line) {
            if (preg_match('/^\s*\/\*\* \{(?:define|block) (.*)\} on line \d+ \*\/$/u', $line, $m) === 1) {
                $params = $this->parseBlockParameters($m[1], $templateRealPath);
                $out[] = $line;
                continue;
            }

            // A block with no declared parameters compiles to
            // `extract($ʟ_args);` instead, so indexed assignments only
            // ever appear under a `{define}` that declared some.
            if ($params !== []
                && preg_match('/^(\s*)\$([a-zA-Z_][a-zA-Z0-9_]*) = \$ʟ_args\[(\d+)\] \?\? /u', $line, $m) === 1) {
                $index = (int) $m[3];
                $declared = $params[$index] ?? null;
                if ($declared === null || $declared['name'] !== $m[2]) {
                    throw new LogicException(
                        "Block parameter mismatch in the compiled output of {$templateRealPath}: Latte generated "
                        . "`\${$m[2]}` at index {$index}, but the {define} tag above it declares "
                        . ($declared === null ? 'no parameter there' : "`\${$declared['name']}`")
                        . '. Extend LatteTemplateCompiler::parseBlockParameters() rather than letting it type '
                        . 'the wrong variable.',
                    );
                }

                $out[] = $line;
                if ($declared['type'] !== null) {
                    $out[] = $m[1] . "/** @var {$declared['type']} \${$m[2]} */";
                }

                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * `name, string $a, int|string $b = 0` -> the two declarations, with
     * the block's own name and any defaults dropped. A parameter written
     * without a type yields `type: null` and is left as Latte typed it.
     *
     * @return list<array{type: ?string, name: string}>
     */
    private function parseBlockParameters(string $tag, string $templateRealPath): array
    {
        $comma = strpos($tag, ',');
        if ($comma === false) {
            return [];
        }

        $params = [];
        foreach ($this->splitTopLevel(substr($tag, $comma + 1)) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }
            if (preg_match('/^(?:(.+?)\s+)?\$([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:=.*)?$/su', $declaration, $m) !== 1) {
                throw new LogicException(
                    "Cannot read block parameter `{$declaration}` in {$templateRealPath}. "
                    . 'Extend LatteTemplateCompiler::parseBlockParameters().',
                );
            }
            $type = trim($m[1]);
            $params[] = [
                'type' => $type === '' ? null : $type,
                'name' => $m[2],
            ];
        }

        return $params;
    }

    /**
     * Splits on the commas that separate parameters, not the ones inside
     * a type (`array{a: int, b: int}`) or a string default.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $list): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($list);
        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $list[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif (in_array($char, ['<', '[', '{', '('], true)) {
                $depth++;
            } elseif (in_array($char, ['>', ']', '}', ')'], true)) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * Deterministic, readable, collision-free: the template's own
     * root-relative path with separators flattened.
     * (Class-name collisions are impossible separately -- Latte derives
     * the generated class name from the template path.)
     */
    private function outputBasename(string $templateRealPath): string
    {
        $relative = str_starts_with($templateRealPath, $this->root . '/')
            ? substr($templateRealPath, strlen($this->root) + 1)
            : ltrim(str_replace(':', '', $templateRealPath), '/');

        return str_replace('/', '-', $relative) . '.php';
    }
}
