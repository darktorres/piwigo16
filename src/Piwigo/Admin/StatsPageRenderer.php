<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use DateInterval;
use DateTime;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryService;
use Piwigo\Template\Template;

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

        $conn = DbConnection::build();
        // Gap-closure Stage 4j (docs/plan/gap-closure-p0-p23.md): bounded to
        // match HistoryService::logVisit()'s own self-triggered summarize()
        // call -- an unbounded call here would rescan the entire remaining
        // `history` table in one request right after an admin uses the
        // Maintenance page's "Purge history summary" action.
        new HistoryService(new HistoryRepository($conn), $configService)
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
            self::getLast($conn, 72, 'hour'),
            $first_date->sub(new DateInterval('P3D')),
            $actual_date
        );

        $first_date = new DateTime();
        $last_days = self::setMissingValues(
            'day',
            self::getLast($conn, 90, 'day'),
            $first_date->sub(new DateInterval('P90D')),
            $actual_date
        );

        $first_date = new DateTime();
        $last_months = self::setMissingValues(
            'month',
            self::getLast($conn, 60, 'month'),
            $first_date->sub(new DateInterval('P60M')),
            $actual_date
        );

        if (count(self::getLast($conn, 60, 'year')) > 1) {
            $last_years = self::setMissingValues(
                'year',
                self::getLast($conn, 60, 'year')
            );
        } else {
            $last_year_date = new DateTime();
            $last_years = self::setMissingValues(
                'year',
                self::getLast($conn, 60, 'year'),
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
            'compareYears' => self::getMonthOfLastYears($conn, $stat_compare_year_displayed),
            'monthStats' => self::getMonthStats($conn),
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
     * @return list<array<string, mixed>>
     */
    private static function getLast(Connection $conn, int $last_number = 60, string $type = 'year'): array
    {
        $query = '
SELECT
    year,
    month,
    day,
    hour,
    nb_pages
  FROM ' . Tables::historySummary();

        if ($type === 'hour') {
            $query .= '
  WHERE year IS NOT NULL
    AND month IS NOT NULL
    AND day IS NOT NULL
    AND hour IS NOT NULL
  ORDER BY
    year DESC,
    month DESC,
    day DESC,
    hour DESC
  LIMIT ' . $last_number . '
;';
        } elseif ($type === 'day') {
            $query .= '
  WHERE year IS NOT NULL
    AND month IS NOT NULL
    AND day IS NOT NULL
    AND hour IS NULL
  ORDER BY
    year DESC,
    month DESC,
    day DESC
  LIMIT ' . $last_number . '
;';
        } elseif ($type === 'month') {
            $query .= '
  WHERE year IS NOT NULL
    AND month IS NOT NULL
    AND day IS NULL
  ORDER BY
    year DESC,
    month DESC
  LIMIT ' . $last_number . '
;';
        } else {
            $query .= '
  WHERE year IS NOT NULL
    AND month IS NULL
  ORDER BY
    year DESC
  LIMIT ' . $last_number . '
;';
        }

        $output = [];
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $output[] = $row;
        }

        return $output;
    }

    /**
     * @param int|'all' $last
     * @return float[]|int[]
     */
    private static function getMonthOfLastYears(Connection $conn, $last = 'all'): array
    {
        $query = '
SELECT
  year,
  month,
  day,
  hour,
  nb_pages
FROM ' . Tables::historySummary() . '
WHERE month IS NOT NULL
  AND day IS NULL
ORDER BY
  year DESC,
  month DESC';

        if ($last !== 'all') {
            $date = new DateTime();
            $limit = ($last - 1) * 12 + (int) $date->format('n') - 1;
            $query .=
' LIMIT ' . (string) $limit;
            $result = $conn->fetchAllAssociative($query . ';');
            $lastDate = $date->sub(new DateInterval('P' . ($last - 1) . 'Y' . ((int) $date->format('n') - 1) . 'M'));
            return self::setMissingValues('month', $result, $lastDate, new DateTime());
        }

        if (count($conn->fetchAllAssociative($query . ';')) > 1) {
            return self::setMissingValues('month', $conn->fetchAllAssociative($query . ';'));
        } else {
            $last_year_date = new DateTime();
            return self::setMissingValues(
                'month',
                $conn->fetchAllAssociative($query . ';'),
                $last_year_date->sub(new DateInterval('P1Y')),
                new DateTime()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function getMonthStats(Connection $conn): array
    {
        $result = [];
        $date = new DateTime();
        $date_last_month = clone $date;
        $date_last_year = clone $date;
        $months = [];

        $date_last_month->sub(new DateInterval('P1M'));
        $date_last_year->sub(new DateInterval('P1Y'));
        $query = '
SELECT
  year,
  month,
  day,
  hour,
  nb_pages
FROM ' . Tables::historySummary() . '
WHERE
  (
    (year = ' . $date->format('Y') . ' AND month = ' . $date->format('n') . ')
    OR (year = ' . $date_last_month->format('Y') . ' AND month = ' . $date_last_month->format('n') . ')
    OR (year = ' . $date_last_year->format('Y') . ' AND month = ' . $date_last_year->format('n') . ')
  )
  AND day IS NOT NULL
  AND hour IS NULL
ORDER BY
  year DESC,
  month DESC
;';

        foreach ($conn->fetchAllAssociative($query) as $value) {
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

        $query = '
SELECT
  AVG(nb_pages)
FROM ' . Tables::historySummary() . '
WHERE
  (
  year = ' . $date->format('Y') . ' OR
  (year = ' . ((int) $date->format('Y') - 1) . ' and month > ' . $date->format('n') . ')
  )
  AND day IS NOT NULL
  AND hour IS NULL
ORDER BY
  year DESC,
  month DESC
;';

        $row = $conn->fetchNumeric($query);
        $result['avg'] = is_array($row) ? $row[0] : null;

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
     * @param array<int, array<string, mixed>> $data
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
     * @param array<string, mixed> $row
     */
    public static function getDateObject(array $row): DateTime
    {
        $year = $row['year'];
        $date_string = is_string($year) ? $year : '';

        $month = $row['month'];
        if ($month !== null) {
            $date_string = $date_string . '-' . (is_string($month) ? $month : '');

            $day = $row['day'];
            if ($day !== null) {
                $date_string = $date_string . '-' . (is_string($day) ? $day : '');

                $hour = $row['hour'];
                if ($hour !== null) {
                    $date_string = $date_string . ' ' . (is_string($hour) ? $hour : '') . ':00';
                }
            }
        } else {
            $date_string .= '-1';
        }

        return new DateTime($date_string);
    }
}
