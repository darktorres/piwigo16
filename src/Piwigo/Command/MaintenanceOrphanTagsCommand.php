<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper for the admin/maintenance_actions.php "Delete orphan tags"
 * action, via the new DbMaintenanceRepository::deleteOrphanTags() (see that
 * method's own docblock for why it's a plain DB cleanup, not a full replay
 * of TagService::deleteTags()'s user-facing side effects).
 */
#[AsCommand(name: 'maintenance:orphan-tags', description: 'Delete tags with no linked image, untouched for over a day')]
final class MaintenanceOrphanTagsCommand extends Command
{
    public function __construct(
        private readonly DbMaintenanceRepository $repo,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->repo->deleteOrphanTags();

        $output->writeln("Deleted {$deleted} orphan tag(s).");

        return Command::SUCCESS;
    }
}
