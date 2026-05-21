<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see NotificationRepository}. Locks in
 * the F1 fix: `N.enabled = 1` (integer) selects the enabled subscriber.
 * Pre-F1 the code wrote the literal string 'true', which MySQL coerced
 * to 0, so the send-notifications path silently selected the *disabled*
 * subscribers.
 *
 * getUserNotifications() reads Config::userFields(); seed the minimum
 * values needed via Config::loadArray() in setUp.
 */
final class NotificationRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private NotificationRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new NotificationRepository($this->conn, 'piwigo_');

        // user_fields drives the JOIN columns in getUserNotifications.
        Config::loadArray([
            'user_fields' => [
                'id'       => 'id',
                'username' => 'username',
                'email'    => 'mail_address',
            ],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
        $this->conn->close();
    }

    public function test_insertSubscriptionsBatch_round_trips_via_checkKeyExists(): void
    {
        $this->repo->insertSubscriptionsBatch([
            ['user_id' => 3, 'check_key' => 'abc12345', 'enabled' => 1],
        ]);

        self::assertTrue($this->repo->checkKeyExists('abc12345'));
        self::assertFalse($this->repo->checkKeyExists('nonexistent'));
    }

    /**
     * F1 regression guard: writing `enabled = 1` (integer) must produce a
     * row that the `action = 'send'` query (which filters on `enabled = 1`)
     * selects. Pre-F1 the writer emitted the string 'true', which the
     * TINYINT(1) column coerced to 0, so this query returned 0 rows.
     */
    public function test_getUserNotifications_send_selects_enabled_rows(): void
    {
        $this->repo->insertSubscriptionsBatch([
            ['user_id' => 1, 'check_key' => 'enabled1', 'enabled' => 1],
            ['user_id' => 3, 'check_key' => 'disabled3', 'enabled' => 0],
        ]);

        $rows = $this->repo->getUserNotifications('send');

        // user 1 has a mail_address in the fixture, user 3 doesn't —
        // getUserNotifications('send') also filters mail_address IS NOT NULL.
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->userId);
        self::assertSame(true, $rows[0]->enabled);
    }

    public function test_getUserNotifications_subscribe_returns_all_with_filter(): void
    {
        $this->repo->insertSubscriptionsBatch([
            ['user_id' => 1, 'check_key' => 'k1', 'enabled' => 1],
            ['user_id' => 3, 'check_key' => 'k3', 'enabled' => 0],
        ]);

        $enabled  = $this->repo->getUserNotifications('subscribe', [], true);
        $disabled = $this->repo->getUserNotifications('subscribe', [], false);

        self::assertCount(1, $enabled);
        self::assertCount(1, $disabled);
        self::assertSame(true, $enabled[0]->enabled);
        self::assertSame(false, $disabled[0]->enabled);
    }

    public function test_setEnabledByCheckKeysBatch_flips_the_flag(): void
    {
        $this->repo->insertSubscriptionsBatch([
            ['user_id' => 1, 'check_key' => 'flip-me', 'enabled' => 1],
        ]);

        $this->repo->setEnabledByCheckKeysBatch([
            ['check_key' => 'flip-me', 'enabled' => 0],
        ]);

        $rows = $this->repo->getUserNotifications('subscribe', ['flip-me']);
        self::assertCount(1, $rows);
        self::assertSame(false, $rows[0]->enabled);
    }
}
