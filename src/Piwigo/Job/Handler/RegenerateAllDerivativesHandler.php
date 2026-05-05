<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageRepository;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RegenerateAllDerivativesHandler
{
    public function __invoke(RegenerateAllDerivativesJob $job): void
    {
        $count = ServiceLocator::get(ImageRepository::class)->countAll();

        LoggerRegistry::current()->info('derivatives.regenerate_all', [
            'types'       => $job->types,
            'image_count' => $count,
        ]);
    }
}
