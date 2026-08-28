<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Cache\CacheFactory;
    use Piwigo\Cache\CalendarNavCachePool;
    use Piwigo\Calendar\CalendarBase;
    use Piwigo\Calendar\CalendarRenderer;
    use Piwigo\Calendar\CalendarRenderResult;
    use Piwigo\Calendar\Projection\CalendarBarEntry;
    use Piwigo\Calendar\Projection\ChronologyNavBarRow;
    use Piwigo\Category\CategoryRepository;
    use Piwigo\Common\Enum\Section;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Core\FilterState;
    use Piwigo\Core\RedirectServiceInterface;
    use Piwigo\Core\UrlServiceInterface;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Db\TypedRepository;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Permission\PermissionRepository;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Tests\Support\CategoryInfoTestFactory;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\DbTransactionTestOverride;
    use Piwigo\Tests\Support\EventDispatcherTestFactory;
    use Piwigo\Tests\Support\HtmlServiceTestFactory;
    use Piwigo\Tests\Support\ImageStdParamsTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\RequestMetricsTestFactory;
    use Piwigo\Tests\Support\TemplateTestFactory;
    use Piwigo\Tests\Support\TranslatorTestFactory;
    use Piwigo\Users\User;

    function calendar_renderer_test_rrmdir(string $dir): void
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
            is_dir($path) ? calendar_renderer_test_rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * End-to-end tests for the real, unmodified CalendarRenderer::render()
     * entry point -- exercises CalendarMonthly/CalendarWeekly/CalendarBase
     * indirectly through it too (render() constructs the real calendar
     * subclass itself), but focuses on render()'s own logic: style/view
     * resolution and fallback, chronology_date sanitization, the
     * categories-vs-items branch, and the real order_by construction.
     *
     * Same date-shifted fixture as CalendarMonthlyTest/CalendarWeeklyTest
     * (see their own docblocks for the exact per-image dates and rationale).
     */
    final class CalendarRendererTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private Connection $conn;

        private CalendarRendererTestFakeUrlService $urlService;

        private HtmlService $htmlService;

        #[Override]
        protected function setUp(): void
        {
            parent::setUp();
            $this->setUpConnectionFromEnv();

            if (! self::$fixtureReady) {
                $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            // PILOT (transaction-wrapping rollout): begin before any container
            // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
            // comment for the full reasoning.
            DbTransactionTestOverride::begin();

            CurrentConfigTestFactory::get()->reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();
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

            $this->urlService = new CalendarRendererTestFakeUrlService();
            $this->htmlService = HtmlServiceTestFactory::build();

            // Matches CalendarServiceTest's own fixture shape/guaranteed-shape
            // rationale: getSqlConditionFandF()'s forbidden_images fallthrough
            // needs a complete row, not just a partial one.
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 1,
                'forbidden_categories' => '0',
                'level' => '0',
                'image_access_type' => 'NOT IN',
                'image_access_list' => '',
            ]));
        }

        #[Override]
        protected function tearDown(): void
        {
            // CurrentConfig::setDataLocation('data/') above only redirects
            // Template::__construct()'s own mkgetdir(..., 'templates_c') call
            // away from the real, shared _data/ tree -- CurrentPaths itself is
            // never sandboxed to a temp root here (unlike every Unit test
            // using this same 'data/' trick, e.g. TemplateInstanceTest.php),
            // since these tests need the real themes/ tree for calendar
            // template rendering. That leaves a genuine, persistent data/
            // directory at the real project root with nothing to clean it up
            // -- real bug, found live (a stray data/templates_c/index.htm
            // showed up as untracked repo debris after a coverage run).
            calendar_renderer_test_rrmdir(CurrentPathsTestFactory::get()->root . 'data');
            LangTestFactory::get()->reset();
            TranslatorTestFactory::get()->reset();
            DbTransactionTestOverride::rollback();
            parent::tearDown();
        }

        private function permissionService(): PermissionService
        {
            return new PermissionService(
                new PermissionRepository(EntityManagerFactory::build($this->conn)),
                TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), GroupRepository::class),
                new CategoryRepository(EntityManagerFactory::build($this->conn), CurrentConfigTestFactory::get()),
                CurrentUserTestFactory::get(),
                new FilterState(),
                new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
            );
        }

        private function makeRenderer(): CalendarRenderer
        {
            return new CalendarRenderer(LangTestFactory::get(), $this->htmlService, TemplateTestFactory::build(), $this->urlService, CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), ImageStdParamsTestFactory::get(), RequestMetricsTestFactory::get(), $this->permissionService(), EntityManagerFactory::build($this->conn), new CalendarNavCachePool(CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30)));
        }

        /**
         * Narrows through a chain of array offsets on a Latte template var --
         * TemplateInterface::getTemplateVars() is declared `mixed` by design
         * (mirrors Template's own arbitrary-value assign() contract, see that
         * interface's own docblock), so every hop needs an explicit is_array()
         * check for PHPStan (level 10, project-wide, tests included) rather
         * than trusting the chain.
         *
         * @param list<int|string> $path
         */
        private function dig(mixed $value, array $path): mixed
        {
            foreach ($path as $key) {
                if (is_array($value) && array_key_exists($key, $value)) {
                    $value = $value[$key];
                    continue;
                }

                // Calendar template variables are objects now, not flattened
                // arrays (P58-A §4), and a path walks through both.
                if (is_object($value) && is_string($key)) {
                    $vars = get_object_vars($value);
                    if (array_key_exists($key, $vars)) {
                        $value = $vars[$key];
                        continue;
                    }
                }

                $value = null;
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

        public function testRenderForAnExplicitItemListDefaultsToMonthlyStyleAndListView(): void
        {
            $renderer = $this->makeRenderer();

            $result = $renderer->render(
                section: Section::ListView,
                category: null,
                items: [3, 1, 5, 2, 4],
                chronologyField: 'posted',
                chronologyStyle: null,
                chronologyView: null,
                chronologyDate: [],
                superOrderBy: false,
            );

            self::assertSame('monthly', $result->chronologyStyle);
            self::assertSame('list', $result->chronologyView);
            self::assertSame('', $result->comment);
            self::assertSame([], $result->chronologyDate);
            // The default order_by ('ORDER BY date_available DESC, file ASC,
            // id ASC') re-sorts by the real, shifted dates: id 5 (2025-01-25)
            // is the newest, id 1 (2024-03-10) the oldest -- proving the
            // caller's own $items order (3,1,5,2,4) was discarded and
            // recomputed from the DB, not just passed through.
            self::assertSame([5, 4, 3, 2, 1], $result->items);
        }

        /**
         * The weekly style has no calendar view (CalendarRenderer's own
         * $styles['weekly']['view_calendar'] === false) -- requesting
         * chronology_view=calendar together with chronology_style=weekly
         * silently downgrades the view (not the style) back to list.
         */
        public function testRenderForcesListViewWhenTheWeeklyStyleHasNoCalendarView(): void
        {
            $renderer = $this->makeRenderer();

            $result = $renderer->render(
                section: Section::ListView,
                category: null,
                items: [1, 2, 3, 4, 5],
                chronologyField: 'posted',
                chronologyStyle: 'weekly',
                chronologyView: CalendarBase::CAL_VIEW_CALENDAR,
                chronologyDate: [],
                superOrderBy: false,
            );

            self::assertSame('weekly', $result->chronologyStyle);
            self::assertSame('list', $result->chronologyView);
        }

        /**
         * buildInnerSql('items', ..., []) returns null for an empty item list
         * -- render()'s own early "nothing to do" return, before any calendar
         * object is even constructed.
         */
        public function testRenderReturnsEarlyForAnEmptyItemList(): void
        {
            $renderer = $this->makeRenderer();

            $result = $renderer->render(
                section: Section::ListView,
                category: null,
                items: [],
                chronologyField: 'posted',
                chronologyStyle: 'monthly',
                chronologyView: CalendarBase::CAL_VIEW_LIST,
                chronologyDate: [2026, 'any'],
                superOrderBy: false,
            );

            self::assertSame([], $result->items);
            self::assertSame('', $result->comment);
            // The early return passes the caller's raw chronologyDate straight
            // through, unsanitized -- unlike every other path, which always
            // normalizes it first.
            self::assertSame([2026, 'any'], $result->chronologyDate);
            self::assertSame('monthly', $result->chronologyStyle);
            self::assertSame('list', $result->chronologyView);
        }

        /**
         * Exercises the DEFAULT style (monthly) with the calendar view and no
         * year selected yet -- i.e. the very first click on a real gallery's
         * "browse by date" link -- through the real, unmodified
         * CalendarRenderer::render() entry point, not just the calendar
         * class's own method in isolation (see
         * CalendarMonthlyTest::test_buildGlobalCalendar_groups_multiple_years_and_months_correctly()
         * for the underlying query). Year/month grouping must work correctly
         * under the standard ONLY_FULL_GROUP_BY sql_mode this project's own
         * DbConnection never strips.
         */
        public function testRenderGroupsMultipleYearsAndMonthsForTheDefaultMonthlyCalendarView(): void
        {
            $template = TemplateTestFactory::build();
            $renderer = new CalendarRenderer(LangTestFactory::get(), $this->htmlService, $template, $this->urlService, CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), ImageStdParamsTestFactory::get(), RequestMetricsTestFactory::get(), $this->permissionService(), EntityManagerFactory::build($this->conn), new CalendarNavCachePool(CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30)));

            $result = $renderer->render(
                section: Section::ListView,
                category: null,
                items: [1, 2, 3, 4, 5],
                chronologyField: 'posted',
                chronologyStyle: null, // defaults to 'monthly'
                chronologyView: CalendarBase::CAL_VIEW_CALENDAR,
                chronologyDate: [],
                superOrderBy: false,
            );

            self::assertSame('monthly', $result->chronologyStyle);
            self::assertSame('calendar', $result->chronologyView);
            self::assertSame([], $result->chronologyDate);

            $calendarVars = $template->getTemplateVars('chronology_calendar');
            // ORDER BY YEAR(date_field) DESC -- 2025 sorts before 2024.
            $bars = $this->digArray($calendarVars, ['calendarBars']);
            self::assertArrayHasKey(0, $bars);
            self::assertArrayHasKey(1, $bars);
            $year2025 = $bars[0];
            $year2024 = $bars[1];
            self::assertInstanceOf(CalendarBarEntry::class, $year2025);
            self::assertInstanceOf(CalendarBarEntry::class, $year2024);

            self::assertSame(2025, $year2025->headLabel);
            self::assertSame(2, $year2025->nbImages);
            self::assertSame(1, $this->dig($year2025, ['items', 0, 'label']));

            self::assertSame(2024, $year2024->headLabel);
            self::assertSame(3, $year2024->nbImages);
            // ORDER BY MONTH(date_field) ASC within the year -- month 3 before
            // month 7.
            self::assertSame(3, $this->dig($year2024, ['items', 0, 'label']));
            self::assertSame(7, $this->dig($year2024, ['items', 1, 'label']));
        }

        /**
         * A real gallery URL parses chronology_date tokens as strings
         * (Piwigo\Url\UrlService::parseWellKnownParamsUrl() returns
         * list<string>) -- render() sanitizes each non-'any', non-empty token
         * into a real int before ever handing it to the calendar object (see
         * its own sanitization loop), which CalendarBase::buildNextPrev()'s
         * own is_string()-based "current period" filter depends on. See
         * CalendarMonthlyTest's own matching test.
         */
        public function testRenderNormalizesChronologyDateToIntsAndNextPrevNavigationStillWorks(): void
        {
            $template = TemplateTestFactory::build();
            $renderer = new CalendarRenderer(LangTestFactory::get(), $this->htmlService, $template, $this->urlService, CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), ImageStdParamsTestFactory::get(), RequestMetricsTestFactory::get(), $this->permissionService(), EntityManagerFactory::build($this->conn), new CalendarNavCachePool(CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30)));

            $result = $renderer->render(
                section: Section::ListView,
                category: null,
                items: [1, 2, 3, 4, 5],
                chronologyField: 'posted',
                chronologyStyle: 'monthly',
                chronologyView: CalendarBase::CAL_VIEW_LIST,
                chronologyDate: ['2024', '3'],
                superOrderBy: false,
            );

            // Sanitized from ['2024', '3'] into real ints.
            self::assertSame([2024, 3], $result->chronologyDate);
            // Only images 1 and 2 (both in March 2024) match the resulting
            // getDateWhere() filter, oldest-first (small selected period).
            self::assertSame([1, 2], $result->items);

            $navRows = $this->digArray($template->getTemplateVars('chronology_navigation_bars'), []);
            self::assertArrayHasKey(0, $navRows);
            $nav = $navRows[0];
            self::assertInstanceOf(ChronologyNavBarRow::class, $nav);
            self::assertNull($nav->previous);
            // "next" points at the real next period (2024-7), not the period
            // already being viewed.
            self::assertSame('7 2024', $this->dig($nav, ['next', 'label']));
            self::assertSame(
                '/fake-index?' . json_encode([
                    'chronology_date' => ['2024', '7'],
                ], JSON_THROW_ON_ERROR) . '|removed=' . json_encode(['start'], JSON_THROW_ON_ERROR),
                $this->dig($nav, ['next', 'url'])
            );
        }

        /**
         * section='categories' with no specific category (category=null)
         * takes an entirely different branch than section='items' -- it
         * recomputes $items from CategoryService/PermissionService's own
         * "browse everything visible" SQL condition instead of trusting the
         * caller's own $items argument (which this branch discards outright,
         * always starting from []).
         */
        public function testRenderForCategoriesSectionRecomputesItemsInsteadOfUsingTheCallerList(): void
        {
            $renderer = $this->makeRenderer();

            $result = $renderer->render(
                section: Section::Categories,
                category: null,
                items: ['this list must be ignored'],
                chronologyField: 'posted',
                chronologyStyle: 'monthly',
                chronologyView: CalendarBase::CAL_VIEW_LIST,
                chronologyDate: [],
                superOrderBy: false,
            );

            self::assertSame([5, 4, 3, 2, 1], $result->items);
        }

        /**
         * render()'s own categories-section cache_item lookup (only computed
         * for section='categories', category=null, and an empty/'any'-only
         * chronologyDate) is keyed on 'nav_' . user id . md5(date_field .
         * order_by) -- forbidden_categories plays no part in that key. Fixture
         * shape: image_category puts images 1/2/3 in category 1 and
         * images 4/5 in category 2 -- forbidding category 2 between the two
         * calls below would make a genuine re-query return [3, 2, 1] instead of
         * [5, 4, 3, 2, 1], so the second call only matching the first proves
         * the cached list (is_array($cached_items) branch) was reused, not
         * recomputed via a fresh findImageIds() call.
         */
        public function testRenderReusesACachedCategoriesItemListInsteadOfReQuerying(): void
        {
            new CalendarNavCachePool(CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30))->clear();

            $render = fn (): CalendarRenderResult => $this->makeRenderer()
                ->render(
                    section: Section::Categories,
                    category: null,
                    items: [],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_LIST,
                    chronologyDate: [],
                    superOrderBy: false,
                );

            try {
                $first = $render();
                self::assertSame([5, 4, 3, 2, 1], $first->items);

                CurrentUserTestFactory::get()->set(User::fromUserArray([
                    'id' => 1,
                    'forbidden_categories' => '2',
                    'level' => '0',
                    'image_access_type' => 'NOT IN',
                    'image_access_list' => '',
                ]));

                $second = $render();
                self::assertSame($first->items, $second->items);
            } finally {
                new CalendarNavCachePool(CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30))->clear();
            }
        }

        /**
         * superOrderBy=true uses CurrentConfig::orderBy() verbatim (always
         * date_available DESC); superOrderBy=false, with a specific month
         * selected, prepends an ASC override ("small period, oldest first")
         * instead -- same underlying data, opposite resulting order.
         */
        public function testSuperOrderByIgnoresTheSmallPeriodAscendingOverride(): void
        {
            $ascending = $this->makeRenderer()
                ->render(
                    section: Section::ListView,
                    category: null,
                    items: [1, 2, 3, 4, 5],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_LIST,
                    chronologyDate: [2024, 3],
                    superOrderBy: false,
                );
            self::assertSame([1, 2], $ascending->items);

            $descending = $this->makeRenderer()
                ->render(
                    section: Section::ListView,
                    category: null,
                    items: [1, 2, 3, 4, 5],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_LIST,
                    chronologyDate: [2024, 3],
                    superOrderBy: true,
                );
            self::assertSame([2, 1], $descending->items);
        }

        /**
         * buildInnerSql('categories', true, ...) returns null when the given
         * category has no visible sub-category ids at all (a category id that
         * doesn't exist anywhere in the real tree) -- render()'s own early
         * "nothing to do" return for the categories branch, distinct from the
         * items-branch equivalent above.
         */
        public function testRenderReturnsEarlyForACategoriesSectionWithNoVisibleSubcategories(): void
        {
            $result = $this->makeRenderer()
                ->render(
                    section: Section::Categories,
                    category: CategoryInfoTestFactory::build(id: 999999),
                    items: [],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_LIST,
                    chronologyDate: [2026, 'any'],
                    superOrderBy: false,
                );

            self::assertSame([], $result->items);
            self::assertSame('', $result->comment);
            self::assertSame([2026, 'any'], $result->chronologyDate);
        }

        /**
         * render()'s own chronology_date sanity check truncates to at most 3
         * components (year/month/day) before ever handing it to the calendar
         * object, regardless of how many extra tokens a crafted URL supplies.
         */
        public function testRenderTruncatesChronologyDateToAtMostThreeComponents(): void
        {
            $result = $this->makeRenderer()
                ->render(
                    section: Section::ListView,
                    category: null,
                    items: [1, 2, 3, 4, 5],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_LIST,
                    chronologyDate: ['2024', '3', '10', '999'],
                    superOrderBy: false,
                );

            self::assertSame([2024, 3, 10], $result->chronologyDate);
        }

        /**
         * 'any' is never allowed together with the calendar view -- render()
         * discards that component and everything after it (not just the
         * 'any' token itself) rather than passing a partially-wildcarded date
         * through to CalendarMonthly's own calendar-grid rendering.
         */
        public function testRenderDiscardsAnyAndEverythingAfterItInCalendarView(): void
        {
            $result = $this->makeRenderer()
                ->render(
                    section: Section::ListView,
                    category: null,
                    items: [1, 2, 3, 4, 5],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_CALENDAR,
                    chronologyDate: ['2024', 'any', '15'],
                    superOrderBy: false,
                );

            self::assertSame([2024], $result->chronologyDate);
        }

        /**
         * Outside calendar view, 'any' is a legitimate wildcard component --
         * kept as-is (not converted to an int like its real numeric
         * siblings), counted toward $any_count.
         */
        public function testRenderKeepsALiteralAnyComponentInListView(): void
        {
            $result = $this->makeRenderer()
                ->render(
                    section: Section::ListView,
                    category: null,
                    items: [1, 2, 3, 4, 5],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_LIST,
                    chronologyDate: ['any', '3'],
                    superOrderBy: false,
                );

            self::assertSame(['any', 3], $result->chronologyDate);
        }

        /**
         * An empty-string component (a real shape a crafted/malformed URL can
         * produce) truncates everything from that point on, same as an
         * over-long array -- but via a completely separate branch than the
         * >3-components truncation above.
         */
        public function testRenderTruncatesChronologyDateAtAnEmptyStringComponent(): void
        {
            $result = $this->makeRenderer()
                ->render(
                    section: Section::ListView,
                    category: null,
                    items: [1, 2, 3, 4, 5],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_LIST,
                    chronologyDate: ['2024', '', '15'],
                    superOrderBy: false,
                );

            self::assertSame([2024], $result->chronologyDate);
        }

        /**
         * All 3 components wildcarded ($any_count === 3) drops the last one --
         * a real day-precision "any/any/any" is meaningless (equivalent to no
         * date filter at all), so render() narrows it back to 2 wildcarded
         * components instead of keeping a pointless 3rd.
         */
        public function testRenderDropsTheLastComponentWhenEveryComponentIsAny(): void
        {
            $result = $this->makeRenderer()
                ->render(
                    section: Section::ListView,
                    category: null,
                    items: [1, 2, 3, 4, 5],
                    chronologyField: 'posted',
                    chronologyStyle: 'monthly',
                    chronologyView: CalendarBase::CAL_VIEW_LIST,
                    chronologyDate: ['any', 'any', 'any'],
                    superOrderBy: false,
                );

            self::assertSame(['any', 'any'], $result->chronologyDate);
        }
    }

    /**
     * Real fake for UrlServiceInterface -- see CalendarMonthlyTestFakeUrlService's
     * own docblock for the full rationale.
     */
    final class CalendarRendererTestFakeUrlService implements UrlServiceInterface
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
            return '/fake-index?' . json_encode($params, JSON_THROW_ON_ERROR);
        }

        #[Override]
        public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
        {
            return '/fake-index?' . json_encode($redefined, JSON_THROW_ON_ERROR) . '|removed=' . json_encode($removed, JSON_THROW_ON_ERROR);
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
            throw new LogicException('not used by CalendarRenderer');
        }

        #[Override]
        public function parseWellKnownParamsUrl(array $tokens, int &$i): array
        {
            throw new LogicException('not used by CalendarRenderer');
        }

        #[Override]
        public function getActionUrl($id, $whatPart, bool $download): string
        {
            throw new LogicException('not used by CalendarRenderer');
        }

        #[Override]
        public function getElementUrl(array $elementInfo): string
        {
            throw new LogicException('not used by CalendarRenderer');
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
            throw new LogicException('not used by CalendarRenderer');
        }

        #[Override]
        public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
        {
            throw new LogicException('not used by CalendarRenderer');
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
