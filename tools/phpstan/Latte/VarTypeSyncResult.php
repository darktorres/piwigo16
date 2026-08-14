<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

/**
 * VarTypeSyncer::sync()'s output for one template.
 */
final readonly class VarTypeSyncResult
{
    /**
     * @param list<string> $notices
     */
    public function __construct(
        public string $content,
        public bool $changed,
        public array $notices,
    ) {}
}
