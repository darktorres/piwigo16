<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use Piwigo\Core\AccessLevel;
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
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $lang
         * @var array<string, mixed> $page
         * @var Template $template
         * @var array<string, mixed> $user
         */
        global $conf, $lang, $page, $template, $user;

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new HistoryService(new HistoryRepository(DbConnection::build()))->summarize();

        $template->set_filename('stats', 'stats.tpl');

        $tabsheet = new tabsheet();
        $tabsheet->set_id('history');
        $page_tab = isset($page['page']) && is_string($page['page']) ? $page['page'] : '';
        $tabsheet->select($page_tab);
        $tabsheet->assign();

        $base_url = get_root_url() . 'admin.php?page=history';

        $template->assign(
            [
                'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=history',
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

        // $lang['month'] is the language file's month-index (1-12) to name map;
        // narrow it once, sort it, then write the sorted copy back so both the
        // ksort() mutation and the join() below observe the same array.
        $lang_month = is_array($lang['month'] ?? null) ? $lang['month'] : [];
        ksort($lang_month);
        $lang['month'] = $lang_month;

        // $conf['stat_compare_year_displayed'] can come back as a numeric string
        // when overridden from the config table (see load_conf_from_db()), or the
        // int default from config_default.inc.php; narrow it once to the 'all'|int
        // shape getMonthOfLastYears() expects.
        $stat_compare_year_displayed = $conf['stat_compare_year_displayed'] ?? 5;
        if (is_numeric($stat_compare_year_displayed)) {
            $stat_compare_year_displayed = (int) $stat_compare_year_displayed;
        } elseif ($stat_compare_year_displayed === 'all') {
            $stat_compare_year_displayed = 'all';
        } else {
            $stat_compare_year_displayed = 5;
        }

        $user_language = $user['language'] ?? null;
        $user_language = is_scalar($user_language) ? $user_language : '';

        $template->assign([
            'compareYears' => self::getMonthOfLastYears($stat_compare_year_displayed),
            'monthStats' => self::getMonthStats(),
            'lastHours' => $last_hours,
            'lastDays' => $last_days,
            'lastMonths' => $last_months,
            'lastYears' => $last_years,
            'langCode' => strval($user_language),
            'month_labels' => join('~', array_filter($lang_month, is_string(...))),
            'ADMIN_PAGE_TITLE' => l10n('History'),
        ]);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'stats');
    }

    /**
     * Get the last unit of time for years, months, days and hours.
     *
     * @return list<array<string, string|null>>
     */
    private static function getLast(int $last_number = 60, string $type = 'year'): array
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

        $result = pwg_query($query);

        $output = [];
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $output[] = $row;
        }

        return $output;
    }

    /**
     * @param int|'all' $last
     * @return float[]|int[]
     */
    private static function getMonthOfLastYears($last = 'all'): array
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
            $limit = ($last - 1) * 12 + $date->format('n') - 1;
            $query .=
' LIMIT ' . $limit;
            $result = query2array($query . ';');
            $lastDate = $date->sub(new DateInterval('P' . ($last - 1) . 'Y' . ($date->format('n') - 1) . 'M'));
            return self::setMissingValues('month', $result, $lastDate, new DateTime());
        }

        if (count(query2array($query . ';')) > 1) {
            return self::setMissingValues('month', query2array($query . ';'));
        } else {
            $last_year_date = new DateTime();
            return self::setMissingValues(
                'month',
                query2array($query . ';'),
                $last_year_date->sub(new DateInterval('P1Y')),
                new DateTime()
            );
        }
    }

    /**
     * @return array<string, mixed>
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

        foreach (query2array($query) as $value) {
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
  (year = ' . ($date->format('Y') - 1) . ' and month > ' . $date->format('n') . ')
  )
  AND day IS NOT NULL
  AND hour IS NULL
ORDER BY
  year DESC,
  month DESC
;';

        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$result['avg']] = $row;

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

        if ($firstDate === null) {
            $date = self::getDateObject($data[count($data) - 1]);
        } else {
            $date = $firstDate;
        }
        if ($lastDate === null) {
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
