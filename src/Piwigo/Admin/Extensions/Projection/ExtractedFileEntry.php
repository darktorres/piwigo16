<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions\Projection;

/**
 * One entry of {@see \Piwigo\Admin\Extensions\ZipExtractor::extract()}'s
 * own fixed per-file result shape.
 */
final readonly class ExtractedFileEntry
{
    public function __construct(
        public string $filename,
        public string $storedFilename,
        public string $status,
    ) {}
}
