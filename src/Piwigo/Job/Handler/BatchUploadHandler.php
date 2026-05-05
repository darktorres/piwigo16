<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageRepository;
use Piwigo\Job\BatchUploadJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class BatchUploadHandler
{
    public function __invoke(BatchUploadJob $job): void
    {
        $count = ServiceLocator::get(ImageRepository::class)->countLoungeImages();

        LoggerRegistry::current()->info('batch_upload.process', [
            'batch_id'     => $job->batchId,
            'lounge_count' => $count,
        ]);
    }
}
