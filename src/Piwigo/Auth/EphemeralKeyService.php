<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Short-lived, time-windowed keys sent back with a form (e.g. a signed
 * "not submitted too fast" / "not submitted too late" token), keyed by the
 * secret-key config. Reads `global $conf['secret_key']` directly, NOT
 * Piwigo\Config\Config::secretKey() -- confirmed empirically (recomputed a
 * real live pwg_token and it matched the empty-secret hash, not the real
 * DB-persisted secret_key) that Config::secretKey() is silently inert on a
 * live request, same root cause as the SEC-29 UrlService bug: secret_key is
 * inserted into the piwigo_config DB table at install time
 * (install/upgrade_1.6.2.php), loaded into $conf by the legacy
 * load_conf_from_db(), with no sync back into Config::$data. This also
 * means the already-shipped Piwigo\Csrf\CsrfService has the identical bug
 * (see task tracking the CsrfService fix) -- caught here before this class
 * shipped, unlike Csrf which needs its own follow-up fix commit.
 *
 * [SEC-28] Built with sha256 + hash_equals() from the start, unlike the
 * original get_ephemeral_key()/verify_ephemeral_key() (hash_hmac('md5', ...),
 * compared with a plain !== ) -- there's no insecure intermediate state to
 * carry forward since this class doesn't exist yet on this branch.
 */
final class EphemeralKeyService
{
    /**
     * Returns a "secret key" that is to be sent back when a user posts a
     * form.
     *
     * @param int $validAfterSeconds key validity start time from now
     */
    public function generate(int $validAfterSeconds, string $additionalDataToHash = ''): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $time = round(microtime(true), 1);
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        $remote_addr = is_string($remote_addr) ? $remote_addr : '';
        $secret_key = $conf['secret_key'] ?? '';
        $secret_key = is_scalar($secret_key) ? (string) $secret_key : '';

        return $time . ':' . $validAfterSeconds . ':'
            . hash_hmac(
                'sha256',
                $time . substr($remote_addr, 0, 5) . $validAfterSeconds . $additionalDataToHash,
                $secret_key
            );
    }

    /**
     * Verifies a key sent back with a form.
     */
    public function verify(string $key, string $additionalDataToHash = ''): bool
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $time = microtime(true);
        $keyParts = explode(':', $key);

        if (count($keyParts) !== 3) {
            return false;
        }

        [$issuedAt, $validAfterSeconds, $signature] = $keyParts;

        if (! is_numeric($issuedAt) || ! is_numeric($validAfterSeconds)) {
            return false;
        }

        if ((float) $issuedAt > $time - (float) $validAfterSeconds) { // page must have been retrieved more than X sec ago
            return false;
        }

        if ((float) $issuedAt < $time - 3600) { // 60 minutes expiration
            return false;
        }

        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        $remote_addr = is_string($remote_addr) ? $remote_addr : '';
        $secret_key = $conf['secret_key'] ?? '';
        $secret_key = is_scalar($secret_key) ? (string) $secret_key : '';

        $expected = hash_hmac(
            'sha256',
            $issuedAt . substr($remote_addr, 0, 5) . $validAfterSeconds . $additionalDataToHash,
            $secret_key
        );

        return hash_equals($expected, $signature);
    }
}
