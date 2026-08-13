<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * ContextVariableExtractor's output for one TemplatePageContext class.
 */
final class ExtractedVariables
{
    /**
     * @param array<string, string> $vars template variable name => PHPStan type string
     * @param list<string> $notices approximations and skips worth surfacing
     *   (dynamic keys, expressions typed via their underlying property, ...)
     */
    public function __construct(
        public readonly array $vars,
        public readonly array $notices,
    ) {}
}
