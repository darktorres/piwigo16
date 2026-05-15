<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for legacy `loc_end_add_uploaded_file` (notify).
 *
 * New in 2.11
 *
 * Dispatched from: src/Piwigo/Admin/Upload/UploadService.php
 */
final readonly class LocEndAddUploadedFile
{
    /**
     * @param array<mixed> $imageInfos
     */
    public function __construct(
        public array $imageInfos,
    ) {
    }
}
