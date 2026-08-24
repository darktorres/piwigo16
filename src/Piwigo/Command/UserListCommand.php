<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Users\Projection\UserAdminListingRow;
use Piwigo\Users\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:list', description: 'List registered users (id, username, email, status, registered)')]
final class UserListCommand extends Command
{
    public function __construct(
        private readonly UserRepository $repo,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->repo->findAllForAdminListing();

        if ($users === []) {
            $output->writeln('No users found.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Username', 'Email', 'Status', 'Registered']);
        $table->setRows(array_map(
            static fn (UserAdminListingRow $row): array => [
                $row->id->value,
                $row->username->value,
                $row->mailAddress->value ?? '',
                $row->status->value ?? '',
                (string) ($row->registrationDate ?? ''),
            ],
            $users,
        ));
        $table->render();

        return Command::SUCCESS;
    }
}
