<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Config\Config;

/**
 * One-shot, time-bounded HMAC tokens issued before a state-changing action
 * (registration, anonymous comment posting) and validated when the user
 * submits. Tokens encode an issue timestamp, a validity offset, and an
 * HMAC over (timestamp, IP-prefix, offset, caller-supplied data).
 */
final class EphemeralKeyService
{
    public function generate(int $validAfterSeconds, string $additionalData = ''): string
    {
        $time          = round(microtime(true), 1);
        /** @var mixed $remoteAddrRaw */
        $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $remoteAddr    = is_string($remoteAddrRaw) ? $remoteAddrRaw : '';
        return number_format($time, 1, '.', '') . ':' . $validAfterSeconds . ':'
            . hash_hmac(
                'md5',
                number_format($time, 1, '.', '') . substr($remoteAddr, 0, 5) . $validAfterSeconds . $additionalData,
                Config::secretKey()
            );
    }

    public function verify(string $key, string $additionalData = ''): bool
    {
        $time          = microtime(true);
        $parts         = explode(':', $key);
        /** @var mixed $remoteAddrRaw */
        $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $remoteAddr    = is_string($remoteAddrRaw) ? $remoteAddrRaw : '';
        if (count($parts) !== 3
            || $parts[0] > $time - (float) $parts[1]
            || $parts[0] < $time - 3600.0
            || hash_hmac('md5', $parts[0] . substr($remoteAddr, 0, 5) . $parts[1] . $additionalData, Config::secretKey()) !== $parts[2]
        ) {
            return false;
        }
        return true;
    }
}
