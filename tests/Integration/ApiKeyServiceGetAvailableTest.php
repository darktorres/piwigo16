<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Doctrine\DBAL\Connection;
use Piwigo\Auth\ApiKeyRepository;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Mail\MailService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;

/**
 * ApiKeyService::getAvailable() -- the only real caller is
 * PasswordController's password-reset-success email flow, whose own
 * Browser tests only ever exercise a fixture user with zero API keys (the
 * common case), so the "at least one key exists, some available/expired/
 * revoked" filtering loop itself was never exercised. Hits the
 * real service/repository directly rather than the full password-reset
 * flow -- much less setup for the same branch coverage.
 */
final class ApiKeyServiceGetAvailableTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ApiKeyService $service;

    private Connection $conn;

    private EntityManagerInterface $em;

    private int $userId;

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

        $this->conn = DbConnection::build();
        $userId = $this->conn->fetchOne("SELECT id FROM " . 'users' . " WHERE username = 'fixture_admin'");
        self::assertIsNumeric($userId);
        $this->userId = $userId;

        $this->em = EntityManagerFactory::build($this->conn);
        $mailer = Kernel::container()->get(MailService::class);
        self::assertInstanceOf(MailService::class, $mailer);
        $this->service = new ApiKeyService(
            LangTestFactory::get(),
            $mailer,
            new ApiKeyRepository($this->em),
            new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy()),
            UrlServiceTestFactory::build(),
            new SessionService($this->em->getRepository(SessionEntity::class),CurrentConfigTestFactory::get()),
            CurrentConfigTestFactory::get(),
        );

        $this->conn->executeStatement('DELETE FROM ' . 'user_auth_keys' . " WHERE user_id = ? AND key_type = 'api_key'", [$this->userId]);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . 'user_auth_keys' . " WHERE user_id = ? AND key_type = 'api_key'", [$this->userId]);
        parent::tearDown();
    }

    public function test_getAvailable_returns_false_when_the_user_has_no_api_keys(): void
    {
        self::assertFalse($this->service->getAvailable($this->userId));
    }

    public function test_getAvailable_includes_a_non_expired_non_revoked_key(): void
    {
        $created = $this->service->create($this->userId, 30, 'Available Key');

        $available = $this->service->getAvailable($this->userId);

        self::assertIsArray($available);
        self::assertCount(1, $available);
        self::assertSame($created->authKey, $available[0]->authKey);
    }

    public function test_getAvailable_excludes_a_revoked_key(): void
    {
        $created = $this->service->create($this->userId, 30, 'Revoked Key');
        $revokeResult = $this->service->revoke($this->userId, $created->authKey);
        self::assertTrue($revokeResult);

        self::assertFalse($this->service->getAvailable($this->userId));
    }

    public function test_getAvailable_excludes_an_expired_key(): void
    {
        // duration is a real `int(11) unsigned` DB column -- create()
        // itself can't be called with a negative duration (a genuine DB
        // "out of range" error, not just a hypothetical), so the only way
        // to force an already-expired key is to create a normal one, then
        // directly backdate its expired_on column (no separate "backdate"
        // API).
        $created = $this->service->create($this->userId, 30, 'Expired Key');
        $this->conn->executeStatement(
            'UPDATE ' . 'user_auth_keys' . " SET expired_on = '2000-01-01 00:00:00' WHERE auth_key = ?",
            [$created->authKey]
        );
        // insert() persisted+flushed this row through the ORM, so the raw
        // DBAL UPDATE above leaves a stale entity in the identity map --
        // clear() forces get()'s own query to read the real, just-written
        // expired_on back from the DB.
        $this->em->clear();

        self::assertFalse($this->service->getAvailable($this->userId));
    }

    public function test_getAvailable_mixes_available_and_excluded_keys_in_one_call(): void
    {
        $available = $this->service->create($this->userId, 30, 'Mixed Available Key');
        $expired = $this->service->create($this->userId, 30, 'Mixed Expired Key');
        $this->conn->executeStatement(
            'UPDATE ' . 'user_auth_keys' . " SET expired_on = '2000-01-01 00:00:00' WHERE auth_key = ?",
            [$expired->authKey]
        );
        $this->em->clear();
        $revoked = $this->service->create($this->userId, 30, 'Mixed Revoked Key');
        $this->service->revoke($this->userId, $revoked->authKey);

        $result = $this->service->getAvailable($this->userId);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame($available->authKey, $result[0]->authKey);
    }
}
