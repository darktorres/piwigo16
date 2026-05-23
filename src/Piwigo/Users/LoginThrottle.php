<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Per-user brute-force lockout. Paired with the per-IP / per-account
 * rate limiter in LoginRateLimiterFactory — each defense is partial in
 * isolation.
 *
 * Thresholds are hardcoded for now; config keys are gated on §1.6c
 * (config-schema metadata).
 */
final readonly class LoginThrottle
{
    public function __construct(
        private UserFailedLoginRepository $repo,
        private int $threshold = 5,
        private int $windowSeconds = 900,
    ) {
    }

    public function isLockedOut(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return $this->repo->recentFailureCountForUser($userId, $this->windowSeconds) >= $this->threshold;
    }

    public function recordFailure(?int $userId, string $ip): void
    {
        $this->repo->insertFailure($userId, $ip, new \DateTimeImmutable());
    }

    public function clearFailures(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->repo->deleteForUser($userId);
    }

    public function purgeExpired(): void
    {
        $this->repo->purgeOlderThan(new \DateTimeImmutable('-' . $this->windowSeconds . ' seconds'));
    }
}
