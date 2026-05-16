<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `upload_file` (dispatch).
 *
 * Dispatched from: src/Piwigo/Admin/Upload/UploadService.php
 */
final readonly class UploadFile
{
    public function __construct(
        public string $representativeExt,
        public string $filePath,
    ) {
    }
}
