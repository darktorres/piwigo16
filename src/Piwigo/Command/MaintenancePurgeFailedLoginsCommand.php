<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Override;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository;
use Piwigo\Auth\PasswordResetRequestRepository;
use Piwigo\Auth\UserFailedLoginRepository;
use Piwigo\Core\Env;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI wrapper purging `user_failed_logins` rows, [P44-L]
 * `password_reset_requests` rows, and stale `integrity_ignored_anomalies`
 * rows (any Piwigo version, not just the currently-running one) older
 * than RETENTION_DAYS -- folded into one command rather than several, per
 * the integration plan's own reasoning: an old-version ignored-anomaly
 * row has no other natural cleanup path, unlike every other retired
 * config-blob mechanism this migration touches; `password_reset_requests`
 * shares `user_failed_logins`'s own append-only, rate-limit-ledger shape
 * and the same retention need.
 */
#[AsCommand(name: 'maintenance:purge-failed-logins', description: 'Purge old failed-login attempts, reset-code requests, and stale integrity-ignore records')]
final class MaintenancePurgeFailedLoginsCommand extends Command
{
    private const int RETENTION_DAYS = 90;

    public function __construct(
        private readonly UserFailedLoginRepository $failedLoginRepo,
        private readonly PasswordResetRequestRepository $passwordResetRequestRepo,
        private readonly IntegrityIgnoredAnomalyRepository $ignoredAnomalyRepo,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = (clone Env::now())->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s');

        $failedLogins = $this->failedLoginRepo->purgeOlderThan($before);
        $passwordResetRequests = $this->passwordResetRequestRepo->purgeOlderThan($before);
        $ignoredAnomalies = $this->ignoredAnomalyRepo->purgeOlderThan($before);

        $output->writeln("Purged {$failedLogins} failed-login attempt(s), {$passwordResetRequests} reset-code request(s), and {$ignoredAnomalies} stale integrity-ignore record(s) older than " . self::RETENTION_DAYS . ' days.');

        return Command::SUCCESS;
    }
}
