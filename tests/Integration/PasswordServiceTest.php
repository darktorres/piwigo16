<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * PasswordService::hash()/verify()/verifyLegacyPhpass() use native
 * password_hash()/password_verify() (bcrypt) as of P5. verify() also
 * accepts a legacy phpass ($P$/$H$-prefixed) hash -- this codebase's own
 * pre-P5 format, still present in installs/fixtures created before this
 * migration -- rehashing it to bcrypt on successful verify. The old MD5/
 * $conf['pass_convert'] fallback (bridging from *upstream* Piwigo's
 * pre-2.5 format) is gone: this fork has no in-place upgrade from
 * upstream (docs/REFERENCE.md's ADR-0002).
 *
 * Moved from tests/Unit/PasswordHashTest.php (a standalone-stub-loaded
 * Unit test against the free functions) once those functions became thin
 * delegates to this class: the rehash path now does a real DBAL write via
 * PasswordRepository, which a Unit test's DB-less stubs can no longer
 * intercept.
 *
 * verifyLegacyPhpass()'s own `strlen($salt) !== 8` guard (right after the
 * cost-factor check) is not chased here: $salt is `substr($hash, 4, 8)`,
 * and the length guard a few lines above already fixes $hash at exactly
 * 34 chars -- positions 4..11 always exist by then, so that substr() call
 * can never return fewer than 8 chars. Genuinely dead code given the
 * method's own earlier guard, not overlooked. (Its own final encoding-loop
 * break at line ~141 is separately, explicitly documented in the source
 * with a phpstan-ignore tag for the identifier greaterOrEqual.alwaysFalse,
 * same "provably redundant" reasoning.)
 */
final class PasswordServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PasswordService $service;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        // A fresh, all-defaults DeploymentPolicy (externalAuthentification
        // false) -- no test in this file needs a non-default policy.
        $this->service = new PasswordService(new PasswordRepository($this->conn), new DeploymentPolicy());
    }

    public function test_hash_produces_a_bcrypt_hash(): void
    {
        $hash = $this->service->hash('correcthorsebatterystaple');

        self::assertStringStartsWith('$2y$', $hash);
    }

    public function test_verify_accepts_its_own_hash_and_rejects_a_wrong_password(): void
    {
        $hash = $this->service->hash('hunter2');

        self::assertTrue($this->service->verify('hunter2', $hash));
        self::assertFalse($this->service->verify('wrong', $hash));
    }

    public function test_hash_reads_cost_from_test_mode(): void
    {
        // pwg_test_mode_is_active() reads the X-Piwigo-Env header
        // (include/env.inc.php); PHP_SAPI is 'cli' here, so it only needs
        // the header present, matching tests/bootstrap.php's convention
        // for the rest of the suite.
        $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';

        $hash = $this->service->hash('costcheck');

        // bcrypt hash format: $2y$<cost>$<22-char-salt><31-char-hash>
        self::assertStringStartsWith('$2y$04$', $hash);
    }

    public function test_verify_accepts_a_legacy_phpass_hash_and_rejects_a_wrong_password(): void
    {
        // Precomputed with a fixed salt via the (now-removed) vendored
        // phpass library, cross-checked byte-for-byte against
        // verifyLegacyPhpass()'s extraction.
        $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';

        self::assertTrue($this->service->verify('legacyPhpassPassw0rd!', $phpassHash));
        self::assertFalse($this->service->verify('wrongpassword', $phpassHash));
    }

    public function test_verify_accepts_a_legacy_phpass_hash_without_touching_the_db(): void
    {
        // No $userId passed: verify()'s `$userId === null` branch returns
        // true immediately, before reaching the rehash write.
        $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';

        self::assertTrue($this->service->verify('legacyPhpassPassw0rd!', $phpassHash));
    }

    public function test_verify_rehashes_a_legacy_phpass_hash_when_a_user_id_is_given(): void
    {
        $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';

        self::assertTrue($this->service->verify('legacyPhpassPassw0rd!', $phpassHash, 3));

        $newHash = $this->conn->createQueryBuilder()
            ->select('password')
            ->from(Tables::users())
            ->where('id = :id')
            ->setParameter('id', 3)
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($newHash);
        self::assertStringStartsWith('$2y$', $newHash);
        self::assertTrue($this->service->verify('legacyPhpassPassw0rd!', $newHash));

        // Restore the fixture row for later tests in this run.
        $this->conn->executeStatement(
            'UPDATE ' . Tables::users() . " SET password = '\$2y\$04\$xGZfKCZNROjaLMYm0nOuKugaMf/IEPCzJsuk9lpjDwZrK.RZLusGy' WHERE id = 3"
        );
    }

    public function test_verify_legacy_phpass_rejects_malformed_hashes(): void
    {
        self::assertFalse($this->service->verifyLegacyPhpass('anything', 'not-a-phpass-hash'));
        self::assertFalse($this->service->verifyLegacyPhpass('anything', '$2y$04$tooshortforbcryptbutwrongprefix'));
    }

    public function test_verify_legacy_phpass_rejects_a_correctly_sized_hash_with_the_wrong_prefix(): void
    {
        // Exactly 34 chars (passes the length guard), but the first 3
        // chars are neither '$P$' nor '$H$' -- a distinct rejection branch
        // from the "wrong length" case above (real phpass hashes always
        // use one of those 2 prefixes).
        $wrongPrefixHash = '$Q$5testsalt/.6ES3kLR5L.kwZkBtHpD/';
        self::assertSame(34, strlen($wrongPrefixHash));

        self::assertFalse($this->service->verifyLegacyPhpass('legacyPhpassPassw0rd!', $wrongPrefixHash));
    }

    public function test_verify_legacy_phpass_rejects_a_hash_with_an_out_of_range_cost_factor(): void
    {
        // Correct '$P$' prefix and 34-char length, but hash[3] (the cost
        // log2 character) is '.' -- itoa64 index 0, below the 7-30 valid
        // range real phpass costs use.
        $outOfRangeCostHash = '$P$.testsalt/.6ES3kLR5L.kwZkBtHpD/';
        self::assertSame(34, strlen($outOfRangeCostHash));

        self::assertFalse($this->service->verifyLegacyPhpass('legacyPhpassPassw0rd!', $outOfRangeCostHash));
    }
}
