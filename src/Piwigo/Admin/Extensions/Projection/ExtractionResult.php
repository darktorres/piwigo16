<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions\Projection;

/**
 * {@see \Piwigo\Admin\Extensions\PemCatalog::extractArchive()}'s own
 * fixed result shape.
 */
final readonly class ExtractionResult
{
    public function __construct(
        public string $status,
        public ?string $id,
    ) {}
}
