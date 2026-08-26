<?php

declare(strict_types=1);

// RateService calls Piwigo\Auth\AccessControl::isAuthorizeStatus()
// directly, which reads Piwigo\Users\CurrentUser -- tests below seed
// CurrentUser instead.
// CookieService::cookiePath() reads Piwigo\Core\RequestMountDepth, which
// defaults to 0, so no setup is needed here.

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Auth\AccessControl;
    use Piwigo\Auth\CookieService;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\Kernel;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Db\TypedRepository;
    use Piwigo\Rate\Projection\RatingScoreSummary;
    use Piwigo\Rate\RateEntity;
    use Piwigo\Rate\RateRepository;
    use Piwigo\Rate\RateService;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\DbTransactionTestOverride;
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

            // PILOT (transaction-wrapping rollout): begin before any container
            // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
            // comment for the full reasoning.
            DbTransactionTestOverride::begin();

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
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 3,
                'status' => 'normal',
            ]));
            $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
            unset($_COOKIE['pwg_anonymous_rater']);

            $this->conn = DbConnection::build();
            $accessControl = Kernel::container()->get(AccessControl::class);
            if (! $accessControl instanceof AccessControl) {
                throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
            }
            $this->service = new RateService($accessControl, TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(RateEntity::class), RateRepository::class), new CookieService(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
        }

        #[Override]
        protected function tearDown(): void
        {
            DbTransactionTestOverride::rollback();
            parent::tearDown();
        }

        public function testRateReturnsFalseForANullRate(): void
        {
            self::assertFalse($this->service->rate(5, null, EntityManagerFactory::build($this->conn)));
        }

        public function testRateReturnsFalseWhenRatingIsDisabled(): void
        {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->rateEnabled = false;

            self::assertFalse($this->service->rate(5, 3, EntityManagerFactory::build($this->conn)));
        }

        public function testRateReturnsFalseForANonDigitRate(): void
        {
            self::assertFalse($this->service->rate(5, 'not-a-number', EntityManagerFactory::build($this->conn)));
        }

        public function testRateReturnsFalseForARateValueNotInRateItems(): void
        {
            self::assertFalse($this->service->rate(5, 99, EntityManagerFactory::build($this->conn)));
        }

        public function testRateReturnsFalseForAnAnonymousUserWhenRateAnonymousIsDisabled(): void
        {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->rateAnonymous = false;
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 2,
                'status' => 'guest',
            ]));

            self::assertFalse($this->service->rate(5, 3, EntityManagerFactory::build($this->conn)));
        }

        public function testRateInsertsANewRateAndRecomputesTheScore(): void
        {
            try {
                $result = $this->service->rate(5, 4, EntityManagerFactory::build($this->conn));

                self::assertInstanceOf(RatingScoreSummary::class, $result);
                self::assertSame(4.0, $result->average);
                self::assertSame(1, $result->count);
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

        public function testRateReplacesAnExistingRateFromTheSameUser(): void
        {
            try {
                $this->service->rate(5, 2, EntityManagerFactory::build($this->conn));
                $result = $this->service->rate(5, 5, EntityManagerFactory::build($this->conn));

                self::assertInstanceOf(RatingScoreSummary::class, $result);
                self::assertSame(1, $result->count, 'the second rate must replace, not add to, the first');
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

        public function testRateForAnAnonymousUserSetsTheCookieAndRecordsTheIp(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 2,
                'status' => 'guest',
            ]));

            try {
                $result = $this->service->rate(5, 3, EntityManagerFactory::build($this->conn));

                self::assertInstanceOf(RatingScoreSummary::class, $result);
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

        public function testUpdateRatingScoreReturnsZeroCountForAnElementWithNoRates(): void
        {
            self::assertEquals(
                new RatingScoreSummary(score: null, average: null, count: 0),
                $this->service->updateRatingScore(EntityManagerFactory::build($this->conn), 5)
            );
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
        public function testRateForAReturningAnonymousUserDeletesTheStaleDuplicateBeforeReassigning(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 2,
                'status' => 'guest',
            ]));
            $_COOKIE['pwg_anonymous_rater'] = '99.99.99';

            $this->conn->insert('rate', [
                'element_id' => 4,
                'user_id' => 2,
                'anonymous_id' => '99.99.99',
                'rate' => 2,
                'date' => '2026-08-01',
            ]);
            $this->conn->insert('rate', [
                'element_id' => 4,
                'user_id' => 2,
                'anonymous_id' => '10.20.30',
                'rate' => 1,
                'date' => '2026-08-01',
            ]);

            try {
                $result = $this->service->rate(5, 3, EntityManagerFactory::build($this->conn));

                self::assertInstanceOf(RatingScoreSummary::class, $result);
                self::assertSame(['10.20.30'], $this->fetchAnonymousIdsForElement(4, 2));
                self::assertSame('10.20.30', $_COOKIE['pwg_anonymous_rater']);
            } finally {
                $this->conn->createQueryBuilder()
                    ->delete('rate')
                    ->where('element_id = 4')
                    ->andWhere('user_id = 2')
                    ->executeStatement();
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
