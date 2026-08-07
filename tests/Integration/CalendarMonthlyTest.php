<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

use Override;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\RedirectServiceInterface;
use LogicException;
use Doctrine\DBAL\Connection;
use Piwigo\Calendar\CalendarBase;
use Piwigo\Calendar\CalendarMonthly;
use Piwigo\Calendar\CalendarQueryScope;
use Piwigo\Calendar\CalendarRepository;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Image\SrcImage;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Permission\SqlCondition;

/**
 * Covers CalendarBase's own shared logic (initialize()/get_date_where()'s
 * shared shape/get_display_name()/build_nav_bar()/build_next_prev(), all
 * `protected`/abstract on the base class) through CalendarMonthly, its
 * only sibling-tested concrete subclass alongside CalendarWeeklyTest --
 * CalendarBase itself is abstract and has no other real construction
 * site, exactly as this batch's own instructions describe.
 *
 * The fixture's 5 images all share one `date_available`/`date_creation`
 * (see CalendarRepositoryTest's own docblock) -- deliberately too little
 * spread to exercise multi-year/month/day grouping, so this file moves 4
 * of them to distinct, real dates via a scoped UPDATE (same technique as
 * CalendarRepositoryTest::test_find_image_ids_applies_the_date_where_continuation()):
 *
 *   id 1: date_available 2024-03-10 (Sunday)
 *   id 2: date_available 2024-03-15 (Friday)
 *   id 3: date_available 2024-07-04 (Thursday)
 *   id 4: date_available 2025-01-20 (Monday)
 *   id 5: date_available 2025-01-25 (Saturday)
 *
 * `date_creation` is left untouched -- the fixture's own dump sets it to
 * NULL for all 5 images (verified directly against the loaded fixture,
 * not assumed), so `chronology_field='created'` always resolves to
 * `AND date_creation IS NOT NULL` matching zero rows. That's still enough
 * to exercise `initialize()`'s field-selection branch and the zero-row
 * empty-calendar path -- see
 * test_created_field_uses_date_creation_and_returns_an_empty_calendar_for_zero_matching_rows().
 *
 * Lang::reset()/Translator::reset() pin this process's translation state
 * to "nothing loaded" -- IntegrationTestCase's own setUp() doesn't manage
 * Lang at all (confirmed via a full grep), so leaving it alone would make
 * get_date_component_label()'s month/day-name lookups depend on whichever
 * earlier test file in the same PHPUnit/Pest process happened to load a
 * language file first. With nothing loaded, Piwigo\Core\Lang::t() (used
 * for the week-number/"All" labels) still returns its own literal,
 * untranslated fallback text (Piwigo\Lang\Translator::translate()'s own
 * documented behaviour) -- Piwigo\Core\Lang::months()/days() (a
 * different, untranslated-by-default array) come back empty, so
 * get_date_component_label() falls through to the raw numeric component
 * every time. Both are asserted on below, not routed around.
 */
