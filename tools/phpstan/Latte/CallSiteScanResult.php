<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * TemplateCallSiteScanner's output: which classes render which real
 * template files, and which TemplatePageContext classes each class
 * assigns -- the two halves VariableMapBuilder joins into per-template
 * variable maps.
 */
final class CallSiteScanResult
{
    /**
     * @param array<string, list<string>> $templatesByClass class FQCN => template realpaths
     * @param array<string, list<string>> $contextsByClass class FQCN => context class FQCNs
     * @param list<string> $assignedTemplateVars literal first arguments of
     *   assignVarFromTemplate() calls -- template variables holding rendered
     *   Html, consumed by whichever template renders later
     * @param list<string> $notices non-fatal skips worth surfacing (unresolvable
     *   literals, non-`new` assignContext arguments, fallback-widened lookups)
     */
    public function __construct(
        public readonly array $templatesByClass,
        public readonly array $contextsByClass,
        public readonly array $assignedTemplateVars,
        public readonly array $notices,
    ) {}
}
