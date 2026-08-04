<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Piwigo\Db\SchemaDumpService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'schema:dump', description: 'Regenerate install/piwigo_structure-{mysql,pgsql}.sql from Doctrine Migrations against the current connection')]
final class SchemaDumpCommand extends Command
{
    public function __construct(
        private readonly SchemaDumpService $schemaDumpService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->schemaDumpService->dump();
        } catch (\Throwable $e) {
            $output->writeln("<error>schema:dump failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $output->writeln("Wrote {$result['path']} (detected provider: {$result['label']})");

        return Command::SUCCESS;
    }
}
