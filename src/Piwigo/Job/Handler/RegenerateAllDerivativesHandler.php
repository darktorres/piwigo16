<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Core\Kernel;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Image\DerivativeService;
use Piwigo\Image\ImageRepository;
use Piwigo\Job\GenerateDerivativeJob;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class RegenerateAllDerivativesHandler
{
    public function __invoke(RegenerateAllDerivativesJob $job): void
    {
        $rows  = Kernel::service(ImageRepository::class)->findAllIdFilename();
        $bus   = Kernel::service(MessageBusInterface::class);
        $types = $job->types === ['all']
            ? Kernel::service(DerivativeService::class)->getDefinedTypes()
            : $job->types;

        $dispatched = 0;
        foreach ($rows as $row) {
            foreach ($types as $type) {
                $bus->dispatch(new GenerateDerivativeJob($row->id->value, $type));
                $dispatched++;
            }
        }

        LoggerRegistry::current()->info('derivatives.regenerate_all.dispatched', [
            'types'      => $types,
            'dispatched' => $dispatched,
        ]);
    }
}
