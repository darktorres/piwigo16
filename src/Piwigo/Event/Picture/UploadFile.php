<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `upload_file` filter. Registered 6x, one per
 * format handler (Pdf/Heic/Tiff/Video/Psd/Eps, wired from
 * `RequestBootstrap.php`) -- mutable on `$representativeExt`.
 * `$representativeExt` is nullable -- diverges from the reference's
 * non-nullable `string` -- since its one real dispatch site
 * (`UploadService::uploadFile()`) starts the chain from `null`, and every
 * registered handler's own signature is `(?string $representative_ext,
 * string $file_path): ?string`.
 */
final class UploadFile
{
    public function __construct(
        public ?string $representativeExt,
        public readonly string $filePath,
    ) {}
}
