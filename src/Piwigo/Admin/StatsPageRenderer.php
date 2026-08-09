<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use Piwigo\Admin\Projection\StatsPageContext;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\History\HistoryService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;

/**
 * Ported from admin/stats.php (page slug "stats") -- a sibling top-level
 * page to "history" sharing the same tabsheet group (built inline here,
 * same shape as every other admin renderer's own tabsheet, selecting on
 * the real page slug, not a `?tab=` param). Its history_summary chart
 * queries are single-purpose view-shaping for this one page (date-bucketing
 * into hour/day/month/year series) -- no HistoryRepository method covers
 * this shape (findSummaryRowsForHierarchy() takes one specific year/month/
 * day/hour combination, not "last N buckets ordered descending"), matching
 * this project's established "page/template glue stays inline" precedent.
 */
final class StatsPageRenderer
{
    /**
     * getMonthOfLastYears()'s own sentinel default -- "no year limit, use
     * every real month-level history row" -- distinct from a real int
     * $last value (a bounded number of years back). Self-contained to
     * this class: CurrentConfig::statCompareYearDisplayed() is
     * schema-typed 'int' only, so the one real production call site can
     * never reach this branch (see that call site's own comment); only
     * reflection-driven tests exercise it directly.
     */
    private const string ALL_YEARS = 'all';

    /**
     * $pageSlug is an explicit param: the one real caller (StatsSubController)
     * already knows its own fixed page slug statically (it's the only class
     * registered for the 'stats' slug in config/admin_pages.php). Selects
     * this page's own tab within the shared 'history' tabsheet group (see
     * HistoryPageRenderer, its sibling in that same group).
     */
    public function render(Lang $lang, AccessControl $accessControl, string $pageSlug, UrlServiceInterface $urlService, ConfigService $configService, CoreTabs $coreTabs, CurrentUser $currentUser, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, HistoryService $historyService, EventDispatcher $eventDispatcher): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        // Bounded to match HistoryService::logVisit()'s own
        // self-triggered summarize() call -- an unbounded call here
        // would rescan the entire remaining `history` table in one
        // request right after an admin uses the Maintenance page's
        // "Purge history summary" action.
        $historyService
            ->summarize(50000);

        $template->set_filename('stats', 'stats.tpl');

