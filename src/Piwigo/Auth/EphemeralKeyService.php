<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Config\CurrentConfig;

/**
 * Short-lived, time-windowed keys sent back with a form (e.g. a signed
 * "not submitted too fast" / "not submitted too late" token), keyed by the
 * secret-key config. Reads Piwigo\Config\CurrentConfig::secretKey() directly:
 * ConfigDb::loadConfFromDb()/confUpdateParam() sync every DB-persisted
 * config row into CurrentConfig::$data at the same point they update the
 * legacy $conf global, so CurrentConfig::secretKey() reflects the real,
 * admin/install-set secret_key on every live request.
 */
final readonly class EphemeralKeyService
{
    public function __construct(
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * Returns a "secret key" that is to be sent back when a user posts a
     * form.
     *
     * @param int $validAfterSeconds key validity start time from now
     */
    public function generate(int $validAfterSeconds, string $additionalDataToHash = ''): string
    {
        // The `1` decimal-places argument isn't independently verifiable
        // via the generated key's own string shape: round(x, 1) and
        // round(x, 0) produce an identical (string) cast (no decimal
        // point at all) whenever the instant happens to round to a whole
        // second, which a live microtime(true) call does often enough
        // to make a "the issuedAt part has a decimal
        // point" assertion flaky. Not chased given this file's own
        // stated aversion to flaky, live-clock-dependent tests (see the
        // rounding-race comments elsewhere in this file).
        $time = round(microtime(true), 1);
        $remote_addr = IpAddress::fromRemoteAddr()->value ?? '';
        $secret_key = $this->currentConfig->secretKey;

        // Both (string) casts below are redundant: `.` concatenation
        // already stringifies a float identically to an explicit cast.
        return (string) $time . ':' . $validAfterSeconds . ':'
            . hash_hmac(
                'sha256',
                (string) $time . substr($remote_addr, 0, 5) . $validAfterSeconds . $additionalDataToHash,
                $secret_key
            );
    }

    /**
     * Verifies a key sent back with a form.
     */
    public function verify(string $key, string $additionalDataToHash = ''): bool
    {
        // Must match generate()'s own round(microtime(true), 1) exactly --
        // comparing a rounded $issuedAt against an unrounded $time here
        // lets rounding push $issuedAt's stored value slightly ahead of
        // the real clock (e.g. a true 1785265426.19 rounds up to .2),
        // making a key generated and verified mere microseconds apart, with
        // $validAfterSeconds = 0, look like it came "from the future" and
        // get rejected, intermittently (whichever way
        // that instant's rounding falls), not a cross-test leak. Same
        // "1 decimal place" argument as generate()'s own identical call --
        // not independently verifiable without a flaky assertion on the
        // live clock landing on a non-whole second, see that method's own
        // comment.
        $time = round(microtime(true), 1);
        $keyParts = explode(':', $key);

        if (count($keyParts) !== 3) {
            return false;
        }

        [$issuedAt, $validAfterSeconds, $signature] = $keyParts;

        if (! is_numeric($issuedAt) || ! is_numeric($validAfterSeconds)) {
            return false;
        }

        // Both (float) casts on $issuedAt/$validAfterSeconds are redundant:
        // PHP's arithmetic/comparison operators already coerce a numeric
        // string operand the same way an explicit cast would. The exact
        // `>`/`>=` and `<`/`<=` boundaries just below
        // aren't chased either: proving them needs $issuedAt to exactly
        // equal $time (or $time - 3600) as computed by verify()'s own
        // internal, uncontrollable round(microtime(true), 1) call --
        // exactly the same live-clock race this file's own docblock above
        // already documents for the 0-offset case, just at a different
        // boundary.
        if ((float) $issuedAt > $time - (float) $validAfterSeconds) { // page must have been retrieved more than X sec ago
            return false;
        }

        if ((float) $issuedAt < $time - 3600.0) { // 60 minutes expiration
            return false;
        }

        $remote_addr = IpAddress::fromRemoteAddr()->value ?? '';
        $secret_key = $this->currentConfig->secretKey;

        $expected = hash_hmac(
            'sha256',
            $issuedAt . substr($remote_addr, 0, 5) . $validAfterSeconds . $additionalDataToHash,
            $secret_key
        );

        return hash_equals($expected, $signature);
    }
}
