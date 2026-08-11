<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * PasswordService::hash()/verify()/verifyLegacyPhpass() use native
 * password_hash()/password_verify() (bcrypt). verify() also
 * accepts a legacy phpass ($P$/$H$-prefixed) hash -- this codebase's own
 * older format, still present in installs/fixtures created before this
 * migration -- rehashing it to bcrypt on successful verify. The old MD5/
 * $conf['pass_convert'] fallback (bridging from *upstream* Piwigo's
 * pre-2.5 format) is gone: this fork has no in-place upgrade from
 * upstream (docs/REFERENCE.md's "Clean fork, no in-place upgrade from
 * upstream Piwigo" decision).
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

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        // A fresh, all-defaults DeploymentPolicy (externalAuthentification
        // false) -- no test in this file needs a non-default policy.
        $this->service = new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy());
    }

    public function testHashProducesABcryptHash(): void
    {
        $hash = $this->service->hash('correcthorsebatterystaple');

        self::assertStringStartsWith('$2y$', $hash);
    }

    public function testVerifyAcceptsItsOwnHashAndRejectsAWrongPassword(): void
    {
        $hash = $this->service->hash('hunter2');

        self::assertTrue($this->service->verify('hunter2', $hash));
        self::assertFalse($this->service->verify('wrong', $hash));
    }

    public function testHashReadsCostFromTestMode(): void
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

    public function testVerifyAcceptsALegacyPhpassHashAndRejectsAWrongPassword(): void
    {
        // Precomputed with a fixed salt via the (now-removed) vendored
        // phpass library, cross-checked byte-for-byte against
        // verifyLegacyPhpass()'s extraction.
        $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';

        self::assertTrue($this->service->verify('legacyPhpassPassw0rd!', $phpassHash));
        self::assertFalse($this->service->verify('wrongpassword', $phpassHash));
    }

    public function testVerifyAcceptsALegacyPhpassHashWithoutTouchingTheDb(): void
    {
        // No $userId passed: verify()'s `$userId === null` branch returns
        // true immediately, before reaching the rehash write.
        $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';

        self::assertTrue($this->service->verify('legacyPhpassPassw0rd!', $phpassHash));
    }

    public function testVerifyRehashesALegacyPhpassHashWhenAUserIdIsGiven(): void
    {
        $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';

        self::assertTrue($this->service->verify('legacyPhpassPassw0rd!', $phpassHash, 3));

        $newHash = $this->conn->createQueryBuilder()
            ->select('password')
            ->from('users')
            ->where('id = :id')
            ->setParameter('id', 3)
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($newHash);
        self::assertStringStartsWith('$2y$', $newHash);
        self::assertTrue($this->service->verify('legacyPhpassPassw0rd!', $newHash));

        // Restore the fixture row for later tests in this run.
        $this->conn->executeStatement(
            "UPDATE users SET password = '\$2y\$04\$xGZfKCZNROjaLMYm0nOuKugaMf/IEPCzJsuk9lpjDwZrK.RZLusGy' WHERE id = 3"
        );
    }

    public function testVerifyLegacyPhpassRejectsMalformedHashes(): void
    {
        self::assertFalse($this->service->verifyLegacyPhpass('anything', 'not-a-phpass-hash'));
        self::assertFalse($this->service->verifyLegacyPhpass('anything', '$2y$04$tooshortforbcryptbutwrongprefix'));
    }

    public function testVerifyLegacyPhpassRejectsACorrectlySizedHashWithTheWrongPrefix(): void
    {
        // Exactly 34 chars (passes the length guard), but the first 3
        // chars are neither '$P$' nor '$H$' -- a distinct rejection branch
        // from the "wrong length" case above (real phpass hashes always
        // use one of those 2 prefixes).
        $wrongPrefixHash = '$Q$5testsalt/.6ES3kLR5L.kwZkBtHpD/';
        // Not testing verifyLegacyPhpass() -- testing this fixture itself:
        // if a future edit changes its length, it would silently start
        // exercising the "wrong length" branch from the test above instead
        // of the "wrong prefix" branch this test is named for.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(34, strlen($wrongPrefixHash));

        self::assertFalse($this->service->verifyLegacyPhpass('legacyPhpassPassw0rd!', $wrongPrefixHash));
    }

    public function testVerifyLegacyPhpassRejectsAHashWithAnOutOfRangeCostFactor(): void
    {
        // Correct '$P$' prefix and 34-char length, but hash[3] (the cost
        // log2 character) is '.' -- itoa64 index 0, below the 7-30 valid
        // range real phpass costs use.
        $outOfRangeCostHash = '$P$.testsalt/.6ES3kLR5L.kwZkBtHpD/';
        // Same fixture-integrity rationale as the wrong-prefix test above.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(34, strlen($outOfRangeCostHash));

        self::assertFalse($this->service->verifyLegacyPhpass('legacyPhpassPassw0rd!', $outOfRangeCostHash));
    }
}
