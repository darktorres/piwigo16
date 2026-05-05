<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\DerivativeService;
use Piwigo\Image\ImageRepository;
use Piwigo\Job\GenerateDerivativeJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateDerivativeHandler
{
    public function __invoke(GenerateDerivativeJob $job): void
    {
        $image = ServiceLocator::get(ImageRepository::class)->findById($job->imageId);
        if ($image === null) {
            return;
        }

        ServiceLocator::get(DerivativeService::class)->generate($image, $job->size);

        LoggerRegistry::current()->info('derivative.generated', [
            'id'   => $job->imageId,
            'size' => $job->size,
        ]);
    }
}
