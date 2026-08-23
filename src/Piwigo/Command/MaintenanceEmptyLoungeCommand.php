<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Image\ImageService;
use Piwigo\Session\SessionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper for the admin/maintenance_actions.php "Empty upload lounge"
 * action (`case 'empty_lounge':` in `MaintenanceActionDispatcher`), which
 * itself just calls `ImageService::emptyLounge()` -- the same call this
 * command makes, resolved through the container instead of that
 * dispatcher's own manually-constructed `ImageService`.
 */
#[AsCommand(name: 'maintenance:empty-lounge', description: 'Move any lounge photos into their assigned albums')]
final class MaintenanceEmptyLoungeCommand extends Command
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly SessionService $sessionService,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->imageService->emptyLounge($this->sessionService);

        $output->writeln(count($rows ?? []) . ' photo(s) were moved from the upload lounge to their album(s).');

        return Command::SUCCESS;
    }
}
