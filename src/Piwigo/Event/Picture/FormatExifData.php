<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `format_exif_data` (dispatch).
 *
 * Dispatched from: src/Piwigo/Metadata/MetadataService.php
 */
final readonly class FormatExifData
{
    /**
     * @param array<mixed> $exif
     * @param array<mixed> $map
     */
    public function __construct(
        public array $exif,
        public string $filename,
        public array $map,
    ) {
    }
}
