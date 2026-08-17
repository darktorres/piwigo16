<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper for `pwg.images.deleteOrphans` -- deletes photos linked to
 * no album, one block at a time (`--block-size`, default 1000, matching
 * `Ws\Images\DeleteOrphansParams`'s own default). Its WS predecessor's
 * orchestration (`ImageService::getOrphans()`/`deleteElements()` +
 * `PermissionCacheInvalidator::invalidate()`) is thin enough to call
 * directly here too, same as the WS handler does.
 */
#[AsCommand(name: 'maintenance:delete-orphans', description: 'Delete photos linked to no album, one block at a time')]
final class MaintenanceDeleteOrphansCommand extends Command
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly UrlServiceInterface $urlService,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('block-size', null, InputOption::VALUE_REQUIRED, 'Maximum number of orphans to delete in this run', '1000');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $blockSize = max(1, (int) $input->getOption('block-size'));

        $orphanIdsToDelete = array_slice($this->imageService->getOrphans(), 0, $blockSize);
        $deletedCount = $this->imageService->deleteElements($orphanIdsToDelete, $this->urlService, true);
        PermissionCacheInvalidator::invalidate();

        $remaining = count($this->imageService->getOrphans());

        $output->writeln("Deleted {$deletedCount} orphan photo(s).");
        $output->writeln("{$remaining} orphan photo(s) remaining.");

        return Command::SUCCESS;
    }
}
