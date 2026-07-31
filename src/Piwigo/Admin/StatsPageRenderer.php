<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;

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
     * Legacy Coupling Retirement Track A batch A5.2f: $pageSlug is an
     * explicit param instead of `global $page['page'];` -- the one real
     * caller (StatsSubController) already knows its own fixed page slug
     * statically (it's the only class registered for the 'stats' slug
     * in config/admin_pages.php); selects this page's own tab within the
     * shared 'history' tabsheet group (see HistoryPageRenderer, its
     * sibling in that same group).
     */
    public function render(string $pageSlug, UrlServiceInterface $urlService, ConfigService $configService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        // Gap-closure Stage 4j (docs/plan/gap-closure-p0-p23.md): bounded to
        // match HistoryService::logVisit()'s own self-triggered summarize()
        // call -- an unbounded call here would rescan the entire remaining
        // `history` table in one request right after an admin uses the
        // Maintenance page's "Purge history summary" action.
        \Piwigo\Bootstrap\ExtendedDomainAccessor::historyService()
            ->summarize(50000);

        $template->set_filename('stats', 'stats.tpl');

        // Legacy Coupling Retirement Phase 8, 8g: real, previously-unfixed
        // bug -- nothing had ever called CoreTabs::setContext() with
        // linkStart for this page (same class of gap as
        // ConfigurationSubController's own $conf_link fix), so this page's
        // own tab strip has always rendered broken relative hrefs.
        CoreTabs::setContext(new CoreTabsContext(linkStart: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('history');
        $tabsheet->select($pageSlug);
        $tabsheet->assign();

        $base_url = $urlService->getRootUrl() . 'admin.php?page=history';

        $template->assign(
            [
                'U_HELP' => $urlService->getRootUrl() . 'admin/popuphelp.php?page=history',
                'F_ACTION' => $base_url,
            ]
        );

        $actual_date = new DateTime();
        $actual_date->add(new DateInterval('PT1S'));

        $first_date = new DateTime();
        $last_hours = self::setMissingValues(
            'hour',
            self::getLast(72, 'hour'),
            $first_date->sub(new DateInterval('P3D')),
            $actual_date
        );

        $first_date = new DateTime();
        $last_days = self::setMissingValues(
            'day',
            self::getLast(90, 'day'),
            $first_date->sub(new DateInterval('P90D')),
            $actual_date
        );

        $first_date = new DateTime();
        $last_months = self::setMissingValues(
            'month',
            self::getLast(60, 'month'),
            $first_date->sub(new DateInterval('P60M')),
            $actual_date
        );

        if (count(self::getLast(60, 'year')) > 1) {
            $last_years = self::setMissingValues(
                'year',
                self::getLast(60, 'year')
            );
        } else {
            $last_year_date = new DateTime();
            $last_years = self::setMissingValues(
                'year',
                self::getLast(60, 'year'),
                $last_year_date->sub(new DateInterval('P1Y')),
                new DateTime()
            );
        }

        // Lang::months() is the language file's month-index (1-12) to name
        // map; a local sorted copy is all join() below needs -- an earlier
        // version also wrote the sorted copy back into the global table
        // (dead: nothing downstream in this request re-reads it, and the
        // join() call already reads this local variable, not the global).
        $lang_month = \Piwigo\Core\Lang::months();
        ksort($lang_month);

        // CurrentConfig::statCompareYearDisplayed() is SCHEMA-typed 'int' only (no
        // 'all' sentinel) -- getMonthOfLastYears()'s own 'all' default is
        // unreachable from this call site, not dead code to resurrect here.
        $stat_compare_year_displayed = \Piwigo\Config\CurrentConfig::statCompareYearDisplayed();

        $template->assign([
            'compareYears' => self::getMonthOfLastYears($stat_compare_year_displayed),
            'monthStats' => self::getMonthStats(),
            'lastHours' => $last_hours,
            'lastDays' => $last_days,
            'lastMonths' => $last_months,
            'lastYears' => $last_years,
            'langCode' => \Piwigo\Users\CurrentUser::get()->language,
            'month_labels' => join('~', array_filter($lang_month, is_string(...))),
            'ADMIN_PAGE_TITLE' => Lang::t('History'),
        ]);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'stats');
    }

    /**
     * Get the last unit of time for years, months, days and hours.
     *
     * @return list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}>
     */
    private static function getLast(int $last_number = 60, string $type = 'year'): array
    {
        return \Piwigo\Bootstrap\ExtendedDomainAccessor::historyService()->getLastByType($type, $last_number);
    }

    /**
     * @param int|'all' $last
     * @return float[]|int[]
     */
    private static function getMonthOfLastYears($last = 'all'): array
    {
        $historyService = \Piwigo\Bootstrap\ExtendedDomainAccessor::historyService();

        if ($last !== 'all') {
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
    private static function getMonthStats(): array
    {
        $result = [];
        $date = new DateTime();
        $date_last_month = clone $date;
        $date_last_year = clone $date;
        $months = [];

        $date_last_month->sub(new DateInterval('P1M'));
        $date_last_year->sub(new DateInterval('P1Y'));

        $historyService = \Piwigo\Bootstrap\ExtendedDomainAccessor::historyService();

        foreach ($historyService->getDailyRowsForMonths(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date_last_month->format('Y'),
            (int) $date_last_month->format('n'),
            (int) $date_last_year->format('Y'),
            (int) $date_last_year->format('n')
        ) as $value) {
            /** @var array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null} $value */
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

        if (! $firstDate instanceof \DateTime) {
            $date = self::getDateObject($data[count($data) - 1]);
        } else {
            $date = $firstDate;
        }
        if (! $lastDate instanceof \DateTime) {
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
            $nb_pages = $value['nb_pages'] ?? null;
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
     * never a genuine string -- this used to check is_string() instead,
     * which is always false for the native-int case and silently built an
     * empty date-string segment (a real, previously-unnoticed bug: verified
     * against the same table's own history_summary read in
     * HistoryRepository, which already uses is_numeric() for this exact
     * column).
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