        // CoreTabs::setContext() must be called with linkStart here so this
        // page's tab strip renders correct admin.php?page=... hrefs instead
        // of broken relative ones.
        $coreTabs->setContext(new CoreTabsContext(linkStart: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('history');
        $tabsheet->select($pageSlug, $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $base_url = $urlService->getRootUrl() . 'admin.php?page=history';

        $actual_date = new DateTime();
        $actual_date->add(new DateInterval('PT1S'));

        $first_date = new DateTime();
        $last_hours = self::setMissingValues(
            'hour',
            self::getLast($historyService, 72, 'hour'),
            $first_date->sub(new DateInterval('P3D')),
            $actual_date
        );

        $first_date = new DateTime();
        $last_days = self::setMissingValues(
            'day',
            self::getLast($historyService, 90, 'day'),
            $first_date->sub(new DateInterval('P90D')),
            $actual_date
        );

        $first_date = new DateTime();
        $last_months = self::setMissingValues(
            'month',
            self::getLast($historyService, 60, 'month'),
            $first_date->sub(new DateInterval('P60M')),
            $actual_date
        );

        if (count(self::getLast($historyService, 60, 'year')) > 1) {
            $last_years = self::setMissingValues(
                'year',
                self::getLast($historyService, 60, 'year')
            );
        } else {
            $last_year_date = new DateTime();
            $last_years = self::setMissingValues(
                'year',
                self::getLast($historyService, 60, 'year'),
                $last_year_date->sub(new DateInterval('P1Y')),
                new DateTime()
            );
        }

        // Lang::months() is the language file's month-index (1-12) to name
        // map; a local sorted copy is all join() below needs -- an earlier
        // version also wrote the sorted copy back into the global table
        // (dead: nothing downstream in this request re-reads it, and the
        // join() call already reads this local variable, not the global).
        $lang_month = $lang->months();
        ksort($lang_month);

        // CurrentConfig::statCompareYearDisplayed() is SCHEMA-typed 'int' only (no
        // self::ALL_YEARS sentinel) -- getMonthOfLastYears()'s own
        // self::ALL_YEARS default is unreachable from this call site, not
        // dead code to resurrect here.
        $stat_compare_year_displayed = $currentConfig->statCompareYearDisplayed;

        $template->assignContext(new StatsPageContext(
            helpUrl: $urlService->getRootUrl() . 'admin/popuphelp.php?page=history',
            formAction: $base_url,
            compareYears: self::getMonthOfLastYears($historyService, $stat_compare_year_displayed),
            monthStats: self::getMonthStats($historyService),
            lastHours: $last_hours,
            lastDays: $last_days,
            lastMonths: $last_months,
            lastYears: $last_years,
            langCode: $currentUser->get()
                ->language,
            monthLabels: join('~', array_filter($lang_month, is_string(...))),
            adminPageTitle: $lang->t('History'),
        ));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'stats');
    }

    /**
     * Get the last unit of time for years, months, days and hours.
     *
     * @return list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}>
     */
    private static function getLast(HistoryService $historyService, int $last_number = 60, string $type = 'year'): array
    {
        return $historyService->getLastByType($type, $last_number);
    }

    /**
     * @param int|self::ALL_YEARS $last
     * @return float[]|int[]
     */
    private static function getMonthOfLastYears(HistoryService $historyService, $last = self::ALL_YEARS): array
    {
        if ($last !== self::ALL_YEARS) {
            $date = new DateTime();
            $limit = ($last - 1) * 12 + (int) $date->format('n') - 1;
            $result = $historyService->getMonthlyRows($limit);
            $lastDate = $date->sub(new DateInterval('P' . ($last - 1) . 'Y' . ((int) $date->format('n') - 1) . 'M'));
            return self::setMissingValues('month', $result, $lastDate, new DateTime());
        }

        $allRows = $historyService->getMonthlyRows(null);
        if (count($allRows) > 1) {
            return self::setMissingValues('month', $allRows);
        } else {
            $last_year_date = new DateTime();
            return self::setMissingValues(
                'month',
                $allRows,
                $last_year_date->sub(new DateInterval('P1Y')),
                new DateTime()
            );
        }
    }

    /**
     * @return array{month?: list<array<int|string, float|int>>, avg: ?float}
     */
    private static function getMonthStats(HistoryService $historyService): array
    {
        $result = [];
        $date = new DateTime();
        $date_last_month = clone $date;
        $date_last_year = clone $date;
        $months = [];

        $date_last_month->sub(new DateInterval('P1M'));
        $date_last_year->sub(new DateInterval('P1Y'));

        foreach ($historyService->getDailyRowsForMonths(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date_last_month->format('Y'),
            (int) $date_last_month->format('n'),
            (int) $date_last_year->format('Y'),
            (int) $date_last_year->format('n')
        ) as $value) {
            $date = self::getDateObject($value);
            @$months[$date->format('Y/m/1')][] = $value;
        }

        $actual_date = new DateTime();
        if (! isset($months[$actual_date->format('Y/m/1')])) {
            @$months[$actual_date->format('Y/m/1')][] = [
                'year' => $actual_date->format('Y'),
                'month' => $actual_date->format('n'),
                'day' => null,
                'hour' => null,
                'nb_pages' => 0,
            ];
        }

        foreach ($months as $key => $val) {
            $lastDate = new DateTime($key);
            $lastDate = $lastDate->add(new DateInterval('P1M'));
            $lastDate = $lastDate->sub(new DateInterval('P1D'));
            if ($lastDate > new DateTime()) {
                $lastDate = new DateTime();
            }
            $result['month'][] = self::setMissingValues('day', $val, new DateTime($key), $lastDate);
        }

        $result['avg'] = $historyService->getAverageDailyPageViewsSince(
            (int) $date->format('Y'),
            (int) $date->format('Y') - 1,
            (int) $date->format('n')
        );

        return $result;
    }

    /**
     * Fills in zero-valued rows for every date bucket between $firstDate and
     * $lastDate at the given $unit granularity, then overlays the real rows
     * from $data on top -- $data is expected sorted DESC (see getLast()),
     * so $data[0] is the most recent row and $data[count($data)-1] the
     * oldest, which is why the no-explicit-dates fallback below reads from
     * those two ends.
     *
     * @param array<int, array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> $data
     * @return float[]|int[]
     */
    public static function setMissingValues(string $unit, array $data, ?DateTime $firstDate = null, ?DateTime $lastDate = null): array
    {
        $limit = count($data);
        $result = [];

        if (! $firstDate instanceof DateTime) {
            $date = self::getDateObject($data[count($data) - 1]);
        } else {
            $date = $firstDate;
        }
        if (! $lastDate instanceof DateTime) {
            $date_end = self::getDateObject($data[0]);
        } else {
            $date_end = $lastDate;
        }

        // Declare variable according the unit
        [$date_format, $date_add] = match ($unit) {
            'year' => ['Y', 'P1Y'],
            'month' => ['Y-m', 'P1M'],
            'day' => ['Y-m-d', 'P1D'],
            'hour' => ['Y-m-d\TH:00', 'PT1H'],
            default => throw new InvalidArgumentException('Invalid unit: ' . $unit),
        };

        // Fill an empty array with all the dates
        while ($date <= $date_end) {
            $result[$date->format($date_format)] = 0;
            $date->add(new DateInterval($date_add));
        }

        // Overload with database rows
        foreach ($data as $value) {
            $str = self::getDateObject($value)
                ->format($date_format);
            $nb_pages = $value['nb_pages'];
            if (isset($result[$str]) && is_numeric($nb_pages)) {
                $result[$str] += (int) $nb_pages;
            }
        }

        return $result;
    }

    /**
     * Builds a DateTime from a history_summary row -- the row's own
     * year/month/day/hour columns form a hierarchy (a NULL month means a
     * year-only summary row, a NULL day means a year+month row, etc.), so
     * this cascades through them in order rather than reading all 4
     * unconditionally.
     *
     * year/month/day/hour are smallint/tinyint columns (schema), so a raw
     * fetched value is int|string (native int under DBAL's mysqli driver,
     * numeric string under its pgsql driver -- see DbConnection::params()),
     * never a genuine string -- each value below is checked with
     * is_numeric(), matching HistoryRepository's own read of this same
     * column.
     *
     * @param array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages?: int|string|null} $row
     */
    public static function getDateObject(array $row): DateTime
    {
        // Every (string) cast below (on the is_numeric()-true branch only)
        // is redundant: $year/$month/$day/$hour are int|string per this
        // method's own docblock, and `.` concatenation stringifies an int
        // operand identically to an explicit (string) cast -- removing the
        // cast can't change the built $date_string. Confirmed while
        // investigating a mutation-testing gap.
        $year = $row['year'];
        $date_string = is_numeric($year) ? (string) $year : '';

        $month = $row['month'];
        if ($month !== null) {
            $date_string = $date_string . '-' . (is_numeric($month) ? (string) $month : '');

            $day = $row['day'];
            if ($day !== null) {
                $date_string = $date_string . '-' . (is_numeric($day) ? (string) $day : '');

                $hour = $row['hour'];
                if ($hour !== null) {
                    $date_string = $date_string . ' ' . (is_numeric($hour) ? (string) $hour : '') . ':00';
                }
            }
        } else {
            $date_string .= '-1';
        }

        return new DateTime($date_string);
    }
}
