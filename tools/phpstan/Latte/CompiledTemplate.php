<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * LatteTemplateCompiler's per-template result.
 */
final readonly class CompiledTemplate
{
    /**
     * @param list<string> $notices variable names skipped as invalid PHP
     *   identifiers (extract() could never define them as locals either)
     */
    public function __construct(
        public string $outputPath,
        public bool $changed,
        public array $notices,
    ) {}
}
