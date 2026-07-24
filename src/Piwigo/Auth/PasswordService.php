<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Password hashing/verification: native password_hash()/password_verify()
 * (bcrypt) as of P5, with a legacy phpass ($P$/$H$-prefixed) hash still
 * accepted for pre-P5 installs -- rehashed to bcrypt on successful verify.
 * The old MD5/$conf['pass_convert'] tier (bridging from *upstream* Piwigo's
 * pre-2.5 format) is removed outright: this fork has no in-place upgrade
 * from upstream (docs/adr/0002-clean-fork-no-inplace-upgrade.md).
 *
 * Constructor-injects PasswordRepository, plain constructor injection (same
 * shape as PermalinkService/GroupService).
 */
final readonly class PasswordService
{
    public function __construct(
        private PasswordRepository $repo,
    ) {}

    /**
     * Hashes a password with native password_hash() (bcrypt).
     */
    public function hash(
        #[\SensitiveParameter]
        string $password
    ): string {
        $cost = \Piwigo\Core\Env::testModeIsActive() ? 4 : 13;

        return password_hash($password, \PASSWORD_BCRYPT, [
            'cost' => $cost,
        ]);
    }

    /**
     * Verifies a password against a stored hash using native
     * password_verify() (bcrypt). Also accepts a legacy phpass
     * ($P$/$H$-prefixed) hash, rehashing it to bcrypt on successful verify
     * -- the same forward-migration pattern SEC-41/P28 reuses later for
     * bcrypt->Argon2id.
     *
     * @param int|null $userId only useful to update the hash format in database
     */
    public function verify(
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        string $hash,
        ?int $userId = null
    ): bool {

        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            if (! $this->verifyLegacyPhpass($password, $hash)) {
                return false;
            }

            if ($userId === null || \Piwigo\Config\DeploymentPolicy::current()->externalAuthentification) {
                return true;
            }

            $this->repo->updatePasswordHash($userId, $this->hash($password));

            return true;
        }

        return password_verify($password, $hash);
    }

    /**
     * Verifies a password against a legacy phpass ($P$/$H$-prefixed) hash.
     * Minimal extraction of the vendored phpass library's core check
     * algorithm (include/passwordhash.class.php, removed in this commit) --
     * kept only long enough to rehash existing installs' passwords forward
     * to bcrypt in verify(); never used to generate new hashes.
     * @see http://www.openwall.com/phpass/ (public domain, Solar Designer)
     *
     * @param string $hash phpass $P$/$H$-prefixed hash
     */
    public function verifyLegacyPhpass(
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        string $hash
    ): bool {
        /** @var string */
        static $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

        if (strlen($password) > 4096 || strlen($hash) !== 34) {
            return false;
        }

        $id = substr($hash, 0, 3);
        if ($id !== '$P$' && $id !== '$H$') {
            return false;
        }

        $count_log2 = strpos($itoa64, $hash[3]);
        if ($count_log2 === false || $count_log2 < 7 || $count_log2 > 30) {
            return false;
        }
        $count = 1 << $count_log2;

        $salt = substr($hash, 4, 8);
        if (strlen($salt) !== 8) {
            return false;
        }

        $computed = md5($salt . $password, true);
        do {
            $computed = md5($computed . $password, true);
        } while ((bool) --$count);

        // phpass's custom base64-like encoding (not RFC 4648)
        $output = substr($hash, 0, 12);
        $i = 0;
        do {
            $value = ord($computed[$i++]);
            $output .= $itoa64[$value & 0x3F];
            if ($i < 16) {
                $value |= ord($computed[$i]) << 8;
            }
            $output .= $itoa64[($value >> 6) & 0x3F];
            if ($i++ >= 16) {
                break;
            }
            if ($i < 16) {
                $value |= ord($computed[$i]) << 16;
            }
            $output .= $itoa64[($value >> 12) & 0x3F];
            // Byte-for-byte port of the canonical phpass encoding loop
            // (openwall.com/phpass) — this bounds check is provably redundant
            // for our always-exactly-16-byte $computed (the loop's first
            // `$i++ >= 16` check above always catches that boundary first), but
            // is kept to match the reference algorithm exactly rather than
            // hand-trim security-sensitive, publicly-audited crypto code.
            // @phpstan-ignore greaterOrEqual.alwaysFalse
            if ($i++ >= 16) {
                break;
            }
            $output .= $itoa64[($value >> 18) & 0x3F];
        } while ($i < 16);

        return hash_equals($hash, $output);
    }
}
