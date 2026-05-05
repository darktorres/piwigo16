<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Search\SearchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReindexImagesHandler
{
    public function __invoke(ReindexImagesJob $job): void
    {
        $repo = ServiceLocator::get(SearchRepository::class);
        $repo->deleteAll();

        LoggerRegistry::current()->info('search.reindex', [
            'since_id' => $job->sinceId,
        ]);
    }
}
