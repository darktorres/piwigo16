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
            /**
             * @psalm-suppress RedundantCondition
             * @psalm-suppress TypeDoesNotContainType Psalm infers
             *   $row->status as always non-null here, but
             *   UserAdminListingRow's own docblock documents it as
             *   genuinely nullable (a user row with no matching
             *   `user_infos` row via the LEFT JOIN in
             *   findAllForAdminListing()); PHP's property-read-on-null
             *   semantics make plain `->` safe here (returns null rather
             *   than throwing), which is what the trailing `?? ''` relies
             *   on -- PHPStan flags a nullsafe `?->` on this same line as
             *   unnecessary for the identical reason.
             */
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