final class CalendarMonthlyTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private CalendarMonthlyTestFakeUrlService $urlService;

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
        LangTestFactory::get()->reset();
        TranslatorTestFactory::get()->reset();

        $this->conn = DbConnection::build();

        // Idempotent: safe to re-run before every test method even though
        // the DB itself is only reset/reloaded once per class (see
        // $fixtureReady above).
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_available = '2024-03-10 00:00:00' WHERE id = 1");
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_available = '2024-03-15 00:00:00' WHERE id = 2");
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_available = '2024-07-04 00:00:00' WHERE id = 3");
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_available = '2025-01-20 00:00:00' WHERE id = 4");
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_available = '2025-01-25 00:00:00' WHERE id = 5");

        $this->urlService = new CalendarMonthlyTestFakeUrlService();

        $configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get());
        $configService->loadConfFromDb();
        ImageStdParamsTestFactory::get()->load_from_db();
    }

    #[Override]
    protected function tearDown(): void
    {
        LangTestFactory::get()->reset();
        TranslatorTestFactory::get()->reset();
        parent::tearDown();
    }

    private function makeCalendar(): CalendarMonthly
    {
        $calendar = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $calendar->chronology_field = 'posted';
        $calendar->initialize($this->makeScope('id IN (1,2,3,4,5)'));

        return $calendar;
    }

    /**
     * Builds a no-join {@see CalendarQueryScope} filtered to $idFilterSql
     * (an `id ...` fragment, e.g. `'id IN (1,2,3)'`) -- both
     * representations are the same literal filter text, only the column
     * reference differs (`id` for the raw-SQL side, `i.id` for the DQL
     * side), same shape {@see \Piwigo\Calendar\CalendarService::
     * buildInnerSql()} itself produces. $idFilterSql omitted means no
     * WHERE filter at all (every real image row in scope).
     */
    private function makeScope(?string $idFilterSql = null): CalendarQueryScope
    {
        return new CalendarQueryScope(
            new SqlCondition(' FROM ' . Tables::images() . ($idFilterSql === null ? '' : ' WHERE ' . $idFilterSql)),
            false,
            new SqlCondition($idFilterSql === null ? '' : 'i.' . $idFilterSql),
        );
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
     * need to keep indexing (e.g. assertCount()/assertArrayNotHasKey(), or
     * to read several sibling keys off the same resolved row without
     * re-walking the whole path each time).
     *
     * @param list<int|string> $path
     * @return array<int|string, mixed>
     */
    private function digArray(mixed $value, array $path): array
    {
        $result = $this->dig($value, $path);

        return is_array($result) ? $result : [];
    }

    public function test_initialize_selects_date_available_for_posted_and_date_creation_for_created(): void
    {
        $posted = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $posted->chronology_field = 'posted';
        $posted->initialize($this->makeScope());
        self::assertSame('date_available', $posted->date_field);

        $created = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $created->chronology_field = 'created';
        $created->initialize($this->makeScope());
        self::assertSame('date_creation', $created->date_field);
    }

    public function test_get_date_where_with_a_full_year_month_day_selects_a_single_day(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_date = [2024, 3, 10];

        $where = $calendar->get_date_where();

        self::assertSame(' AND date_available BETWEEN :dateWhereStart AND :dateWhereEnd', $where->sql);
        self::assertSame(['dateWhereStart' => '2024-03-10', 'dateWhereEnd' => '2024-03-10 23:59:59'], $where->parameters);
    }

    /**
     * get_all_days_in_month()'s leap-year branch (Feb 29 vs Feb 28) is
     * `protected` -- exercised here through the public get_date_where(),
     * which calls it to compute the month's last day when only
     * year+month are selected, exactly like real production traffic.
     */
    public function test_get_date_where_respects_leap_years_when_only_year_and_month_are_selected(): void
    {
        $calendar = $this->makeCalendar();

        $calendar->chronology_date = [2024, 2]; // 2024 is a leap year
        $where2024 = $calendar->get_date_where();
        self::assertSame(' AND date_available BETWEEN :dateWhereStart AND :dateWhereEnd', $where2024->sql);
        self::assertSame(['dateWhereStart' => '2024-02-01', 'dateWhereEnd' => '2024-02-29 23:59:59'], $where2024->parameters);

        $calendar->chronology_date = [2023, 2]; // 2023 is not a leap year
        $where2023 = $calendar->get_date_where();
        self::assertSame(' AND date_available BETWEEN :dateWhereStart AND :dateWhereEnd', $where2023->sql);
        self::assertSame(['dateWhereStart' => '2023-02-01', 'dateWhereEnd' => '2023-02-28 23:59:59'], $where2023->parameters);
    }

    public function test_get_date_where_treats_any_as_a_wildcard_for_year_or_month(): void
    {
        $calendar = $this->makeCalendar();

        // year 'any', month given: skips the year BETWEEN bound entirely,
        // filters only by month.
        $calendar->chronology_date = ['any', 3];
        $whereMonthOnly = $calendar->get_date_where();
        self::assertSame(' AND date_available IS NOT NULL AND MONTH(date_available)= :dateWhereMonth', $whereMonthOnly->sql);
        self::assertSame(['dateWhereMonth' => 3], $whereMonthOnly->parameters);

        // year given, month 'any': the whole year, no month/day narrowing.
        $calendar->chronology_date = [2024, 'any'];
        $whereYearOnly = $calendar->get_date_where();
        self::assertSame(' AND date_available BETWEEN :dateWhereStart AND :dateWhereEnd', $whereYearOnly->sql);
        self::assertSame(['dateWhereStart' => '2024-01-01', 'dateWhereEnd' => '2024-12-31 23:59:59'], $whereYearOnly->parameters);
    }

    public function test_get_date_where_with_nothing_selected_falls_back_to_is_not_null(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_date = [];

        $where = $calendar->get_date_where();
        self::assertSame(' AND date_available IS NOT NULL', $where->sql);
        self::assertSame([], $where->parameters);
    }

    /**
     * REAL BUG found while writing this test (reproducible against a real
     * MySQL/MariaDB connection with the standard ONLY_FULL_GROUP_BY
     * sql_mode, which this project's own DbConnection deliberately never
     * strips -- see CalendarRepository's own docblock): build_global_calendar()'s
     * `ORDER BY YEAR(date_field) DESC, MONTH(date_field) ASC` references
     * the raw date column directly while the query only `GROUP BY period`
     * (a DATE_FORMAT(...) alias) -- MySQL can't prove YEAR()/MONTH() are
     * functionally dependent on that alias, so this ORDER BY is rejected
     * outright. This is CAL_VIEW_CALENDAR + monthly style (the default
     * style) + no year selected yet -- i.e. the very first calendar page a
     * real visitor lands on -- so every real request down this path 500s.
     * Reproduced two ways: directly here, and end-to-end through the real
     * CalendarRenderer::render() entry point in CalendarRendererTest.
     */
    /**
     * Regression test for a fixed bug: build_global_calendar()'s
     * `ORDER BY YEAR(date_field) DESC, MONTH(date_field) ASC` used to
     * reference the raw date column directly while the query only
     * `GROUP BY period` (a DATE_FORMAT(...) alias) -- MySQL couldn't prove
     * YEAR()/MONTH() were functionally dependent on that alias under
     * standard SQL mode (ONLY_FULL_GROUP_BY, never stripped by this
     * project's own DbConnection), so the query was rejected outright.
     * This was CAL_VIEW_CALENDAR + monthly style (the default style) + no
     * year selected yet -- i.e. the very first calendar page a real
     * visitor lands on -- so every real request down this path 500d.
     * Fixed by also grouping by the exact YEAR()/MONTH() expressions used
     * in ORDER BY. Verifies the real, now-working two-year/three-month
     * grouping (real values captured from a live run, not guessed).
     */
    public function test_build_global_calendar_groups_multiple_years_and_months_correctly(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [];
        $template = TemplateTestFactory::build();

        $ret = $calendar->generate_category_content($template);

        self::assertTrue($ret);
        // 2 distinct years exist (2024, 2025), so no single-year bail-out --
        // chronology_date stays empty.
        self::assertSame([], $calendar->chronology_date);

        $calendarVars = $template->get_template_vars('chronology_calendar');
        // ORDER BY YEAR(date_field) DESC -- 2025 sorts before 2024.
        $year2025 = $this->digArray($calendarVars, ['calendar_bars', 0]);
        $year2024 = $this->digArray($calendarVars, ['calendar_bars', 1]);

        self::assertSame(2025, $year2025['HEAD_LABEL']);
        self::assertSame(2, $year2025['NB_IMAGES']);
        self::assertSame(1, $this->dig($year2025, ['items', 0, 'LABEL']));
        self::assertSame(2, $this->dig($year2025, ['items', 0, 'NB_IMAGES']));
        self::assertCount(1, $this->digArray($year2025, ['items']));

        self::assertSame(2024, $year2024['HEAD_LABEL']);
        self::assertSame(3, $year2024['NB_IMAGES']);
        // ORDER BY MONTH(date_field) ASC within the year -- month 3 before
        // month 7 (a renderer that swapped the sort direction, or grouped
        // by the wrong expression, would show these out of order or merge
        // them into the wrong year).
        self::assertSame(3, $this->dig($year2024, ['items', 0, 'LABEL']));
        self::assertSame(2, $this->dig($year2024, ['items', 0, 'NB_IMAGES']));
        self::assertSame(7, $this->dig($year2024, ['items', 1, 'LABEL']));
        self::assertSame(1, $this->dig($year2024, ['items', 1, 'NB_IMAGES']));
    }

    public function test_generate_category_content_case_b_groups_days_by_month_within_a_selected_year(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [2024];
        $template = TemplateTestFactory::build();

        $ret = $calendar->generate_category_content($template);

        self::assertTrue($ret);
        // 2 distinct months exist in 2024, so build_year_calendar() does
        // NOT bail out to the single-month view -- chronology_date stays
        // exactly as given.
        self::assertSame([2024], $calendar->chronology_date);

        $calendarVars = $template->get_template_vars('chronology_calendar');
        $month3 = $this->digArray($calendarVars, ['calendar_bars', 0]);
        $month7 = $this->digArray($calendarVars, ['calendar_bars', 1]);

        // Month 3 (March): 2 images, on days 10 and 15.
        self::assertSame(2, $month3['NB_IMAGES']);
        self::assertSame(3, $month3['HEAD_LABEL']);
        self::assertSame(10, $this->dig($month3, ['items', 0, 'LABEL']));
        self::assertSame(1, $this->dig($month3, ['items', 0, 'NB_IMAGES']));
        self::assertSame(15, $this->dig($month3, ['items', 1, 'LABEL']));
        self::assertSame(1, $this->dig($month3, ['items', 1, 'NB_IMAGES']));

        // Month 7 (July): 1 image, on day 4 -- build_year_calendar() keeps
        // the day component as a raw substr() slice ('04'), not an
        // int-cast value, unlike the month key itself (real, observed
        // behaviour: a leading-zero day never survives PHP's numeric-string
        // array-key coercion the way '10'/'15' silently do).
        self::assertSame(1, $month7['NB_IMAGES']);
        self::assertSame(7, $month7['HEAD_LABEL']);
        self::assertSame('04', $this->dig($month7, ['items', 0, 'LABEL']));
        self::assertSame(1, $this->dig($month7, ['items', 0, 'NB_IMAGES']));

        // build_nav_bar(CYEAR) also ran (case B always calls it) -- 2024
        // has 3 images total, 2025 has 2, plus the "All" link.
        $navVars = $template->get_template_vars('chronology_navigation_bars');
        self::assertSame(2024, $this->dig($navVars, [0, 'items', 0, 'LABEL']));
        self::assertSame(3, $this->dig($navVars, [0, 'items', 0, 'NB_IMAGES']));
        self::assertSame(2025, $this->dig($navVars, [0, 'items', 1, 'LABEL']));
        self::assertSame(2, $this->dig($navVars, [0, 'items', 1, 'NB_IMAGES']));
        self::assertSame('All', $this->dig($navVars, [0, 'items', 2, 'LABEL']));
    }

    public function test_generate_category_content_case_c_builds_the_month_grid_picking_the_single_image_per_day(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [2024, 3];
        $template = TemplateTestFactory::build();

        $ret = $calendar->generate_category_content($template);

        self::assertTrue($ret);

        $calendarVars = $template->get_template_vars('chronology_calendar');

        // March 2024: day 10 is week-row 1 (0-indexed) column 6 (Sunday,
        // Monday-start grid), day 15 is week-row 2 column 4 (Friday) --
        // both real calendar facts, not guesses.
        $day10 = $this->digArray($calendarVars, ['month_view', 'weeks', 1, 6]);
        self::assertSame(10, $day10['DAY']);
        self::assertSame(6, $day10['DOW']);
        self::assertSame(1, $day10['NB_ELEMENTS']);
        self::assertSame('fixture-photo-1.jpg', $day10['IMAGE_ALT']);
        self::assertSame(
            '/fake-index?' . json_encode(['chronology_date' => [2024, 3, 10]]) . '|removed=[]',
            $day10['U_IMG_LINK']
        );

        $day15 = $this->digArray($calendarVars, ['month_view', 'weeks', 2, 4]);
        self::assertSame(15, $day15['DAY']);
        self::assertSame(4, $day15['DOW']);
        self::assertSame(1, $day15['NB_ELEMENTS']);
        self::assertSame('fixture-photo-2.jpg', $day15['IMAGE_ALT']);

        // The picked-image derivative URL is the real DerivativeImage
        // algorithm's own output for image 1's known, real fixture row --
        // reconstructing it independently (rather than hardcoding the
        // hashed filename) proves generate_category_content() picked
        // *that* image, without duplicating DerivativeImage's own
        // internal path-building logic as a guess.
        $row1 = $this->conn->createQueryBuilder()
            ->select('id', 'file', 'representative_ext', 'path', 'width', 'height', 'rotation')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row1);
        $expectedDerivative = new DerivativeImage(ImageStdParams::SQUARE, new SrcImage($row1), CurrentConfigTestFactory::get());
        self::assertSame($expectedDerivative->get_url(), $day10['IMAGE']);

        // Empty grid cells (no images) carry only DAY, no DOW/IMAGE/etc.
        self::assertSame(['DAY' => 1], $this->dig($calendarVars, ['month_view', 'weeks', 0, 4]));

        $where = $calendar->get_date_where();
        self::assertSame(' AND date_available BETWEEN :dateWhereStart AND :dateWhereEnd', $where->sql);
        self::assertSame(['dateWhereStart' => '2024-03-01', 'dateWhereEnd' => '2024-03-31 23:59:59'], $where->parameters);

        self::assertSame(
            ' / <a href="/fake-index?' . json_encode(['chronology_date' => [2024]]) . '|removed=' . json_encode(['start']) . '">2024</a>'
            . ' / <span class="calInHere">3</span>',
            $calendar->get_display_name()
        );
    }

    /**
     * Regression test for a fixed bug: CalendarBase::build_next_prev()
     * used to compute its own "current period" marker as
     * `implode('-', array_filter($this->chronology_date, is_string(...)))`
     * -- silently dropping every *int*-typed component. But
     * CalendarRenderer::render() (the only real caller) always sanitizes
     * non-'any', non-empty chronology_date tokens into real ints before
     * ever setting $calendar->chronology_date (see its own sanitization
     * loop). So on every real request, $current ended up '' (empty),
     * never matching any of the query's own real period strings -- the
     * "just in case" fallback then ranked '' *before* every real period,
     * making "next" always point at the *currently viewed* period instead
     * of genuinely advancing, and "previous" never appearing at all. Fixed
     * by dropping the is_string() filter entirely -- implode() already
     * string-casts int elements correctly on its own, same as every real
     * int-typed component in the SQL/DQL side's own $concatDqlExpr build.
     *
     * Demonstrated here with both chronology_date shapes on the exact
     * same underlying data to prove the fix: string components and int
     * components (what the real, unmodified CalendarRenderer actually
     * produces) now navigate identically. See CalendarRendererTest for
     * the same fix verified end-to-end through the unmodified production
     * entry point.
     */
    public function test_build_next_prev_navigates_correctly_regardless_of_chronology_date_element_type(): void
    {
        $withStrings = $this->makeCalendar();
        $withStrings->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $withStrings->chronology_date = ['2024', '3'];
        $templateStrings = TemplateTestFactory::build();
        $withStrings->generate_category_content($templateStrings);

        $navStrings = $this->digArray($templateStrings->get_template_vars('chronology_navigation_bars'), [0]);
        self::assertArrayNotHasKey('previous', $navStrings);
        self::assertSame('7 2024', $this->dig($navStrings, ['next', 'LABEL']));

        $withInts = $this->makeCalendar();
        $withInts->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $withInts->chronology_date = [2024, 3];
        $templateInts = TemplateTestFactory::build();
        $withInts->generate_category_content($templateInts);

        $navInts = $this->digArray($templateInts->get_template_vars('chronology_navigation_bars'), [0]);
        self::assertArrayNotHasKey('previous', $navInts);
        // Fixed: "next" now correctly points at the real next period
        // (2024-7), identically to the string-typed case above, instead of
        // the bug's old '3 2024' (the period already being viewed).
        self::assertSame('7 2024', $this->dig($navInts, ['next', 'LABEL']));
        self::assertSame(
            '/fake-index?' . json_encode(['chronology_date' => ['2024', '7']]) . '|removed=' . json_encode(['start']),
            $this->dig($navInts, ['next', 'URL'])
        );
    }

    public function test_generate_category_content_list_view_builds_year_then_month_then_day_nav_bars(): void
    {
        // nb_date_parts=0 -> year nav bar.
        $yearLevel = $this->makeCalendar();
        $yearLevel->chronology_view = CalendarBase::CAL_VIEW_LIST;
        $yearLevel->chronology_date = [];
        $yearTemplate = TemplateTestFactory::build();
        self::assertFalse($yearLevel->generate_category_content($yearTemplate));
        $yearNav = $yearTemplate->get_template_vars('chronology_navigation_bars');
        self::assertSame(2024, $this->dig($yearNav, [0, 'items', 0, 'LABEL']));
        self::assertSame(3, $this->dig($yearNav, [0, 'items', 0, 'NB_IMAGES']));

        // nb_date_parts=1 -> month nav bar (2 months in 2024).
        $monthLevel = $this->makeCalendar();
        $monthLevel->chronology_view = CalendarBase::CAL_VIEW_LIST;
        $monthLevel->chronology_date = [2024];
        $monthTemplate = TemplateTestFactory::build();
        self::assertFalse($monthLevel->generate_category_content($monthTemplate));
        $monthNav = $monthTemplate->get_template_vars('chronology_navigation_bars');
        self::assertSame(3, $this->dig($monthNav, [0, 'items', 0, 'LABEL']));
        self::assertSame(2, $this->dig($monthNav, [0, 'items', 0, 'NB_IMAGES']));
        self::assertSame(7, $this->dig($monthNav, [0, 'items', 1, 'LABEL']));
        self::assertSame(1, $this->dig($monthNav, [0, 'items', 1, 'NB_IMAGES']));

        // nb_date_parts=2 -> day nav bar. Unlike the year/month levels
        // above, this call passes an explicit, non-empty $day_labels
        // array (1..31, built from get_all_days_in_month(), not from
        // Lang::days()) rather than null/[] -- which flips on
        // get_nav_bar_from_items()'s "show_empty" synthesis (calendarShowEmpty()
        // defaults true): every day 1..31 gets a real entry, with a -1
        // sentinel (no URL/NB_IMAGES key at all) for the 29 days with no
        // image, not just the 2 real ones.
        $dayLevel = $this->makeCalendar();
        $dayLevel->chronology_view = CalendarBase::CAL_VIEW_LIST;
        $dayLevel->chronology_date = [2024, 3];
        $dayTemplate = TemplateTestFactory::build();
        self::assertFalse($dayLevel->generate_category_content($dayTemplate));
        $dayItems = $this->digArray($dayTemplate->get_template_vars('chronology_navigation_bars'), [0, 'items']);
        self::assertCount(31, $dayItems);
        self::assertSame(['LABEL' => 1], $dayItems[0]);
        self::assertSame(10, $this->dig($dayItems, [9, 'LABEL']));
        self::assertSame(1, $this->dig($dayItems, [9, 'NB_IMAGES']));
        self::assertSame(15, $this->dig($dayItems, [14, 'LABEL']));
        self::assertSame(1, $this->dig($dayItems, [14, 'NB_IMAGES']));
        self::assertSame(['LABEL' => 31], $dayItems[30]);
    }

    public function test_get_display_name_renders_the_any_wildcard_with_a_translated_label(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_date = [2024, 'any'];

        self::assertSame(
            ' / <a href="/fake-index?' . json_encode(['chronology_date' => [2024]]) . '|removed=' . json_encode(['start']) . '">2024</a>'
            . ' / <span class="calInHere">All</span>',
            $calendar->get_display_name()
        );
    }

    /**
     * chronology_field='created' points every image at date_creation,
     * which is NULL for all 5 fixture images (this file's own UPDATEs
     * only ever touch date_available) -- get_date_where() with nothing
     * selected falls back to `AND date_creation IS NOT NULL`, matching
     * zero rows.
     *
     * Regression test for the same fixed ONLY_FULL_GROUP_BY bug documented
     * above (test_build_global_calendar_groups_multiple_years_and_months_correctly()):
     * confirms the query now runs to completion (rather than being
     * rejected at the SQL level before any row is ever read) even when
     * zero rows actually match -- an empty result set is a real, distinct
     * scenario from "the query never even ran".
     */
    public function test_created_field_uses_date_creation_and_returns_an_empty_calendar_for_zero_matching_rows(): void
    {
        $calendar = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $calendar->chronology_field = 'created';
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [];
        $calendar->initialize($this->makeScope('id IN (1,2,3,4,5)'));

        self::assertSame('date_creation', $calendar->date_field);

        $template = TemplateTestFactory::build();
        $ret = $calendar->generate_category_content($template);

        self::assertTrue($ret);
        self::assertSame([], $this->digArray($template->get_template_vars('chronology_calendar'), ['calendar_bars']));
    }

    /**
     * Branch coverage gap closed: CalendarBase::build_nav_bar()'s own
     * "only one value exists at this level" auto-narrow
     * (`count($level_items) === 1 and count($page_chronology_date) <
     * count($this->calendar_levels) - 1`) silently sets
     * chronology_date[level] and returns *before* ever appending anything
     * to 'chronology_navigation_bars' -- a distinct branch from
     * build_global_calendar()'s own single-year bail-out (see
     * test_build_global_calendar_groups_multiple_years_and_months_correctly()).
     * build_nav_bar()'s own query never has an ORDER BY, so it was never
     * affected by the (now-fixed) ONLY_FULL_GROUP_BY bug either -- this is
     * the one real way in this suite to observe the auto-narrow-and-return
     * behaviour directly.
     *
     * Forces a single-month bucket by restricting inner_sql to id IN
     * (4,5) -- both 2025-01-* (see this file's own date UPDATEs above) --
     * so at CMONTH level, with year 2025 already selected, only one
     * distinct month (January) exists.
     */
    public function test_build_nav_bar_auto_narrows_chronology_date_and_skips_the_bar_for_a_single_value(): void
    {
        $calendar = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $calendar->chronology_field = 'posted';
        $calendar->chronology_view = CalendarBase::CAL_VIEW_LIST;
        $calendar->chronology_date = [2025];
        $calendar->initialize($this->makeScope('id IN (4,5)'));

        $template = TemplateTestFactory::build();
        $ret = $calendar->generate_category_content($template);

        self::assertFalse($ret);
        // Auto-narrowed from [2025] to [2025, 1]: build_nav_bar(CMONTH)
        // found a single distinct month (January) across id 4 and 5.
        self::assertSame([2025, 1], $calendar->chronology_date);
        // build_nav_bar() itself returned early, before appending anything
        // for the month level -- the only entry present comes from
        // build_next_prev()'s own separate (buggy) 'next' link, not from
        // a real nav bar of month choices.
        // build_nav_bar() itself returned early, before appending anything
        // for the month level. build_next_prev() still runs immediately
        // afterwards (unconditional, see generate_category_content()) --
        // now that it's fixed, it correctly finds that 2025-1 is the only
        // period id 4/5 (the only images in scope) ever fall into, so
        // there is genuinely no next or previous period at all.
        $navVars = $this->digArray($template->get_template_vars('chronology_navigation_bars'), [0]);
        self::assertSame([], $navVars);
    }

    /**
     * A specific day with month left as 'any' is an unusual but
     * code-supported combination -- the year-known branch of
     * get_date_where() re-checks the day component independently of month
     * when month is 'any' (or unset).
     */
    public function test_get_date_where_year_known_month_any_still_applies_a_day_filter(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_date = [2024, 'any', 15];

        $where = $calendar->get_date_where();
        self::assertSame(' AND date_available BETWEEN :dateWhereStart AND :dateWhereEnd AND DAYOFMONTH(date_available)= :dateWhereDay', $where->sql);
        self::assertSame([
            'dateWhereDay' => 15,
            'dateWhereStart' => '2024-01-01',
            'dateWhereEnd' => '2024-12-31 23:59:59',
        ], $where->parameters);
    }

    /**
     * Same day-filter-independent-of-month behaviour as above, but on the
     * year='any' side of get_date_where() (a completely separate branch).
     */
    public function test_get_date_where_year_any_month_any_still_applies_a_day_filter(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_date = ['any', 'any', 15];

        $where = $calendar->get_date_where();
        self::assertSame(' AND date_available IS NOT NULL AND DAYOFMONTH(date_available)= :dateWhereDay', $where->sql);
        self::assertSame(['dateWhereDay' => 15], $where->parameters);
    }

    /**
     * get_all_days_in_month()'s final fallback (neither a leap-year-aware
     * February computation nor a plain lookup-table hit) only triggers
     * when $month itself isn't numeric -- reachable through
     * generate_category_content()'s own day-nav-bar branch when the URL's
     * month component is the literal 'any', not a real number.
     */
    public function test_generate_category_content_defaults_to_31_days_when_month_is_any(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_view = CalendarBase::CAL_VIEW_LIST;
        $calendar->chronology_date = [2024, 'any'];
        $template = TemplateTestFactory::build();

        $calendar->generate_category_content($template);

        $dayNav = $this->digArray($template->get_template_vars('chronology_navigation_bars'), [0, 'items']);
        self::assertCount(31, $dayNav);
    }

    /**
     * build_next_prev()'s own per-index sub-query loop uses a single,
     * uniform literal `'any'` for any chronology_date index that is
     * itself 'any' -- applied to *every* row's period, not just the
     * current one -- so with chronology_date=[2024, 'any'] the whole
     * query groups by (YEAR(date_field), 'any'), collapsing every month
     * within a year into one period per year ('2024-any', '2025-any'),
     * not one period per real month. Confirmed live: this is genuinely
     * how the algorithm behaves, not a documented-elsewhere edge case.
     * '2024-any' sorts before '2025-any' under version_compare(),
     * landing at rank 0 -- no previous, and 'next' pointing at the only
     * other period, 2025 (get_date_nice_name() skips the 'any' component
     * entirely, so the label is just the year).
     */
    public function test_build_next_prev_treats_an_any_component_as_an_unmatched_current_period(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [2024, 'any'];
        $template = TemplateTestFactory::build();

        $calendar->generate_category_content($template);

        $nav = $this->digArray($template->get_template_vars('chronology_navigation_bars'), [0]);
        self::assertArrayNotHasKey('previous', $nav);
        self::assertSame('2025', $this->dig($nav, ['next', 'LABEL']));
        self::assertSame(
            '/fake-index?' . json_encode(['chronology_date' => ['2025', 'any']]) . '|removed=' . json_encode(['start']),
            $this->dig($nav, ['next', 'URL'])
        );
    }

    /**
     * build_next_prev()'s own `if (! isset($upper_items_rank[$current]))`
     * guard (CalendarBase.php's own "just in case (external link)" comment
     * right above it) fires when the requested chronology_date doesn't
     * correspond to *any* real period at all -- unlike the 'any' test
     * above, where the SQL's own uniform 'any' grouping happens to make the
     * "current" period always exist in $upper_items regardless. 1999/6 has
     * zero images in this fixture (real dates are 2024-03/2024-07/2025-01),
     * so no '1999-6' row is ever returned by the period query -- exactly
     * the shape of a hand-crafted/stale bookmark URL, not something any
     * internal link this class itself generates would ever produce.
     * version_compare() sorts '1999-6' before every real period, landing it
     * at rank 0 once appended -- no previous, 'next' pointing at the
     * chronologically-nearest real period, 2024-03.
     */
    public function test_build_next_prev_appends_the_current_period_when_no_real_period_matches_it(): void
    {
        $calendar = $this->makeCalendar();
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [1999, 6];
        $template = TemplateTestFactory::build();

        $calendar->generate_category_content($template);

        $nav = $this->digArray($template->get_template_vars('chronology_navigation_bars'), [0]);
        self::assertArrayNotHasKey('previous', $nav);
        self::assertSame('3 2024', $this->dig($nav, ['next', 'LABEL']));
        self::assertSame(
            '/fake-index?' . json_encode(['chronology_date' => ['2024', '3']]) . '|removed=' . json_encode(['start']),
            $this->dig($nav, ['next', 'URL'])
        );
    }

    /**
     * build_global_calendar() (case A: no year selected) bails out and
     * auto-narrows to a single real year instead of rendering a
     * pointless one-entry "choose a year" calendar -- scoped to images
     * 1-3 (all 2024) so exactly one year exists in this query's scope,
     * unlike the full 1-5 fixture set (spanning 2024 and 2025) every
     * other test in this file uses.
     */
    public function test_build_global_calendar_bails_out_to_year_view_when_only_one_year_exists(): void
    {
        $calendar = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $calendar->chronology_field = 'posted';
        $calendar->initialize($this->makeScope('id IN (1,2,3)'));
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [];
        $template = TemplateTestFactory::build();

        $calendar->generate_category_content($template);

        self::assertSame([2024], $calendar->chronology_date);
    }

    /**
     * build_year_calendar() (case B: year selected) bails out and
     * auto-narrows to a single real month the same way -- images 4 and 5
     * are both dated January 2025, the only month in this query's scope.
     */
    public function test_build_year_calendar_bails_out_to_month_view_when_only_one_month_exists(): void
    {
        $calendar = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $calendar->chronology_field = 'posted';
        $calendar->initialize($this->makeScope('id IN (4,5)'));
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [2025];
        $template = TemplateTestFactory::build();

        $calendar->generate_category_content($template);

        self::assertSame([2025, 1], $calendar->chronology_date);
    }

    /**
     * September 1, 2024 is a Sunday. build_month_calendar()'s raw
     * DAYOFWEEK()-1 gives dow=0 for Sunday; with week_starts_on=monday
     * (the default), a raw 0 must remap to column 6 (the last column of
     * the week), not stay at column 0 -- scoped to image 1 alone, moved
     * to September for this test only and restored afterwards.
     */
    public function test_build_month_calendar_shifts_a_sunday_first_day_to_the_last_column(): void
    {
        $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_available = '2024-09-15 00:00:00' WHERE id = 1");

        try {
            $calendar = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
            $calendar->chronology_field = 'posted';
            $calendar->initialize($this->makeScope('id = 1'));
            $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
            $calendar->chronology_date = [2024, 9];
            $template = TemplateTestFactory::build();

            $calendar->generate_category_content($template);

            $weeks = $this->digArray($template->get_template_vars('chronology_calendar'), ['month_view', 'weeks']);
            $firstWeek = $this->digArray($weeks, [0]);
            self::assertArrayNotHasKey('DAY', $this->digArray($firstWeek, [0]));
            self::assertSame(1, $this->dig($firstWeek, [6, 'DAY']));
        } finally {
            $this->conn->executeStatement("UPDATE " . Tables::images() . " SET date_available = '2024-03-10 00:00:00' WHERE id = 1");
        }
    }

    /**
     * July 31, 2024 is a Wednesday, not the last column of its week (under
     * week_starts_on=monday) -- the trailing padding loop must fill the
     * rest of the final week with empty cells rather than leaving it
     * short. Scoped to image 3 (already dated 2024-07-04 by this file's
     * own setUp(), no restoration needed).
     */
    public function test_build_month_calendar_pads_trailing_days_to_complete_the_final_week(): void
    {
        $calendar = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $calendar->chronology_field = 'posted';
        $calendar->initialize($this->makeScope('id = 3'));
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [2024, 7];
        $template = TemplateTestFactory::build();

        $calendar->generate_category_content($template);

        $weeks = $this->digArray($template->get_template_vars('chronology_calendar'), ['month_view', 'weeks']);
        $lastWeek = $this->digArray($weeks, [count($weeks) - 1]);

        self::assertCount(7, $lastWeek);
        self::assertArrayNotHasKey('DAY', $this->digArray($lastWeek, [count($lastWeek) - 1]));
    }

    public function test_build_month_calendar_populates_a_real_thumbnail_for_a_day_with_images(): void
    {
        // Item 16H: findRandomImageForDay()'s own by-id row feeds
        // new SrcImage($row) directly (CalendarMonthly.php:428) -- a
        // wrong key name there wouldn't crash (SrcImage's own
        // constructor defensively narrows every field to 0/''/null),
        // just silently produce a broken thumbnail, which neither
        // sibling test above observes (both only check the day-grid
        // layout). image 3 is fixture-dated 2024-07-04, the same
        // fixture row and month this file's own setUp() already
        // establishes.
        $calendar = new CalendarMonthly(LangTestFactory::get(), new CalendarRepository(EntityManagerFactory::build($this->conn)), $this->urlService, CurrentConfigTestFactory::get(), ImageStdParamsTestFactory::get());
        $calendar->chronology_field = 'posted';
        $calendar->initialize($this->makeScope('id = 3'));
        $calendar->chronology_view = CalendarBase::CAL_VIEW_CALENDAR;
        $calendar->chronology_date = [2024, 7];
        $template = TemplateTestFactory::build();

        $calendar->generate_category_content($template);

        $weeks = $this->digArray($template->get_template_vars('chronology_calendar'), ['month_view', 'weeks']);
        $dayCell = null;
        foreach ($weeks as $week) {
            foreach ((array) $week as $cell) {
                if (($this->dig($cell, ['DAY'])) === 4) {
                    $dayCell = $cell;
                }
            }
        }

        self::assertIsArray($dayCell);
        self::assertSame('fixture-photo-3.jpg', $this->dig($dayCell, ['IMAGE_ALT']));
        $image = $this->dig($dayCell, ['IMAGE']);
        self::assertIsString($image);
        self::assertNotSame('', $image);
    }
}

