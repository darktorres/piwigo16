<?php

declare(strict_types=1);

use Piwigo\Url\UrlGenerator;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;
use Piwigo\History\HistoryRepository;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
require_once(PHPWG_ROOT_PATH.'admin/include/functions_history.inc.php');

// +-----------------------------------------------------------------------+
// | Functions                                                             |
// +-----------------------------------------------------------------------+
//Get the last unit of time for years, months, days and hours
/**
 * @return mixed[]
 */
/** @return array<mixed> */
function get_last(int $last_number = 60, string $type = 'year'): array
{
    return ServiceLocator::get(HistoryRepository::class)
        ->findSummaryByType($type, $last_number);
}

/** @return array<mixed> */
function get_month_of_last_years(string|int $last = 'all'): array
{

    $query = '
SELECT
  year,
  month,
  day,
  hour,
  nb_pages
FROM '.HISTORY_SUMMARY_TABLE.'
WHERE month IS NOT NULL
  AND day IS NULL
ORDER BY
  year DESC,
  month DESC';

    if ($last !== 'all') {
        $date = new DateTime();
        $limit = ((int) $last - 1) * 12 + (int) $date->format('n') - 1;
        $query .=
' LIMIT '.$limit;
        $result = get_dbal_connection()->executeQuery($query.';')->fetchAllAssociative();
        $lastDate = $date->sub(new DateInterval('P'.((int) $last - 1).'Y'.((int) $date->format('n') - 1).'M'));
        return set_missing_values('month', $result, $lastDate, new DateTime());
    }

    if (count(get_dbal_connection()->executeQuery($query.';')->fetchAllAssociative()) > 1) {
        return set_missing_values('month', get_dbal_connection()->executeQuery($query.';')->fetchAllAssociative());
    } else {
        $last_year_date = new DateTime();
        return set_missing_values(
            'month',
            get_dbal_connection()->executeQuery($query.';')->fetchAllAssociative(),
            $last_year_date->sub(new DateInterval('P1Y')),
            new DateTime()
        );
    }
}

/** @return array<mixed> */
function get_month_stats(): array
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
FROM '.HISTORY_SUMMARY_TABLE.'
WHERE 
  (
    (year = '.$date->format('Y').' AND month = '.$date->format('n').')
    OR (year = '.$date_last_month->format('Y').' AND month = '.$date_last_month->format('n').')
    OR (year = '.$date_last_year->format('Y').' AND month = '.$date_last_year->format('n').')
  )
  AND day IS NOT NULL
  AND hour IS NULL
ORDER BY
  year DESC,
  month DESC
