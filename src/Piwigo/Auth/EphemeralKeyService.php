<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Short-lived, time-windowed keys sent back with a form (e.g. a signed
 * "not submitted too fast" / "not submitted too late" token), keyed by the
 * secret-key config. Reads Piwigo\Config\Config::secretKey() directly --
 * safe since Legacy Coupling Retirement Track A batch A4's ConfigDb fix:
 * ConfigDb::loadConfFromDb()/confUpdateParam() now sync every DB-persisted
 * config row into Config::$data at the same point they update the legacy
 * $conf global, so Config::secretKey() reflects the real, admin/install-set
 * secret_key on every live request (previously it did not -- see
 * Piwigo\Csrf\CsrfService's own docblock for the historical P18 incident
 * this same gap caused there).
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
        $time = round(microtime(true), 1);
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        $remote_addr = is_string($remote_addr) ? $remote_addr : '';
        $secret_key = \Piwigo\Config\Config::secretKey();
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
        $secret_key = \Piwigo\Config\Config::secretKey();
        $secret_key = is_scalar($secret_key) ? (string) $secret_key : '';

        $expected = hash_hmac(
            'sha256',
            $issuedAt . substr($remote_addr, 0, 5) . $validAfterSeconds . $additionalDataToHash,
            $secret_key
        );

        return hash_equals($expected, $signature);
    }
}
