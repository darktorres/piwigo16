<?php

declare(strict_types=1);

// RateService calls Piwigo\Auth\AccessControl::isAuthorizeStatus()
// directly, which reads Piwigo\Users\CurrentUser -- tests below seed
// CurrentUser instead.
// CookieService::cookiePath() reads Piwigo\Core\RequestMountDepth, which
// defaults to 0, so no setup is needed here.
namespace Piwigo\Tests\Integration {

    use Override;
    use Piwigo\Core\Kernel;
    use LogicException;
    use Piwigo\Auth\AccessControl;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Rate\RateEntity;
    use Doctrine\DBAL\Connection;
    use Piwigo\Auth\CookieService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Event\Picture\UpdateRatingScore;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Rate\RateService;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Users\User;

    final class RateServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private RateService $service;

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

            $currentConfig->rateEnabled = true;
            $currentConfig->rateAnonymous = true;
            $currentConfig->rateItems = [0, 1, 2, 3, 4, 5];
            $currentConfig->guestAccess = true;
            CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 3, 'status' => 'normal']));
            $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
            unset($_COOKIE['pwg_anonymous_rater']);

            $this->conn = DbConnection::build();
            $accessControl = Kernel::container()->get(AccessControl::class);
            if (! $accessControl instanceof AccessControl) {
                throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
            }
            $this->service = new RateService($accessControl, EntityManagerFactory::build($this->conn)->getRepository(RateEntity::class), new CookieService(), EventDispatcherTestFactory::get(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
        }

        public function test_rate_returns_false_for_a_null_rate(): void
        {
            self::assertFalse($this->service->rate(5, null));
        }

        public function test_rate_returns_false_when_rating_is_disabled(): void
        {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->rateEnabled = false;

            self::assertFalse($this->service->rate(5, 3));
        }

        public function test_rate_returns_false_for_a_non_digit_rate(): void
        {
            self::assertFalse($this->service->rate(5, 'not-a-number'));
        }

        public function test_rate_returns_false_for_a_rate_value_not_in_rate_items(): void
        {
            self::assertFalse($this->service->rate(5, 99));
        }

        public function test_rate_returns_false_for_an_anonymous_user_when_rate_anonymous_is_disabled(): void
        {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->rateAnonymous = false;
            CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 2, 'status' => 'guest']));

            self::assertFalse($this->service->rate(5, 3));
        }

        public function test_rate_inserts_a_new_rate_and_recomputes_the_score(): void
        {
            try {
                $result = $this->service->rate(5, 4);

                self::assertIsArray($result);
                self::assertSame(4.0, $result['average']);
                self::assertSame(1, $result['count']);
                self::assertSame('4', $this->fetchRate(5, 3));
            } finally {
                $this->conn->createQueryBuilder()
                    ->delete('rate')
                    ->where('element_id = 5')
                    ->andWhere('user_id = 3')
                    ->executeStatement();
                $this->conn->createQueryBuilder()
                    ->update('images')
                    ->set('rating_score', 'NULL')
                    ->where('id = 5')
                    ->executeStatement();
            }
        }

        public function test_rate_replaces_an_existing_rate_from_the_same_user(): void
        {
            try {
                $this->service->rate(5, 2);
                $result = $this->service->rate(5, 5);

                self::assertIsArray($result);
                self::assertSame(1, $result['count'], 'the second rate must replace, not add to, the first');
                self::assertSame('5', $this->fetchRate(5, 3));
            } finally {
                $this->conn->createQueryBuilder()
                    ->delete('rate')
                    ->where('element_id = 5')
                    ->andWhere('user_id = 3')
                    ->executeStatement();
                $this->conn->createQueryBuilder()
                    ->update('images')
                    ->set('rating_score', 'NULL')
                    ->where('id = 5')
                    ->executeStatement();
            }
        }

        public function test_rate_for_an_anonymous_user_sets_the_cookie_and_records_the_ip(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 2, 'status' => 'guest']));

            try {
                $result = $this->service->rate(5, 3);

                self::assertIsArray($result);
                self::assertSame('10.20.30', $_COOKIE['pwg_anonymous_rater'] ?? null);

                $anonymousId = $this->conn->createQueryBuilder()
                    ->select('anonymous_id')
                    ->from('rate')
                    ->where('element_id = 5')
                    ->andWhere('user_id = 2')
                    ->executeQuery()
                    ->fetchOne();
                // the rate table's anonymous_id stores the TRIMMED (3-octet)
                // IP throughout -- unlike Comment's anonymous_id (which
                // stores the full IP and trims only for a LIKE prefix),
                // rate_picture()'s original code uses a single trimmed
                // variable for the cookie, the migration queries, AND the
                // stored column.
                self::assertSame('10.20.30', $anonymousId);
            } finally {
                $this->conn->createQueryBuilder()
                    ->delete('rate')
                    ->where('element_id = 5')
                    ->andWhere('user_id = 2')
                    ->executeStatement();
                $this->conn->createQueryBuilder()
                    ->update('images')
                    ->set('rating_score', 'NULL')
                    ->where('id = 5')
                    ->executeStatement();
            }
        }

        public function test_update_rating_score_returns_zero_count_for_an_element_with_no_rates(): void
        {
            self::assertSame(
                ['score' => null, 'average' => null, 'count' => 0],
                $this->service->updateRatingScore(5)
            );
        }

        public function test_update_rating_score_returns_a_plugin_handlers_replacement_verbatim(): void
        {
            $override = ['score' => 999, 'average' => 9.9, 'count' => 42];
            EventDispatcherTestFactory::get()->addTypedHandler(
                UpdateRatingScore::class,
                static fn (UpdateRatingScore $event): UpdateRatingScore => new UpdateRatingScore($override, $event->elementId)
            );

            try {
                self::assertSame($override, $this->service->updateRatingScore(5));
            } finally {
                EventDispatcherTestFactory::get()->reset();
            }
        }

        /**
         * Simulates a returning anonymous rater whose IP address changed
         * since their last visit (cookie still says the old, saved
         * anonymous_id) while ALSO already having a rate stored under the
         * exact new ip-derived anonymous_id for a different element (e.g.
         * a prior visit from the same subnet) -- the
         * `$existingElementIds !== []` branch deletes that stale
         * old-anonymous_id row before reassignAnonymousId() runs, avoiding
         * a primary-key collision on (element_id, user_id, anonymous_id).
         * Only the row already at the new anonymous_id survives.
         */
        public function test_rate_for_a_returning_anonymous_user_deletes_the_stale_duplicate_before_reassigning(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 2, 'status' => 'guest']));
            $_COOKIE['pwg_anonymous_rater'] = '99.99.99';

            $this->conn->insert('rate', ['element_id' => 4, 'user_id' => 2, 'anonymous_id' => '99.99.99', 'rate' => 2, 'date' => '2026-08-01']);
            $this->conn->insert('rate', ['element_id' => 4, 'user_id' => 2, 'anonymous_id' => '10.20.30', 'rate' => 1, 'date' => '2026-08-01']);

            try {
                $result = $this->service->rate(5, 3);

                self::assertIsArray($result);
                self::assertSame(['10.20.30'], $this->fetchAnonymousIdsForElement(4, 2));
                self::assertSame('10.20.30', $_COOKIE['pwg_anonymous_rater']);
            } finally {
                $this->conn->createQueryBuilder()->delete('rate')->where('element_id = 4')->andWhere('user_id = 2')->executeStatement();
                $this->conn->createQueryBuilder()->delete('rate')->where('element_id = 5')->andWhere('user_id = 2')->executeStatement();
                $this->conn->createQueryBuilder()->update('images')->set('rating_score', 'NULL')->where('id = 5')->executeStatement();
            }
        }

        /**
         * @return list<string>
         */
        private function fetchAnonymousIdsForElement(int $elementId, int $userId): array
        {
            $values = $this->conn->createQueryBuilder()
                ->select('anonymous_id')
                ->from('rate')
                ->where('element_id = :elementId')
                ->andWhere('user_id = :userId')
                ->setParameter('elementId', $elementId)
                ->setParameter('userId', $userId)
                ->executeQuery()
                ->fetchFirstColumn();

            $result = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $result[] = $value;
                }
            }

            sort($result);

            return $result;
        }

        private function fetchRate(int $elementId, int $userId): ?string
        {
            $value = $this->conn->createQueryBuilder()
                ->select('rate')
                ->from('rate')
                ->where('element_id = :elementId')
                ->andWhere('user_id = :userId')
                ->setParameter('elementId', $elementId)
                ->setParameter('userId', $userId)
                ->executeQuery()
                ->fetchOne();

            return is_numeric($value) ? (string) (int) $value : null;
        }
    }
}
