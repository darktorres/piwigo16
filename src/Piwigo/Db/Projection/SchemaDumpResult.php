<?php

declare(strict_types=1);

namespace Piwigo\Db\Projection;

/**
 * {@see \Piwigo\Db\SchemaDumpService::dump()}'s own fixed result shape.
 */
final readonly class SchemaDumpResult
{
    public function __construct(
        public string $label,
        public string $path,
    ) {}
}
