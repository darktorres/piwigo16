<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Override;
use Piwigo\Admin\Upload\Event\UploadFile;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Core\SubscriberInterface;

/**
 * Extracted from `Bootstrap\RequestBootstrap::finalize()`'s own 6
 * `UploadFile` registrations -- all real, all the same event, each
 * delegating to one of `UploadService`'s per-format handlers.
 * `addTypedHandler()` already supports multiple handlers per event, so
 * this is one listener with 6 methods, not 6 separate classes.
 * `UploadService` is a real constructor dependency (not a static call) --
 * `RequestBootstrap` passes its own container-resolved instance, the
 * same one every other real `UploadService` consumer gets, standard
 * container hygiene rather than an event-dedup concern.
 */
final readonly class UploadFormatListener implements SubscriberInterface
{
    public function __construct(
        private UploadService $uploadService,
    ) {}

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
        return $this->uploadService->uploadFilePdf($event);
    }

    public function onHeic(UploadFile $event): UploadFile
    {
        return $this->uploadService->uploadFileHeic($event);
    }

    public function onTiff(UploadFile $event): UploadFile
    {
        return $this->uploadService->uploadFileTiff($event);
    }

    public function onVideo(UploadFile $event): UploadFile
    {
        return $this->uploadService->uploadFileVideo($event);
    }

    public function onPsd(UploadFile $event): UploadFile
    {
        return $this->uploadService->uploadFilePsd($event);
    }

    public function onEps(UploadFile $event): UploadFile
    {
        return $this->uploadService->uploadFileEps($event);
    }
}
