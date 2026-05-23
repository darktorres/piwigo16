<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Piwigo\Config\Config;

/**
 * Proxy-aware view of the current request's scheme and client IP.
 *
 * Reads `X-Forwarded-Proto` and `X-Forwarded-For` ONLY when the immediate
 * peer (`REMOTE_ADDR`) is in the trusted-proxy CIDR list configured via
 * `PIWIGO_TRUSTED_PROXIES`. Empty list (the default) = forwarded headers
 * are ignored, so an off-net attacker cannot fake the request scheme by
 * sending `X-Forwarded-Proto: https`.
 *
 * Trusted-proxy list format: comma-separated IPs or CIDR blocks, e.g.
 * `"10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1"`. Malformed entries are
 * skipped silently — the helper degrades to "no proxy" rather than
 * crashing the request.
 */
final class RequestScheme
{
    /** @var list<array{0:string,1:int}>|null parsed (binary-prefix, bits) tuples */
    private static ?array $cidrCache = null;

    private static ?string $cidrCacheKey = null;

    public static function isHttps(): bool
    {
        $direct = self::directHttps();
        if (!self::peerIsTrustedProxy()) {
            return $direct;
        }
        $forwarded = self::serverString('HTTP_X_FORWARDED_PROTO');
        if ($forwarded === '') {
            return $direct;
        }
        // X-Forwarded-Proto may be a comma-separated list when traversing
        // multiple proxies; the leftmost value is the original scheme.
        return strtolower(trim(explode(',', $forwarded)[0])) === 'https';
    }

    public static function clientIp(): string
    {
        $remote = self::serverString('REMOTE_ADDR');
        if (!self::peerIsTrustedProxy()) {
            return $remote;
        }
        $forwarded = self::serverString('HTTP_X_FORWARDED_FOR');
        if ($forwarded === '') {
            return $remote;
        }
        // Walk right-to-left, skip trusted hops, return the first untrusted
        // one — that's the real client. If every hop is trusted, fall back
        // to the leftmost entry (which is what the original client claimed).
        $hops = array_map(trim(...), explode(',', $forwarded));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = $hops[$i];
            if ($hop !== '' && !self::ipMatchesCidrList($hop, self::cidrList())) {
                return $hop;
            }
        }
        $leftmost = $hops[0];
        return $leftmost !== '' ? $leftmost : $remote;
    }

    /** Test seam: clear the parsed-CIDR cache so per-test config overrides take effect. */
    public static function resetCache(): void
    {
        self::$cidrCache = null;
        self::$cidrCacheKey = null;
    }

    private static function serverString(string $key): string
    {
        /** @psalm-var mixed $raw */
        $raw = $_SERVER[$key] ?? null;
        return is_string($raw) ? $raw : '';
    }

    private static function directHttps(): bool
    {
        $https = self::serverString('HTTPS');
        return $https !== '' && strtolower($https) !== 'off';
    }

    private static function peerIsTrustedProxy(): bool
    {
        $list = self::cidrList();
        if ($list === []) {
            return false;
        }
        $remote = self::serverString('REMOTE_ADDR');
        return $remote !== '' && self::ipMatchesCidrList($remote, $list);
    }

    /** @return list<array{0:string,1:int}> parsed (packed-prefix, bits) tuples */
    private static function cidrList(): array
    {
        $raw = Config::trustedProxies();
        if (self::$cidrCache !== null && self::$cidrCacheKey === $raw) {
            return self::$cidrCache;
        }
        $parsed = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $tuple = self::parseCidr($entry);
            if ($tuple !== null) {
                $parsed[] = $tuple;
            }
        }
        self::$cidrCacheKey = $raw;
        return self::$cidrCache = $parsed;
    }

    /** @return array{0:string,1:int}|null packed-prefix-bytes + prefix-bit-length */
    private static function parseCidr(string $entry): ?array
    {
        if (str_contains($entry, '/')) {
            $parts = explode('/', $entry, 2);
            if (count($parts) !== 2 || !ctype_digit($parts[1])) {
                return null;
            }
            $ip   = $parts[0];
            $bits = (int) $parts[1];
        } else {
            $ip   = $entry;
            $bits = -1; // sentinel → full-length match against the packed IP
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $maxBits = strlen($packed) * 8;
        if ($bits === -1) {
            $bits = $maxBits;
        }
        if ($bits < 0 || $bits > $maxBits) {
            return null;
        }
        return [$packed, $bits];
    }

    /** @param list<array{0:string,1:int}> $list */
    private static function ipMatchesCidrList(string $ip, array $list): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($list as [$prefix, $bits]) {
            if (strlen($prefix) !== strlen($packed)) {
                continue; // can't compare IPv4 against IPv6
            }
            if (self::packedPrefixMatch($packed, $prefix, $bits)) {
                return true;
            }
        }
        return false;
    }

    private static function packedPrefixMatch(string $a, string $b, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($fullBytes > 0 && substr($a, 0, $fullBytes) !== substr($b, 0, $fullBytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($a[$fullBytes]) & $mask) === (ord($b[$fullBytes]) & $mask);
    }
}
