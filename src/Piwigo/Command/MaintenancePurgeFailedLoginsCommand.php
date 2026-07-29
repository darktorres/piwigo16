<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository;
use Piwigo\Auth\UserFailedLoginRepository;
use Piwigo\Core\Env;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper purging both `user_failed_logins` rows and stale
 * `integrity_ignored_anomalies` rows (any Piwigo version, not just the
 * currently-running one) older than RETENTION_DAYS -- folded into one
 * command rather than two, per the integration plan's own reasoning: an
 * old-version ignored-anomaly row has no other natural cleanup path,
 * unlike every other retired config-blob mechanism this migration touches.
 */
#[AsCommand(name: 'maintenance:purge-failed-logins', description: 'Purge old failed-login attempts and stale integrity-ignore records')]
final class MaintenancePurgeFailedLoginsCommand extends Command
{
    private const int RETENTION_DAYS = 90;

    public function __construct(
        private readonly UserFailedLoginRepository $failedLoginRepo,
        private readonly IntegrityIgnoredAnomalyRepository $ignoredAnomalyRepo,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = (clone Env::now())->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s');

        $failedLogins = $this->failedLoginRepo->purgeOlderThan($before);
        $ignoredAnomalies = $this->ignoredAnomalyRepo->purgeOlderThan($before);

        $output->writeln("Purged {$failedLogins} failed-login attempt(s) and {$ignoredAnomalies} stale integrity-ignore record(s) older than " . self::RETENTION_DAYS . ' days.');

        return Command::SUCCESS;
    }
}
