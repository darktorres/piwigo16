<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Config\TestMode;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\NoLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Two rate-limit policies for the login surface, both backed by the
 * shared symfony/cache pool. Paired with LoginThrottle (per-user
 * lockout) — each defense is partial in isolation.
 *
 * Thresholds are hardcoded for now; config keys are gated on §1.6c
 * (config-schema metadata).
 */
final readonly class LoginRateLimiterFactory
{
    private RateLimiterFactory $ipFactory;
    private RateLimiterFactory $accountFactory;

    public function __construct(CacheItemPoolInterface $pool)
    {
        $storage = new CacheStorage($pool);
        $this->ipFactory = new RateLimiterFactory([
            'id'       => 'login_ip',
            'policy'   => 'sliding_window',
            'limit'    => 5,
            'interval' => '1 minute',
        ], $storage);
        $this->accountFactory = new RateLimiterFactory([
            'id'       => 'login_account',
            'policy'   => 'sliding_window',
            'limit'    => 10,
            'interval' => '10 minutes',
        ], $storage);
    }

    public function forIp(string $ip): LimiterInterface
    {
        // Integration tests legitimately log in many times per minute from
        // 127.0.0.1; the rate limit would mask the test's actual assertions.
        // TestMode is gated on a request header + a loopback REMOTE_ADDR, so
        // a remote attacker can't flip into this branch.
        if (TestMode::isActive()) {
            return new NoLimiter();
        }
        return $this->ipFactory->create($ip);
    }

    public function forAccount(string $usernameOrEmail): LimiterInterface
    {
        if (TestMode::isActive()) {
            return new NoLimiter();
        }
        return $this->accountFactory->create(strtolower($usernameOrEmail));
    }
}
