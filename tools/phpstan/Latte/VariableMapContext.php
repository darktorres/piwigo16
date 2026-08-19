<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use Latte\Runtime\Html;

/**
 * The scan -> extract -> build -> globals sequence PhpStanLatteCompileCommand
 * and PhpStanLatteSyncVarTypeCommand both need identically -- extracted so
 * neither can silently diverge on what a template's variable set is (that
 * divergence is exactly the class of bug VarTypeSyncer exists to avoid
 * introducing at the .latte-source layer).
 */
final readonly class VariableMapContext
{
    /**
     * @param array<string, string> $globals always-available variables from
     *   outside the context system, merged into every template's own map
     * @param list<string> $notices non-fatal scan/extract/theme-vars skips
     *   worth surfacing
     * @param list<string> $fallbackContexts context FQCNs that fell back to
     *   the global union instead of a per-template association
     */
    public function __construct(
        public VariableMap $map,
        public array $globals,
        public array $notices,
        public array $fallbackContexts,
    ) {}

    public static function build(string $root): self
    {
        $scan = new TemplateCallSiteScanner($root)
            ->scan();

        $extractor = new ContextVariableExtractor();
        $varsByContext = [];
        $notices = $scan->notices;
        foreach ($scan->contextsByClass as $contexts) {
            foreach ($contexts as $context) {
                if (isset($varsByContext[$context])) {
                    continue;
                }
                $extracted = $extractor->extract($context);
                $varsByContext[$context] = $extracted->vars;
                $notices = [...$notices, ...$extracted->notices];
            }
        }

        $templateTypes = TemplateTypeScanner::scan(LatteTemplateFiles::discover($root));

        $map = new VariableMapBuilder($scan->templatesByClass, $scan->contextsByClass, $varsByContext, $extractor, $templateTypes)
            ->build();

        // Always-available variables from outside the context system:
        // Template.php's own framework assigns, every theme's parseable
        // $theme_template_vars literal, and assignVarFromTemplate()'s
        // rendered-Html outputs.
        $themeVars = $extractor->themeTemplateVars($root);
        $notices = [...$notices, ...$themeVars['notices']];
        $globals = $extractor->frameworkGlobals() + $themeVars['vars'];
        foreach ($scan->assignedTemplateVars as $name) {
            // Leading backslash required: this feeds
            // LatteTemplateCompiler's `/** @var {$type} ... */` docblock
            // generation (and VarTypeSyncer's `{varType}` generation),
            // spliced directly into generated source text -- ::class alone
            // never carries one, which would leave the emitted type
            // namespace-relative instead of absolute. Concatenation, not a
            // bare string literal, so Rector's StringClassNameToClassConstantRector
            // has nothing to rewrite here (empirically verified).
            $globals[$name] ??= '\\' . Html::class;
        }

        return new self($map, $globals, $notices, $map->fallbackContexts);
    }
}
