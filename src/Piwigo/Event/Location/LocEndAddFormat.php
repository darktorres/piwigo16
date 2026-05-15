<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_end_add_format` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Upload/UploadService.php
 */
final readonly class LocEndAddFormat
{
    /**
     * @param array<mixed> $formatInfos
     */
    public function __construct(
        public array $formatInfos,
    ) {
    }
}
