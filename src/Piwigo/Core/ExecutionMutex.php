<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\DBAL\Connection;
use Piwigo\Config\ConfigService;
use Piwigo\Db\Tables;
use Psr\Log\LoggerInterface;

/**
 * Cross-request advisory mutex backed by the `config` table.
 *
 * Each token name maps to a `{name}_running` config row whose value is
 * `{execId}-{startTime}`. {@see self::acquire()} tries to claim the token;
 * {@see self::isHeld()} reports whether anything is currently claiming it;
 * {@see self::release()} drops the claim. A claim older than `$timeout`
 * seconds is treated as stale and forcibly released.
 *
 * Used by `TelemetryService::sendInfos()` and `UserService` cache rebuilds
 * to prevent concurrent runs of an expensive operation. Replaces
 * `Util::pwgUniqueExec{Begins,IsRunning,Ends}` (Phase 5).
 */
final readonly class ExecutionMutex
{
    public function __construct(
        private Connection $conn,
        private ConfigService $configService,
        private LoggerInterface $log,
    ) {
    }

    /**
     * Try to claim the token. Returns the execution id on success, or `false`
     * if another holder is currently running and hasn't timed out yet.
     */
    public function acquire(string $tokenName, int $timeout = 60): false|string
    {
        $execId = substr(sha1(random_bytes(1000)), 0, 8);
        $this->log->info('[' . $tokenName . '][exec=' . $execId . '] starts now');

        $existing = $this->conn->executeQuery(
            'SELECT value FROM ' . Tables::config() . ' WHERE param = ?',
            [$tokenName . '_running']
        )->fetchOne();
        if (is_string($existing) && $existing !== '') {
            [$runningExecId, $runningExecStartTime] = explode('-', $existing);
            if (time() - (int) $runningExecStartTime > $timeout) {
                $this->log->info('[' . $tokenName . '][exec=' . $execId . '] exec=' . $runningExecId . ', timeout stopped by another call');
                $this->release($tokenName);
            }
        }

        $this->conn->executeStatement('INSERT IGNORE INTO ' . Tables::config() . ' SET param=?, value=?', [$tokenName . '_running', $execId . '-' . time()]);
        $runningExec = $this->conn->executeQuery('SELECT value FROM ' . Tables::config() . ' WHERE param = ?', [$tokenName . '_running'])->fetchOne();
        [$runningExecId] = explode('-', is_scalar($runningExec) ? (string) $runningExec : '');

        if ($runningExecId !== $execId) {
            $this->log->info('[' . $tokenName . '][exec=' . $execId . '] skip');
            return false;
        }
        $this->log->info('[' . $tokenName . '][exec=' . $execId . '] wins the race and gets the token!');
        return $execId;
    }

    public function isHeld(string $tokenName): bool
    {
        $counter = $this->conn->executeQuery('SELECT COUNT(*) FROM ' . Tables::config() . ' WHERE param = ?', [$tokenName . '_running'])->fetchOne();
        return is_numeric($counter) && (int) $counter > 0;
    }

    public function release(string $tokenName): void
    {
        $this->configService->confDeleteParam($tokenName . '_running');
        $this->log->info('[' . $tokenName . '] ends now');
    }
}
