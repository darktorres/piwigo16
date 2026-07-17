<?php

declare(strict_types=1);

// RateService now calls Piwigo\Auth\AccessControl::isAuthorizeStatus()
// directly (P23 batch 8d), which reads Piwigo\Users\CurrentUser (Legacy
// Coupling Retirement Track A batch A3) -- tests below seed CurrentUser
// instead.
// CookieService::cookiePath() needs PHPWG_ROOT_PATH defined, same as
// tests/Unit/Auth/CookieServiceTest.php.
namespace {
    if (! defined('PHPWG_ROOT_PATH')) {
        define('PHPWG_ROOT_PATH', './');
    }
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Auth\CookieService;
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Rate\RateRepository;
    use Piwigo\Rate\RateService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\User;

    final class RateServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private RateService $service;

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

            Config::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            $GLOBALS['conf'] = [
                'rate' => true,
                'rate_anonymous' => true,
                'rate_items' => [0, 1, 2, 3, 4, 5],
                'guest_access' => true,
            ];
            CurrentUser::set(User::fromUserArray(['id' => 3, 'status' => 'normal']));
            $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
            unset($_COOKIE['pwg_anonymous_rater']);

            $this->conn = DbConnection::build();
            $this->service = new RateService(new RateRepository($this->conn), new CookieService());
        }

        public function test_rate_returns_false_for_a_null_rate(): void
        {
            self::assertFalse($this->service->rate(5, null));
        }

        public function test_rate_returns_false_when_rating_is_disabled(): void
        {
            $this->setConf('rate', false);

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
            $this->setConf('rate_anonymous', false);
            CurrentUser::set(User::fromUserArray(['id' => 2, 'status' => 'guest']));

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
                    ->delete(Tables::rate())
                    ->where('element_id = 5')
                    ->andWhere('user_id = 3')
                    ->executeStatement();
                $this->conn->createQueryBuilder()
                    ->update(Tables::images())
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
                    ->delete(Tables::rate())
                    ->where('element_id = 5')
                    ->andWhere('user_id = 3')
                    ->executeStatement();
                $this->conn->createQueryBuilder()
                    ->update(Tables::images())
                    ->set('rating_score', 'NULL')
                    ->where('id = 5')
                    ->executeStatement();
            }
        }

        public function test_rate_for_an_anonymous_user_sets_the_cookie_and_records_the_ip(): void
        {
            CurrentUser::set(User::fromUserArray(['id' => 2, 'status' => 'guest']));

            try {
                $result = $this->service->rate(5, 3);

                self::assertIsArray($result);
                self::assertSame('10.20.30', $_COOKIE['pwg_anonymous_rater'] ?? null);

                $anonymousId = $this->conn->createQueryBuilder()
                    ->select('anonymous_id')
                    ->from(Tables::rate())
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
                    ->delete(Tables::rate())
                    ->where('element_id = 5')
                    ->andWhere('user_id = 2')
                    ->executeStatement();
                $this->conn->createQueryBuilder()
                    ->update(Tables::images())
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

        /**
         * $GLOBALS is untyped (values are `mixed`), so a nested write like
         * `$GLOBALS['conf']['key'] = ...` can't be offset into directly --
         * round-trip through a locally typed variable instead.
         */
        private function setConf(string $key, mixed $value): void
        {
            /** @var array<string, mixed> $conf */
            $conf = $GLOBALS['conf'];
            $conf[$key] = $value;
            $GLOBALS['conf'] = $conf;
        }

        private function fetchRate(int $elementId, int $userId): ?string
        {
            $value = $this->conn->createQueryBuilder()
                ->select('rate')
                ->from(Tables::rate())
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
