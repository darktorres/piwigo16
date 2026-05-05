<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Job;

use PHPUnit\Framework\TestCase;
use Piwigo\Job\BatchUploadJob;
use Piwigo\Job\GenerateDerivativeJob;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Job\SendNotificationEmailJob;

final class JobMessageClassesTest extends TestCase
{
    public function test_GenerateDerivativeJob_stores_properties(): void
    {
        $job = new GenerateDerivativeJob(42, 'thumb');
        self::assertSame(42, $job->imageId);
        self::assertSame('thumb', $job->size);
    }

    public function test_GenerateDerivativeJob_is_readonly(): void
    {
        $job = new GenerateDerivativeJob(1, 'medium');
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $job->imageId = 99;
    }

    public function test_RegenerateAllDerivativesJob_stores_types(): void
    {
        $job = new RegenerateAllDerivativesJob(['thumb', 'medium']);
        self::assertSame(['thumb', 'medium'], $job->types);
    }

    public function test_RegenerateAllDerivativesJob_all_sentinel(): void
    {
        $job = new RegenerateAllDerivativesJob(['all']);
        self::assertSame(['all'], $job->types);
    }

    public function test_SendNotificationEmailJob_stores_properties(): void
    {
        $job = new SendNotificationEmailJob(7, 'registration', ['to' => 'a@b.com', 'subject' => 'Hi']);
        self::assertSame(7, $job->userId);
        self::assertSame('registration', $job->template);
        self::assertSame('a@b.com', $job->params['to']);
        self::assertSame('Hi', $job->params['subject']);
    }

    public function test_BatchUploadJob_stores_batch_id(): void
    {
        $job = new BatchUploadJob(3);
        self::assertSame(3, $job->batchId);
    }

    public function test_ReindexImagesJob_defaults_since_id_to_null(): void
    {
        $job = new ReindexImagesJob();
        self::assertNull($job->sinceId);
    }

    public function test_ReindexImagesJob_stores_since_id(): void
    {
        $job = new ReindexImagesJob(1000);
        self::assertSame(1000, $job->sinceId);
    }
}
