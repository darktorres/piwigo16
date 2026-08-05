<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Db\DbCredentials;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * New CLI-only capability (no web equivalent). Reads via a raw `mysql`/
 * `psql` client shell-out (env-var credentials, Piwigo\Db\DbCredentials)
 * rather than a Users domain service. docs/PLAN.md's P12 audit note
 * (2026-07-13) records that this docblock used to point at a
 * "scope-decision section" that never existed. Doctrine DBAL (P14) and a
 * real Users domain service (Piwigo\Users\UserRepository/UserService) have
 * both since landed and are usable from CLI commands (see sibling commands
 * in this directory); this command has not yet been migrated to use them.
 *
 * pgsql support pass: real bug found live -- this always shelled out to
 * `mysql` regardless of `PIWIGO_DB_DRIVER`, so `bin/piwigo user:list`
 * against a real Postgres install didn't just produce wrong output, it
 * hung for the full 60s Process timeout (the `mysql` binary has no idea
 * how to speak the Postgres wire protocol on a Postgres port) and then
 * failed outright. Branches per platform, matching every other real CLI-
 * shelling call site in this codebase (IntegrationTestCase, RegenerateFixtureTest).
 */
#[AsCommand(name: 'user:list', description: 'List registered users (id, username, email, status, registered)')]
final class UserListCommand extends Command
{
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $credentials = DbCredentials::fromEnv();

        $query = sprintf(
            'SELECT u.id, u.username, u.mail_address, i.status, i.registration_date '
            . 'FROM %1$susers u LEFT JOIN %1$suser_infos i ON i.user_id = u.id ORDER BY u.id',
            $credentials->prefix
        );

        if ($credentials->driver === 'pgsql') {
            $process = new Process(
                [
                    'psql',
                    ...$credentials->toPsqlArgs(),
                    '-d',
                    $credentials->database,
                    '-t',
                    '-A',
                    '-F',
                    "\t",
                    '-c',
                    $query,
                ],
                null,
                $credentials->password !== '' ? array_merge(getenv(), [
                    'PGPASSWORD' => $credentials->password,
                ]) : null,
            );
        } else {
            $process = new Process([
                'mysql',
                ...$credentials->toMysqlArgs(),
                '--batch',
                '--raw',
                '-N',
                $credentials->database,
                '-e',
                $query,
            ]);
        }

        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $output->writeln('<error>Query failed: ' . $process->getErrorOutput() . '</error>');

            return Command::FAILURE;
        }

        $rows = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = explode("\t", $line);
        }

        if ($rows === []) {
            $output->writeln('No users found.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Username', 'Email', 'Status', 'Registered']);
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }
}
