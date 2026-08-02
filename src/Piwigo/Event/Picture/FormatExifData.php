<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `format_exif_data` filter. No handler is
 * registered for it anywhere today. `$exif` is nullable -- diverges from
 * the reference's non-nullable `array<mixed>` -- since this branch's own
 * real dispatch site (`MetadataService::getExifData()`) has a real
 * null-payload dispatch (before falling back to a real exif_read_data()
 * array at its second dispatch site).
 */
final readonly class FormatExifData
{
    /**
     * @param ?array<mixed> $exif
     * @param array<string, string> $map
     */
    public function __construct(
        public ?array $exif,
        public string $filename,
        public array $map,
    ) {}
}
