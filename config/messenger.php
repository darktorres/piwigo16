<?php

declare(strict_types=1);

use Piwigo\Job\BatchUploadJob;
use Piwigo\Job\GenerateDerivativeJob;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Job\SendNotificationEmailJob;

return [
    'transports' => [
        'async'  => ['queue_name' => 'piwigo_async'],
        'failed' => ['queue_name' => 'piwigo_failed'],
    ],
    'routing' => [
        GenerateDerivativeJob::class          => 'async',
        RegenerateAllDerivativesJob::class     => 'async',
        SendNotificationEmailJob::class        => 'async',
        BatchUploadJob::class                  => 'async',
        ReindexImagesJob::class                => 'async',
    ],
];
