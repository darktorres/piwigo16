<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
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
        private ConfigRepository $configRepository,
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
        $execId    = substr(sha1(random_bytes(1000)), 0, 8);
        $tokenParam = $tokenName . '_running';
        $this->log->info('[' . $tokenName . '][exec=' . $execId . '] starts now');

        $existing = $this->configRepository->findValueByParam($tokenParam);
        if (is_string($existing) && $existing !== '') {
            [$runningExecId, $runningExecStartTime] = explode('-', $existing);
            if (time() - (int) $runningExecStartTime > $timeout) {
                $this->log->info('[' . $tokenName . '][exec=' . $execId . '] exec=' . $runningExecId . ', timeout stopped by another call');
                $this->release($tokenName);
            }
        }

        $this->configRepository->insertIgnoreParamValue($tokenParam, $execId . '-' . time());
        $runningExec = $this->configRepository->findValueByParam($tokenParam);
        if (!is_string($runningExec)) {
            $runningExec = '';
        }
        [$runningExecId] = explode('-', $runningExec);

        if ($runningExecId !== $execId) {
            $this->log->info('[' . $tokenName . '][exec=' . $execId . '] skip');
            return false;
        }
        $this->log->info('[' . $tokenName . '][exec=' . $execId . '] wins the race and gets the token!');
        return $execId;
    }

    public function isHeld(string $tokenName): bool
    {
        return $this->configRepository->paramExists($tokenName . '_running');
    }

    public function release(string $tokenName): void
    {
        $this->configService->confDeleteParam($tokenName . '_running');
        $this->log->info('[' . $tokenName . '] ends now');
    }
}