;';

    foreach (get_dbal_connection()->executeQuery($query)->fetchAllAssociative() as $value) {
        $date = get_date_object($value);
        $months[$date->format('Y/m/1')][] = $value;
    }

    $actual_date = new DateTime();
    if (!isset($months[$actual_date->format('Y/m/1')])) {
        $months[$actual_date->format('Y/m/1')][] = [
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
        $result['month'][] = set_missing_values('day', $val, new DateTime($key), $lastDate);
    }

    $result['avg'] = ServiceLocator::get(HistoryRepository::class)
        ->findCurrentPeriodDailyAvg((int) $date->format('Y'), (int) $date->format('n'));

    return $result;
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | Refresh summary from details                                          |
// +-----------------------------------------------------------------------+

history_summarize();

// +-----------------------------------------------------------------------+
// | Display statistics header                                             |
// +-----------------------------------------------------------------------+

$template->set_filename('stats', 'stats.tpl');

// TabSheet initialization
history_tabsheet();

$base_url = ServiceLocator::get(UrlGenerator::class)->admin('history');

$template->assign(
    [
    'U_HELP' => get_root_url().'admin/popuphelp.php?page=history',
    'F_ACTION' => $base_url,
    ]
);

// +-----------------------------------------------------------------------+
// | Set missing rows to 0                                                 |
// +-----------------------------------------------------------------------+
/**
 * @return float[]|int[]
 */
/**
 * @param array<mixed> $data
 * @return array<mixed>
 */
function set_missing_values(string $unit, array $data, DateTime|null $firstDate = null, DateTime|null $lastDate = null): array
{
    $limit = count($data);
    $result = [];

    if ($firstDate == null) {
        $data_last = $data[count($data) - 1] ?? null;
        $date = is_array($data_last) ? get_date_object($data_last) : new \DateTime();
    } else {
        $date = $firstDate;
    }
    if ($lastDate == null) {
        $data_first = $data[0] ?? null;
        $date_end = is_array($data_first) ? get_date_object($data_first) : new \DateTime();
    } else {
        $date_end = $lastDate;
    }

    //Declare variable according the unit
    $date_format = 'Y-m-d';
    $date_add = 'P1D';
    if ($unit == 'year') {
        $date_format = 'Y';
        $date_add = 'P1Y';
    } elseif ($unit == 'month') {
        $date_format = 'Y-m';
        $date_add = 'P1M';
    } elseif ($unit == 'day') {
        $date_format = 'Y-m-d';
        $date_add = 'P1D';
    } elseif ($unit == 'hour') {
        $date_format = 'Y-m-d\TH:00';
        $date_add = 'PT1H';
    }

    //Fill an empty array with all the dates
    while ($date <= $date_end) {
        $result[$date->format($date_format)] = 0;
        $date->add(new DateInterval($date_add));
    }

    //Overload with database rows
    foreach ($data as $value) {
        if (!is_array($value)) {
            continue;
        }
        $str = get_date_object($value)->format($date_format);
        if (isset($result[$str])) {
            $result[$str] += is_numeric($value['nb_pages'] ?? null) ? (int) $value['nb_pages'] : 0;
        }
    }

    return $result;
}

//Get a DateTime object for a database row
/** @param array<mixed> $row */
function get_date_object(array $row): \DateTime
{
    $date_string = is_scalar($row['year'] ?? null) ? (string) $row['year'] : '2000';
    if (($row['month'] ?? null) != null) {
        $date_string = $date_string.'-'.(is_scalar($row['month']) ? (string) $row['month'] : '1');
        if (($row['day'] ?? null) != null) {
            $date_string = $date_string.'-'.(is_scalar($row['day']) ? (string) $row['day'] : '1');
            if (($row['hour'] ?? null) != null) {
                $date_string = $date_string.' '.(is_scalar($row['hour']) ? (string) $row['hour'] : '0').':00';
            }
        }
    } else {
        $date_string .= '-1';
    }

    return new DateTime($date_string);
}

// +-----------------------------------------------------------------------+
// | Send data to template                                                 |
// +-----------------------------------------------------------------------+

$actual_date = new DateTime();
$actual_date->add(new DateInterval('PT1S'));

$first_date = new DateTime();
$last_hours = set_missing_values(
    'hour',
    get_last(72, 'hour'),
    $first_date->sub(new DateInterval('P3D')),
    $actual_date
);

$first_date = new DateTime();
$last_days = set_missing_values(
    'day',
    get_last(90, 'day'),
    $first_date->sub(new DateInterval('P90D')),
    $actual_date
);

$first_date = new DateTime();
$last_months = set_missing_values(
    'month',
    get_last(60, 'month'),
    $first_date->sub(new DateInterval('P60M')),
    $actual_date
);

if (count(get_last(60, 'year')) > 1) {
    $last_years = set_missing_values(
        'year',
        get_last(60, 'year')
    );
} else {
    $last_year_date = new DateTime();
    $last_years = set_missing_values(
        'year',
        get_last(60, 'year'),
        $last_year_date->sub(new DateInterval('P1Y')),
        new DateTime()
    );
}

if (is_array($lang['month'] ?? null)) {
    ksort($lang['month']);
}

$template->assign([
  'compareYears' => get_month_of_last_years(Config::statCompareYearDisplayed()),
  'monthStats' => get_month_stats(),
  'lastHours' => $last_hours,
  'lastDays' => $last_days,
  'lastMonths' => $last_months,
  'lastYears' => $last_years,
  'ADMIN_PAGE_TITLE' => l10n('History'),
  'page_data_json' => json_encode([
    'str_avg' => l10n('Average last 12 months'),
    'str_number_page_visited' => l10n('Page Visited'),
    'str_months' => array_values(is_array($lang['month'] ?? null) ? $lang['month'] : []),
    'lang_code' => strval($user['language']),
  ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'stats');
