<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * VariableMapBuilder's output: the per-template and fallback variable
 * type maps LatteTemplateCompiler injects as `@var` docblocks.
 */
final class VariableMap
{
    /**
     * @param array<string, array<string, string>> $byTemplate template realpath => {var => type}
     * @param array<string, string> $fallback variables from contexts assigned in
     *   classes that render nothing themselves -- applied to every template
     * @param list<string> $fallbackContexts the context FQCNs that fell back,
     *   for the compile Command's visibility log
     */
    public function __construct(
        public readonly array $byTemplate,
        public readonly array $fallback,
        public readonly array $fallbackContexts,
    ) {}

    /**
     * @return array<string, string> the full variable set for one template:
     *   its own call-site-associated variables + the global fallback union
     *   + the framework globals, later sources never overriding earlier
     *   more-specific ones
     */
    public function forTemplate(string $realPath, ContextVariableExtractor $extractor): array
    {
        $vars = $this->byTemplate[$realPath] ?? [];
        foreach ([$this->fallback, $extractor->frameworkGlobals()] as $source) {
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
