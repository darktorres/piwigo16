<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Core\Kernel;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Image\DerivativeService;
use Piwigo\Image\ImageRepository;
use Piwigo\Job\GenerateDerivativeJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateDerivativeHandler
{
    public function __invoke(GenerateDerivativeJob $job): void
    {
        $image = Kernel::service(ImageRepository::class)->findById($job->imageId);
        if ($image === null) {
            return;
        }

        Kernel::service(DerivativeService::class)->generate($image, $job->size);

        LoggerRegistry::current()->info('derivative.generated', [
            'id'   => $job->imageId,
            'size' => $job->size,
        ]);
    }
}
