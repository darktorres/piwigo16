<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
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
        $rows  = ServiceLocator::get(ImageRepository::class)->findAllIdFilename();
        $bus   = ServiceLocator::get(MessageBusInterface::class);
        $types = $job->types === ['all']
            ? ServiceLocator::get(DerivativeService::class)->getDefinedTypes()
            : $job->types;

        $dispatched = 0;
        foreach ($rows as $row) {
            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            if ($id === 0) {
                continue;
            }
            foreach ($types as $type) {
                $bus->dispatch(new GenerateDerivativeJob($id, $type));
                $dispatched++;
            }
        }

        LoggerRegistry::current()->info('derivatives.regenerate_all.dispatched', [
            'types'      => $types,
            'dispatched' => $dispatched,
        ]);
    }
}
