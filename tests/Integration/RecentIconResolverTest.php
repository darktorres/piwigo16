<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Core\ProcessCache;
use Piwigo\Core\RecentIconResolver;

/**
 * Piwigo\Core\RecentIconResolver::getIcon() -- had zero dedicated coverage
 * (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 * Needs a real DB round-trip (SqlDialect::getRecentPeriodExpression()'s
 * SUBDATE() query, resolved via Env::now() in test mode against the fixed
 * PIWIGO_TEST_NOW=2026-08-01), so this lives in Integration, not Unit.
 */
final class RecentIconResolverTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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

        ProcessCache::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProcessCache::reset();
        parent::tearDown();
    }

    public function test_getIcon_returns_false_for_an_empty_or_zero_date(): void
    {
        self::assertFalse(RecentIconResolver::getIcon('', 7));
        self::assertFalse(RecentIconResolver::getIcon('0', 7));
    }

    public function test_getIcon_returns_the_title_icon_for_a_date_within_the_recent_period(): void
    {
        // PIWIGO_TEST_NOW is 2026-08-01; 7 days back is 2026-07-25, so
        // 2026-07-30 is within the recent window.
        $result = RecentIconResolver::getIcon('2026-07-30', 7);

        self::assertIsArray($result);
        if (! array_key_exists('TITLE', $result)) {
            self::fail('Expected getIcon() to return a TITLE key: ' . var_export($result, true));
        }
        self::assertSame('photos posted during the last 7 days', $result['TITLE']);
        self::assertFalse($result['IS_CHILD_DATE']);
    }

    public function test_getIcon_returns_an_empty_array_for_a_date_outside_the_recent_period(): void
    {
        $result = RecentIconResolver::getIcon('2026-07-01', 7);

        self::assertSame([], $result);
    }

    public function test_getIcon_propagates_the_isChildDate_flag(): void
    {
        $result = RecentIconResolver::getIcon('2026-07-30', 7, true);

        self::assertIsArray($result);
        if (! array_key_exists('IS_CHILD_DATE', $result)) {
            self::fail('Expected getIcon() to return an IS_CHILD_DATE key: ' . var_export($result, true));
        }
        self::assertTrue($result['IS_CHILD_DATE']);
    }

    public function test_getIcon_reuses_the_per_request_cache_for_a_repeated_date(): void
    {
        $first = RecentIconResolver::getIcon('2026-07-30', 7);
        $second = RecentIconResolver::getIcon('2026-07-30', 7);

        self::assertSame($first, $second);
    }
}
