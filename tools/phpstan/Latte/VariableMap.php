<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * VariableMapBuilder's output: the per-template and fallback variable
 * type maps LatteTemplateCompiler injects as `@var` docblocks.
 */
final readonly class VariableMap
{
    /**
     * @param array<string, array<string, string>> $byTemplate template realpath => {var => type}
     * @param array<string, string> $fallback variables from contexts assigned in
     *   classes that render nothing themselves -- applied to every template
     * @param list<string> $fallbackContexts the context FQCNs that fell back,
     *   for the compile Command's visibility log
     */
    public function __construct(
        public array $byTemplate,
        public array $fallback,
        public array $fallbackContexts,
    ) {}

    /**
     * @param array<string, string> $globals always-available variables from
     *   outside the context system (framework globals, themeconf vars,
     *   assignVarFromTemplate outputs) -- the caller merges those sources
     * @return array<string, string> the full variable set for one template:
     *   its own call-site-associated variables + the global fallback union
     *   + $globals, later sources never overriding earlier more-specific ones
     */
    public function forTemplate(string $realPath, array $globals): array
    {
        $vars = $this->byTemplate[$realPath] ?? [];
        foreach ([$this->fallback, $globals] as $source) {
            foreach ($source as $name => $type) {
                if (! isset($vars[$name])) {
                    $vars[$name] = $type;
                }
            }
        }
        ksort($vars);

        return $vars;
    }
}