/**
 * Real fake for UrlServiceInterface -- CalendarBase/CalendarMonthly only
 * ever call duplicateIndexUrl() (see CalendarBase's own code); the real
 * Piwigo\Url\UrlService's own duplicateIndexUrl() falls through to
 * getAbsoluteRootUrl(), whose output depends on $_SERVER['SCRIPT_NAME']/
 * $_SERVER['HTTP_HOST'] (confirmed live: it echoed back this test
 * process's own invoking script path, not a gallery URL) -- entirely an
 * artifact of how the test binary happens to be invoked, not of anything
 * CalendarMonthly itself computes. This fake instead returns a
 * deterministic, exact encoding of the real $redefined/$removed params it
 * was called with, so assertions below verify the actual chronology_date/
 * removed-keys values CalendarMonthly's own grouping logic computed.
 */
final class CalendarMonthlyTestFakeUrlService implements UrlServiceInterface
{
    /** @var list<array{redefined: array<string, mixed>, removed: array<int, string>}> */
    public array $calls = [];

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
        $this->calls[] = ['redefined' => $redefined, 'removed' => $removed];

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
        throw new LogicException('not used by CalendarMonthly');
    }

    #[Override]
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        throw new LogicException('not used by CalendarMonthly');
    }

    #[Override]
    public function getActionUrl($id, $whatPart, bool $download): string
    {
        throw new LogicException('not used by CalendarMonthly');
    }

    #[Override]
    public function getElementUrl(array $elementInfo): string
    {
        throw new LogicException('not used by CalendarMonthly');
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
        throw new LogicException('not used by CalendarMonthly');
    }

    #[Override]
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        throw new LogicException('not used by CalendarMonthly');
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
