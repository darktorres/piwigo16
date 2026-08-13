<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use Latte\Engine;
use LogicException;
use Piwigo\Tools\PhpStan\Latte\Generated\LatteAnalysisShims;

/**
 * Compiles one real `.latte` template with the real `Latte\Engine`
 * (nothing re-derived) and applies exactly two mechanical transforms to
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
 * Both transforms are textual, deliberately not parse-and-reprint:
 * every generated line and its `/* pos L:C *``/` comment stays
 * byte-identical to Latte's own output (injection only inserts lines),
 * so the error formatter's pos-comment line mapping stays exact.
 *
 * Output is deterministic and write-only-if-changed so PHPStan's result
 * cache stays warm across runs -- the exact trap efabrica's own #426
 * performance discussion describes.
 */
final class LatteTemplateCompiler
{
    private const SHIMS = '\\Piwigo\\Tools\\PhpStan\\Latte\\Generated\\LatteAnalysisShims';

    /**
     * @param list<string> $templateAwareFunctions function names whose real
     *   callable takes the Latte template as its first parameter -- the
     *   `$this` argument stays for these
     */
    public function __construct(
        private readonly Engine $engine,
        private readonly string $root,
        private readonly string $outputDir,
        private readonly array $templateAwareFunctions = LatteAnalysisShims::TEMPLATE_AWARE,
    ) {}

    /**
     * @param array<string, string> $vars template variable name => PHPStan type
     */
    public function compile(string $templateRealPath, array $vars): CompiledTemplate
    {
        $code = $this->engine->compile($templateRealPath);

        $code = $this->rewriteInvocations($code, $templateRealPath);
        [$code, $notices] = $this->injectVarDocblocks($code, $vars, $templateRealPath);

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
            if (preg_match('/^(\s*)extract\(/', $line, $m) === 1) {
                foreach ($docLines as $docLine) {
                    $out[] = $m[1] . $docLine;
                }
            }
        }

        return [implode("\n", $out), $notices];
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
