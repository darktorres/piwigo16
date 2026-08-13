<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Override;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Event\Picture\UploadFile;

/**
 * Extracted from `Bootstrap\RequestBootstrap::finalize()`'s own 6
 * `UploadFile` registrations -- all real, all the same event, each
 * delegating to one of `UploadService`'s static per-format handlers.
 * `addTypedHandler()` already supports multiple handlers per event, so
 * this is one listener with 6 methods, not 6 separate classes; no
 * constructor dependencies are needed since every target method is
 * static.
 */
final readonly class UploadFormatListener implements ListenerInterface
{
    #[Override]
    public function subscribedEvents(): array
    {
        return [
            UploadFile::class => [
                $this->onPdf(...),
                $this->onHeic(...),
                $this->onTiff(...),
                $this->onVideo(...),
                $this->onPsd(...),
                $this->onEps(...),
            ],
        ];
    }

    public function onPdf(UploadFile $event): UploadFile
    {
        return UploadService::uploadFilePdf($event);
    }

    public function onHeic(UploadFile $event): UploadFile
    {
        return UploadService::uploadFileHeic($event);
    }

    public function onTiff(UploadFile $event): UploadFile
    {
        return UploadService::uploadFileTiff($event);
    }

    public function onVideo(UploadFile $event): UploadFile
    {
        return UploadService::uploadFileVideo($event);
    }

    public function onPsd(UploadFile $event): UploadFile
    {
        return UploadService::uploadFilePsd($event);
    }

    public function onEps(UploadFile $event): UploadFile
    {
        return UploadService::uploadFileEps($event);
    }
}
