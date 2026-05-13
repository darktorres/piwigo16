<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Core\Kernel;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Job\BatchUploadJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class BatchUploadHandler
{
    public function __invoke(BatchUploadJob $job): void
    {
        $moved = Kernel::service(CategoryAdminService::class)->emptyLounge();

        LoggerRegistry::current()->info('batch_upload.lounge_emptied', [
            'batch_id' => $job->batchId,
            'moved'    => count($moved ?? []),
        ]);
    }
}
