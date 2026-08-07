<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Doctrine\DBAL\Connection;
use Piwigo\Auth\ApiKeyRepository;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\MailerInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;

/**
 * Records every mail() call for assertion instead of actually delivering
 * anything -- matches this repo's established "fake the boundary
 * interface" convention (e.g. CategoryServiceFakeHtmlRendererDeniesAccess)
 * rather than exercising the real MailService/BoundedSendmailTransport
 * stack, which is out of scope for ApiKeyService's own branch coverage.
 */
final class ApiKeyServiceLifecycleTestSpyMailer implements MailerInterface
{
    /** @var list<array{to: string|array<int|string, mixed>, args: array<string, mixed>}> */
    public array $calls = [];

    #[Override]
    public function mail(string|array $to, array $args = [], array $tpl = []): bool
    {
        $this->calls[] = ['to' => $to, 'args' => $args];

        return true;
    }

    #[Override]
    public function mailNotificationAdmins(string|array $subject, string|array $content, bool $sendTechnicalDetails = true, int|string|null $groupId = null): bool
    {
        throw new LogicException('not used by ApiKeyService');
    }
}

/**
 * Closes ApiKeyService gaps ApiKeyServiceGetAvailableTest.php doesn't touch:
 * revoke()/edit()'s "key not found" branches, get()'s 3-way expiration
 * message branch (days/hours/minutes -- that file's own fixture keys are
 * always created with a whole-day duration, so hours/minutes never fire),
 * and notifyExpiration()'s plural-days wording.
 *
 * get()'s own `str2DateTime() failed` \Exception guard is not chased here:
 * expired_on/created_on are real `datetime NOT NULL` columns. str2DateTime()
 * (no explicit $format given, so its "unknown date format" branch runs) also
 * returns false when strtok()-ing the input on `- :/` yields fewer than 3
 * parts, not just for `false`/''/0/'0' input -- but MySQL only ever hands
 * back a `datetime` column's value in canonical 'Y-m-d H:i:s' form, which
 * always tokenizes into exactly 6 parts, and (confirmed live: `UPDATE ...
 * SET expired_on = 'corrupted'` against this same column) strict SQL mode
 * (STRICT_TRANS_TABLES) rejects any raw write that isn't a valid datetime
 * literal with `ERROR 1292 Incorrect datetime value` before it ever reaches
 * PHP. Forcing either false-returning path would need a value the schema
 * itself won't store. Left uncovered as a genuinely unreachable defensive
 * guard, not overlooked.
 */
