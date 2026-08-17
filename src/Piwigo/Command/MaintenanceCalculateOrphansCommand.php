<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Category\CategoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper for `pwg.categories.calculateOrphans` -- reports how many
 * photos would become orphan (linked to no other album) if the given
 * album (and its sub-albums) were deleted, via
 * `CategoryService::calculateOrphanImpact()` (shared with
 * `Ws\Categories\CalculateOrphansHandler`, which the admin/Batch-Manager
 * "delete album" confirmation dialog still calls). `getCacheSize`'s
 * sibling classes in this same commit are the other 3 -- see
 * `MaintenanceCacheSizeCommand`/`MaintenanceDeleteOrphansCommand`/
 * `MaintenanceSyncMetadataCommand`.
 */
#[AsCommand(name: 'maintenance:calculate-orphans', description: 'Report how many photos would become orphan if an album were deleted')]
final class MaintenanceCalculateOrphansCommand extends Command
{
    public function __construct(
        private readonly CategoryService $categoryService,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('category-id', InputArgument::REQUIRED, 'The album id to evaluate');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawCategoryId = $input->getArgument('category-id');
        $categoryId = is_numeric($rawCategoryId) ? (int) $rawCategoryId : 0;

        $impact = $this->categoryService->calculateOrphanImpact($categoryId);

        $output->writeln("Images recursively linked to album {$categoryId}: {$impact['nbImagesRecursive']}");
        $output->writeln("Also linked to another album: {$impact['nbImagesAssociatedOutside']}");
        $output->writeln("Would become orphan: {$impact['nbImagesBecomingOrphan']}");

        return Command::SUCCESS;
    }
}
