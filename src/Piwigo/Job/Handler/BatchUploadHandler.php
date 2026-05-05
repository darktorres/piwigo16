<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Job\BatchUploadJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class BatchUploadHandler
{
    public function __invoke(BatchUploadJob $job): void
    {
        $moved = ServiceLocator::get(CategoryAdminService::class)->emptyLounge();

        LoggerRegistry::current()->info('batch_upload.lounge_emptied', [
            'batch_id' => $job->batchId,
            'moved'    => count($moved ?? []),
        ]);
    }
}