final class ApiKeyServiceLifecycleTest extends IntegrationTestCase
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

        CurrentConfigTestFactory::get()->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $userId = $this->conn->fetchOne("SELECT id FROM " . Tables::users() . " WHERE username = 'fixture_admin'");
        self::assertIsNumeric($userId);
        $this->userId = (int) $userId;

        $this->em = EntityManagerFactory::build($this->conn);
        $this->service = new ApiKeyService(
            LangTestFactory::get(),
            new ApiKeyServiceLifecycleTestSpyMailer(),
            new ApiKeyRepository($this->em),
            new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy()),
            UrlServiceTestFactory::build(),
            new SessionService($this->em->getRepository(SessionEntity::class),CurrentConfigTestFactory::get()),
            CurrentConfigTestFactory::get(),
        );

        $this->conn->executeStatement('DELETE FROM ' . Tables::userAuthKeys() . " WHERE user_id = ? AND key_type = 'api_key'", [$this->userId]);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::userAuthKeys() . " WHERE user_id = ? AND key_type = 'api_key'", [$this->userId]);
        parent::tearDown();
    }

    public function test_revoke_returns_a_not_found_message_for_an_unknown_key(): void
    {
        $result = $this->service->revoke($this->userId, 'pkid-does-not-exist');

        self::assertSame('API Key not found', $result);
    }

    public function test_revoke_returns_a_not_found_message_when_the_key_belongs_to_a_different_user(): void
    {
        $created = $this->service->create($this->userId, 30, 'Owned By Admin');

        // 4 is fixture user 'power_user' -- countByAuthKeyAndUser() scopes
        // by (auth_key, user_id) together, so a real key looked up under
        // the wrong owner is indistinguishable from a nonexistent one.
        $result = $this->service->revoke(4, $created['auth_key']);

        self::assertSame('API Key not found', $result);
    }

    public function test_edit_returns_a_not_found_message_for_an_unknown_key(): void
    {
        $result = $this->service->edit($this->userId, 'pkid-does-not-exist', 'New Name');

        self::assertSame('API Key not found', $result);
    }

    public function test_edit_returns_true_and_persists_the_new_name_for_a_real_key(): void
    {
        $created = $this->service->create($this->userId, 30, 'Original Name');

        $result = $this->service->edit($this->userId, $created['auth_key'], 'Renamed Key');

        self::assertTrue($result);

        $available = $this->service->getAvailable($this->userId);
        self::assertIsArray($available);
        self::assertSame('Renamed Key', $available[0]['apikey_name']);
    }

    public function test_get_reports_an_hours_only_expiration_message_for_a_key_expiring_within_the_same_day(): void
    {
        $created = $this->service->create($this->userId, 30, 'Hours Left Key');
        // PIWIGO_TEST_NOW freezes Env::now() at 2026-08-01 00:00:00 -- 3
        // hours ahead keeps DateHelper::dateDiff()'s ->days at 0 so the
        // hours branch fires, not the days one.
        $this->conn->executeStatement(
            'UPDATE ' . Tables::userAuthKeys() . " SET expired_on = '2026-08-01 03:00:00' WHERE auth_key = ?",
            [$created['auth_key']]
        );
        $this->em->clear();

        $keys = $this->service->get($this->userId);
        self::assertIsArray($keys);
        $key = self::findKeyByAuthKey($keys, $created['auth_key']);

        self::assertFalse($key['is_expired']);
        self::assertSame('3 hours', $key['expiration']);
    }

    public function test_get_reports_a_minutes_only_expiration_message_for_a_key_expiring_within_the_hour(): void
    {
        $created = $this->service->create($this->userId, 30, 'Minutes Left Key');
        // 45 minutes ahead of the frozen 2026-08-01 00:00:00 "now" -- both
        // ->days and ->h stay 0, so the minutes branch fires.
        $this->conn->executeStatement(
            'UPDATE ' . Tables::userAuthKeys() . " SET expired_on = '2026-08-01 00:45:00' WHERE auth_key = ?",
            [$created['auth_key']]
        );
        $this->em->clear();

        $keys = $this->service->get($this->userId);
        self::assertIsArray($keys);
        $key = self::findKeyByAuthKey($keys, $created['auth_key']);

        self::assertFalse($key['is_expired']);
        self::assertSame('45 minutes', $key['expiration']);
    }

    public function test_notify_expiration_uses_the_plural_days_wording_when_more_than_one_day_remains(): void
    {
        $mailer = new ApiKeyServiceLifecycleTestSpyMailer();
        $service = new ApiKeyService(
            LangTestFactory::get(),
            $mailer,
            new ApiKeyRepository($this->em),
            new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy()),
            UrlServiceTestFactory::build(),
            new SessionService($this->em->getRepository(SessionEntity::class),CurrentConfigTestFactory::get()),
            CurrentConfigTestFactory::get(),
        );

        $result = $service->notifyExpiration('fixture_admin', 'fixture_admin@example.test', 5);

        self::assertTrue($result);
        self::assertCount(1, $mailer->calls);
        self::assertSame('fixture_admin@example.test', $mailer->calls[0]['to']);
        $content = $mailer->calls[0]['args']['content'];
        self::assertIsString($content);
        self::assertStringContainsString('Your API key will expire in 5 days.', $content);
        self::assertStringNotContainsString('will expire in 5 day.', $content);
    }

    public function test_notify_expiration_uses_the_singular_day_wording_when_one_day_or_less_remains(): void
    {
        $mailer = new ApiKeyServiceLifecycleTestSpyMailer();
        $service = new ApiKeyService(
            LangTestFactory::get(),
            $mailer,
            new ApiKeyRepository($this->em),
            new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy()),
            UrlServiceTestFactory::build(),
            new SessionService($this->em->getRepository(SessionEntity::class),CurrentConfigTestFactory::get()),
            CurrentConfigTestFactory::get(),
        );

        $result = $service->notifyExpiration('fixture_admin', 'fixture_admin@example.test', 1);

        self::assertTrue($result);
        $content = $mailer->calls[0]['args']['content'];
        self::assertIsString($content);
        self::assertStringContainsString('Your API key will expire in 1 day.', $content);
    }

    /**
     * @param list<array{auth_key: string, expiration: string, is_expired: bool, ...}> $keys
     * @return array{auth_key: string, expiration: string, is_expired: bool, ...}
     */
    private static function findKeyByAuthKey(array $keys, string $authKey): array
    {
        foreach ($keys as $key) {
            if ($key['auth_key'] === $authKey) {
                return $key;
            }
        }

        self::fail('Expected auth_key ' . $authKey . ' to be present in get() results');
    }
}
