<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * LatteTemplateCompiler's per-template result.
 */
final class CompiledTemplate
{
    /**
     * @param list<string> $notices variable names skipped as invalid PHP
     *   identifiers (extract() could never define them as locals either)
     */
    public function __construct(
        public readonly string $outputPath,
        public readonly bool $changed,
        public readonly array $notices,
    ) {}
}
