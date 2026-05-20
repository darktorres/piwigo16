<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Config\Config;

/**
 * Image privacy level value object. Constrained at construction time
 * against {@see Config::availablePermissionLevels()} (default
 * `[0, 1, 2, 4, 8]`) — invalid integers parse to null via tryFrom.
 *
 * Replaces the loose `int $level` parameter threaded through WS users
 * and image-permission endpoints; centralises the
 * `in_array($v, availablePermissionLevels())` validation that
 * previously appeared at every consumer.
 */
final readonly class PermissionLevel
{
    public function __construct(public int $value)
    {
    }

    public static function tryFrom(int $value): ?self
    {
        return in_array($value, Config::availablePermissionLevels(), true) ? new self($value) : null;
    }

    public static function maxAvailable(): self
    {
        return new self(max(Config::availablePermissionLevels()));
    }
}
