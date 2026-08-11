<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Calendar\CalendarBase;
    use Piwigo\Calendar\CalendarQueryScope;
    use Piwigo\Calendar\CalendarRepository;
    use Piwigo\Calendar\CalendarWeekly;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Core\RedirectServiceInterface;
    use Piwigo\Core\UrlServiceInterface;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Permission\SqlCondition;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\ImageStdParamsTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\TemplateTestFactory;
    use Piwigo\Tests\Support\TranslatorTestFactory;

    function calendar_weekly_test_rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $nodes = scandir($dir);
        foreach ($nodes !== false ? $nodes : [] as $node) {
            if ($node === '.' || $node === '..') {
                continue;
            }
            $path = $dir . '/' . $node;
            is_dir($path) ? calendar_weekly_test_rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Covers CalendarBase's shared logic (initialize()/buildNavBar()/
     * buildNextPrev(), all on the abstract base class) through
     * CalendarWeekly -- see CalendarMonthlyTest's own docblock for why there
     * is no separate CalendarBaseTest file, and for the exact same date
     * shift/Lang-reset rationale reused here.
     *
     *   id 1: date_available 2024-03-10 (year 2024, WEEK(...,5)+1 = 11, WEEKDAY = 6 (Sunday))
     *   id 2: date_available 2024-03-15 (year 2024, week 12, WEEKDAY = 4 (Friday))
     *   id 3: date_available 2024-07-04 (year 2024, week 28, WEEKDAY = 3 (Thursday))
     *   id 4: date_available 2025-01-20 (year 2025, week 4, WEEKDAY = 0 (Monday))
     *   id 5: date_available 2025-01-25 (year 2025, week 4, WEEKDAY = 5 (Saturday))
     *
     * (4 and 5 share week 4 deliberately, so the day-level nav bar for that
     * week has 2 distinct real entries instead of just 1.)
     */
    final class CalendarWeeklyTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private Connection $conn;

        private CalendarWeeklyTestFakeUrlService $urlService;

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
            // Template::__construct() unconditionally checks
            // CurrentConfig::dataDirChecked() and, when null, writes through
            // its own constructor-injected $this->currentConfigService->get()
            // -- which this test never wires (no test here needs a real
            // ConfigService/ConfigRepository, see buildConfigRepository()'s
            // own docblock for the opt-in shape).
            // Pre-marking it "1" skips that write entirely, same technique as
            // tests/Unit/Picture/PictureCommentRendererTest.php's own
            // makePictureCommentTestTemplate().
            CurrentConfigTestFactory::get()->dataLocation = 'data/';
            CurrentConfigTestFactory::get()->dataDirChecked = '1';
            LangTestFactory::get()->reset();
            TranslatorTestFactory::get()->reset();

            $this->conn = DbConnection::build();

            $this->conn->executeStatement("UPDATE images SET date_available = '2024-03-10 00:00:00' WHERE id = 1");
            $this->conn->executeStatement("UPDATE images SET date_available = '2024-03-15 00:00:00' WHERE id = 2");
            $this->conn->executeStatement("UPDATE images SET date_available = '2024-07-04 00:00:00' WHERE id = 3");
            $this->conn->executeStatement("UPDATE images SET date_available = '2025-01-20 00:00:00' WHERE id = 4");
            $this->conn->executeStatement("UPDATE images SET date_available = '2025-01-25 00:00:00' WHERE id = 5");

            $this->urlService = new CalendarWeeklyTestFakeUrlService();
        }

        #[Override]
        protected function tearDown(): void
        {
            // CurrentConfig::setDataLocation('data/') above only redirects
            // Template::__construct()'s own mkgetdir(..., 'templates_c') call
            // away from the real, shared _data/ tree -- CurrentPaths itself
            // stays pointed at the real project root throughout (unlike every
            // Unit test using this same 'data/' trick, e.g.
            // TemplateInstanceTest.php, which sandboxes CurrentPaths to a temp
            // root too). That leaves a genuine, persistent data/ directory at
            // the real project root with nothing to clean it up -- real bug,
            // found live (a stray data/templates_c/index.htm showed up as
            // untracked repo debris after a coverage run).
            calendar_weekly_test_rrmdir(CurrentPathsTestFactory::get()->root . 'data');
            LangTestFactory::get()->reset();
            TranslatorTestFactory::get()->reset();
            parent::tearDown();
        }

        private function makeCalendar(): CalendarWeekly
        {
            $calendar = new CalendarWeekly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
            $calendar->chronology_field = 'posted';
            $calendar->initialize(new CalendarQueryScope(
                new SqlCondition(' FROM images WHERE id IN (1,2,3,4,5)'),
                false,
                new SqlCondition('i.id IN (1,2,3,4,5)'),
            ));

            return $calendar;
        }

        /**
         * Narrows through a chain of array offsets on a Smarty template var --
         * TemplateInterface::get_template_vars() is declared `mixed` by design
         * (mirrors Smarty's own arbitrary-value assign() contract, see that
         * interface's own docblock), so every hop needs an explicit is_array()
         * check for PHPStan (level 10, project-wide, tests included) rather
         * than trusting the chain.
         *
         * @param list<int|string> $path
         */
        private function dig(mixed $value, array $path): mixed
        {
            foreach ($path as $key) {
                $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
            }

            return $value;
        }

        /**
         * Same as dig(), narrowed to array<int|string, mixed> for callers that
         * need to keep indexing.
         *
         * @param list<int|string> $path
         * @return array<int|string, mixed>
         */
        private function digArray(mixed $value, array $path): array
        {
            $result = $this->dig($value, $path);

            return is_array($result) ? $result : [];
        }

        /**
         * CurrentConfig::weekStartsOn() governs which SQL fragments
         * initialize() builds for the week/day levels -- monday-start uses
         * WEEK(date,5) (ISO-ish, week 1 = first week containing a Monday) and
         * WEEKDAY(date) (already Monday-based, 0-6); sunday-start uses plain
         * WEEK(date) (mode omitted) and DAYOFWEEK(date)-1.
         */
        public function testInitializeBuildsDifferentWeekAndDaySqlForMondayVsSundayStart(): void
        {
            CurrentConfigTestFactory::get()->weekStartsOn = 'sunday';
            $sunday = $this->makeCalendar();
            self::assertSame('WEEK(date_available)+1', $sunday->calendar_levels[CalendarBase::CWEEK]['sql']);
            self::assertSame('DAYOFWEEK(date_available)-1', $sunday->calendar_levels[CalendarBase::CDAY]['sql']);

            CurrentConfigTestFactory::get()->weekStartsOn = 'monday';
            $monday = $this->makeCalendar();
            self::assertSame('WEEK(date_available, 5)+1', $monday->calendar_levels[CalendarBase::CWEEK]['sql']);
            self::assertSame('WEEKDAY(date_available)', $monday->calendar_levels[CalendarBase::CDAY]['sql']);
        }

        public function testGetDateWhereWithNothingSelectedFallsBackToIsNotNull(): void
        {
            $calendar = $this->makeCalendar();
            $calendar->chronology_date = [];

            $where = $calendar->getDateWhere();
            self::assertSame(' AND date_available IS NOT NULL', $where->sql);
            self::assertSame([], $where->parameters);
        }

        public function testGetDateWhereForAYearWeekAndDay(): void
        {
            $calendar = $this->makeCalendar();
            $calendar->chronology_date = [2024, 11, 6];

            $where = $calendar->getDateWhere();
            self::assertSame(
                ' AND date_available BETWEEN :dateWhereYearStart AND :dateWhereYearEnd'
                . ' AND WEEK(date_available, 5)+1= :dateWhereWeek'
                . ' AND WEEKDAY(date_available)= :dateWhereDay',
                $where->sql
            );
            self::assertSame([
                'dateWhereYearStart' => '2024-01-01',
                'dateWhereYearEnd' => '2024-12-31 23:59:59',
                'dateWhereWeek' => 11,
                'dateWhereDay' => 6,
            ], $where->parameters);
        }

        public function testGetDateWhereTreatsAnyWeekAsAWildcardForTheWholeYear(): void
        {
            $calendar = $this->makeCalendar();
            $calendar->chronology_date = [2024, 'any'];

            $where = $calendar->getDateWhere();
            self::assertSame(' AND date_available BETWEEN :dateWhereYearStart AND :dateWhereYearEnd', $where->sql);
            self::assertSame([
                'dateWhereYearStart' => '2024-01-01',
                'dateWhereYearEnd' => '2024-12-31 23:59:59',
            ], $where->parameters);
        }

        public function testGenerateCategoryContentBuildsYearNavBarWhenNothingSelected(): void
        {
            $calendar = $this->makeCalendar();
            $calendar->chronology_date = [];
            $template = TemplateTestFactory::build();

            self::assertFalse($calendar->generateCategoryContent($template));

            $navVars = $calendar->getChronologyNavigationBars();
            self::assertSame(2024, $this->dig($navVars, [0, 'items', 0, 'LABEL']));
            self::assertSame(3, $this->dig($navVars, [0, 'items', 0, 'NB_IMAGES']));
            self::assertSame(2025, $this->dig($navVars, [0, 'items', 1, 'LABEL']));
            self::assertSame(2, $this->dig($navVars, [0, 'items', 1, 'NB_IMAGES']));
            self::assertSame('All', $this->dig($navVars, [0, 'items', 2, 'LABEL']));
        }

        /**
         * The week-level nav bar passes an explicit `[]` (not null) as its
         * own labels argument (see CalendarWeekly::generateCategoryContent()),
         * which -- unlike the year level above -- means getNavBarFromItems()
         * never substitutes calendar_levels[CWEEK]['labels'] (the "Week %d"
         * text) at all: LABEL stays the raw week number.
         */
        public function testGenerateCategoryContentBuildsWeekNavBarForASelectedYear(): void
        {
            $calendar = $this->makeCalendar();
            $calendar->chronology_date = [2024];
            $template = TemplateTestFactory::build();

            self::assertFalse($calendar->generateCategoryContent($template));

            $navVars = $calendar->getChronologyNavigationBars();
            self::assertSame(11, $this->dig($navVars, [0, 'items', 0, 'LABEL']));
            self::assertSame(1, $this->dig($navVars, [0, 'items', 0, 'NB_IMAGES']));
            self::assertSame(12, $this->dig($navVars, [0, 'items', 1, 'LABEL']));
            self::assertSame(1, $this->dig($navVars, [0, 'items', 1, 'NB_IMAGES']));
            self::assertSame(28, $this->dig($navVars, [0, 'items', 2, 'LABEL']));
            self::assertSame(1, $this->dig($navVars, [0, 'items', 2, 'NB_IMAGES']));
        }

        public function testGenerateCategoryContentBuildsDayNavBarWithTwoDistinctDaysInTheSameWeek(): void
        {
            $calendar = $this->makeCalendar();
            $calendar->chronology_date = [2025, 4];
            $template = TemplateTestFactory::build();

            self::assertFalse($calendar->generateCategoryContent($template));

            $navVars = $calendar->getChronologyNavigationBars();
            // WEEKDAY(2025-01-20) = 0 (Monday, image 4), WEEKDAY(2025-01-25) = 5 (Saturday, image 5).
            self::assertSame(0, $this->dig($navVars, [0, 'items', 0, 'LABEL']));
            self::assertSame(1, $this->dig($navVars, [0, 'items', 0, 'NB_IMAGES']));
            self::assertSame(5, $this->dig($navVars, [0, 'items', 1, 'LABEL']));
            self::assertSame(1, $this->dig($navVars, [0, 'items', 1, 'NB_IMAGES']));
        }

        /**
         * Regression test for the same fixed bug as CalendarMonthlyTest::
         * test_buildNextPrev_navigates_correctly_regardless_of_chronology_date_element_type()
         * -- buildNextPrev() is shared, unmodified CalendarBase code. With
         * chronology_date holding the ints CalendarRenderer actually produces,
         * "next" used to point at the currently-viewed year instead of
         * genuinely advancing to 2025.
         */
        public function testBuildNextPrevNavigatesCorrectlyWithIntTypedChronologyDate(): void
        {
            $calendar = $this->makeCalendar();
            $calendar->chronology_date = [2024];
            $template = TemplateTestFactory::build();

            $calendar->generateCategoryContent($template);

            $nav = $this->digArray($calendar->getChronologyNavigationBars(), [0]);
            self::assertArrayNotHasKey('previous', $nav);
            // Fixed: correctly advances to '2025', not '2024' (the period
            // already being viewed).
            self::assertSame('2025', $this->dig($nav, ['next', 'LABEL']));
        }

        /**
         * With week_starts_on=monday (the default), initialize() also rotates
         * the day-name labels themselves (not just the SQL), moving Sunday
         * from the front to the back -- untested until now because every
         * other test in this file (and CalendarMonthlyTest's identical
         * rationale) resets Lang, leaving Lang::days() empty and this
         * particular rotation's `is_string($shifted)` guard always false.
         * Lang::loadArray() populates real day names directly, without
         * needing an actual language file on disk.
         */
        public function testInitializeRotatesSundayToTheEndOfTheDayLabelsWhenMondayStartsTheWeek(): void
        {
            LangTestFactory::get()->loadArray([
                'day' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            ]);
            CurrentConfigTestFactory::get()->weekStartsOn = 'monday';

            $calendar = $this->makeCalendar();

            $labels = $calendar->calendar_levels[CalendarBase::CDAY]['labels'];
            self::assertIsArray($labels);
            self::assertSame(
                ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                array_values($labels)
            );
        }

        /**
         * getDateWhere()'s own truncation loop (shared shape with
         * CalendarMonthly's identical one) drops components beyond
         * $max_levels -- distinct from every other getDateWhere() test in
         * this file, which always calls it with the default (untruncated)
         * $max_levels=3.
         */
        public function testGetDateWhereTruncatesBeyondAnExplicitSmallerMaxLevels(): void
        {
            $calendar = $this->makeCalendar();
            $calendar->chronology_date = [2024, 12, 3];

            $where = $calendar->getDateWhere(2);
            self::assertSame(
                ' AND date_available BETWEEN :dateWhereYearStart AND :dateWhereYearEnd'
                . ' AND WEEK(date_available, 5)+1= :dateWhereWeek',
                $where->sql
            );
            self::assertSame([
                'dateWhereYearStart' => '2024-01-01',
                'dateWhereYearEnd' => '2024-12-31 23:59:59',
                'dateWhereWeek' => 12,
            ], $where->parameters);
        }
    }

    /**
     * Real fake for UrlServiceInterface -- see CalendarMonthlyTestFakeUrlService's
     * own docblock for the full rationale (identical: CalendarWeekly, like
     * CalendarMonthly, only ever calls duplicateIndexUrl()).
     */
    final class CalendarWeeklyTestFakeUrlService implements UrlServiceInterface
    {
        #[Override]
        public function getRootUrl(): string
        {
            return '/fake-root/';
        }

        #[Override]
        public function getAbsoluteRootUrl(bool $withScheme = true): string
        {
            return 'https://fake.test/';
        }

        #[Override]
        public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
        {
            return $url;
        }

        #[Override]
        public function makeIndexUrl(array $params = []): string
        {
            return '/fake-index?' . json_encode($params);
        }

        #[Override]
        public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
        {
            return '/fake-index?' . json_encode($redefined) . '|removed=' . json_encode($removed);
        }

        #[Override]
        public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
        {
            return '/fake-picture';
        }

        #[Override]
        public function makePictureUrl(array $params): string
        {
            return '/fake-picture';
        }

        #[Override]
        public function parseSectionUrl(array $tokens, &$nextToken, RedirectServiceInterface $redirectService): array
        {
            throw new LogicException('not used by CalendarWeekly');
        }

        #[Override]
        public function parseWellKnownParamsUrl(array $tokens, int &$i): array
        {
            throw new LogicException('not used by CalendarWeekly');
        }

        #[Override]
        public function getActionUrl($id, $whatPart, bool $download): string
        {
            throw new LogicException('not used by CalendarWeekly');
        }

        #[Override]
        public function getElementUrl(array $elementInfo): string
        {
            throw new LogicException('not used by CalendarWeekly');
        }

        #[Override]
        public function setMakeFullUrl(): void {}

        #[Override]
        public function unsetMakeFullUrl(): void {}

        #[Override]
        public function embellishUrl(string $url): string
        {
            return $url;
        }

        #[Override]
        public function getGalleryHomeUrl(): string
        {
            throw new LogicException('not used by CalendarWeekly');
        }

        #[Override]
        public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
        {
            throw new LogicException('not used by CalendarWeekly');
        }

        #[Override]
        public function urlIsRemote(string $url): bool
        {
            return false;
        }

        #[Override]
        public function getUserFavorites(): array
        {
            return [];
        }
    }
}
