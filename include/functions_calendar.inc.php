<?php

declare(strict_types=1);

use Piwigo\Calendar\CalendarMonthly;
use Piwigo\Calendar\CalendarWeekly;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\calendar
 */

/** URL keyword for list view */
define('CAL_VIEW_LIST', 'list');
/** URL keyword for calendar view */
define('CAL_VIEW_CALENDAR', 'calendar');

/** Calendar date array indices */
define('CYEAR', 0);
define('CMONTH', 1);
define('CDAY', 2);
define('CWEEK', 1);


/**
 * Initialize _$page_ and _$template_ vars for calendar view.
 */
function initialize_calendar(): void
{
    $filter = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
    $persistent_cache = \Piwigo\Cache\PersistentCacheRegistry::current();

    $template = TemplateRegistry::current();
    $currentUser = CurrentUser::get();
    $user = $currentUser->rawAttributes;
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    //------------------ initialize the condition on items to take into account ---
    $inner_sql = ' FROM ' . IMAGES_TABLE;

    if (($page['section'] ?? null) == 'categories') { // we will regenerate the items by including subcats elements
        $page['items'] = [];
        $inner_sql .= '
INNER JOIN '.IMAGE_CATEGORY_TABLE.' ON id = image_id';

        if (isset($page['category']) && is_array($page['category'])) {
            $sub_ids = array_diff(
                get_subcat_ids([is_numeric($page['category']['id'] ?? null) ? (int) $page['category']['id'] : 0]),
                explode(',', is_scalar($user['forbidden_categories'] ?? null) ? (string) $user['forbidden_categories'] : '')
            );

            if (empty($sub_ids)) {
                return; // nothing to do
            }
            $inner_sql .= '
WHERE category_id IN ('.implode(',', $sub_ids).')';
            $inner_sql .= '
    '.get_sql_condition_FandF(
                [
                  'visible_images' => 'id',
                ],
                'AND',
                false
            );
        } else {
            $inner_sql .= '
    '.get_sql_condition_FandF(
                [
                  'forbidden_categories' => 'category_id',
                  'visible_categories' => 'category_id',
                  'visible_images' => 'id',
                ],
                'WHERE',
                true
            );
        }
    } else {
        if (empty($page['items'])) {
            return; // nothing to do
        }
        $items = [];
        if (is_array($page['items'])) {
            foreach ($page['items'] as $item) {
                if (is_int($item) || is_string($item)) {
                    $items[] = (string) (int) $item;
                }
            }
        }
        $inner_sql .= '
WHERE id IN (' . implode(',', $items) .')';
    }

    //-------------------------------------- initialize the calendar parameters ---
    pwg_debug('start initialize_calendar');

    $fields = [
      // Created
      'created' => [
        'label'          => l10n('Creation date'),
        ],
      // Posted
      'posted' => [
        'label'          => l10n('Post date'),
        ],
      ];

    $styles = [
      // Monthly style
      'monthly' => [
        'view_calendar'  => true,
        'classname'      => CalendarMonthly::class,
        ],
      // Weekly style
      'weekly' => [
        'view_calendar'  => false,
        'classname'      => CalendarWeekly::class,
        ],
      ];

    $views = [CAL_VIEW_LIST,CAL_VIEW_CALENDAR];

    // Retrieve calendar field
    $chronologyField = is_scalar($page['chronology_field'] ?? null) ? (string) $page['chronology_field'] : '';
    isset($fields[$chronologyField]) or fatal_error('bad chronology field');

    // Retrieve style
    $chronologyStyle = is_scalar($page['chronology_style'] ?? null) ? (string) $page['chronology_style'] : '';
    if (!isset($styles[$chronologyStyle])) {
        $page['chronology_style'] = 'monthly';
    }
    $cal_style = is_scalar($page['chronology_style'] ?? null) ? (string) $page['chronology_style'] : 'monthly';
    $calendar = match($cal_style) {
        'monthly' => new CalendarMonthly(),
        default   => new CalendarWeekly(),
    };

    // Retrieve view

    if (!isset($page['chronology_view']) or
         !in_array($page['chronology_view'], $views)) {
        $page['chronology_view'] = CAL_VIEW_LIST;
    }

    $styleEntry = $styles[$cal_style] ?? null;
    if (CAL_VIEW_CALENDAR == $page['chronology_view'] and
          is_array($styleEntry) and
          !$styleEntry['view_calendar']) {

        $page['chronology_view'] = CAL_VIEW_LIST;
    }

    // perform a sanity check on $requested
    if (!isset($page['chronology_date']) || !is_array($page['chronology_date'])) {
        $page['chronology_date'] = [];
    }
    while (count($page['chronology_date']) > 3) {
        array_pop($page['chronology_date']);
    }

    $any_count = 0;
    for ($i = 0; $i < count($page['chronology_date']); $i++) {
        if ($page['chronology_date'][$i] == 'any') {
            if ($page['chronology_view'] == CAL_VIEW_CALENDAR) {// we dont allow any in calendar view
                while ($i < count($page['chronology_date'])) {
                    array_pop($page['chronology_date']);
                }
                break;
            }
            $any_count++;
        } elseif ($page['chronology_date'][$i] == '') {
            while ($i < count($page['chronology_date'])) {
                array_pop($page['chronology_date']);
            }
        } else {
            $rawDate = $page['chronology_date'][$i];
            $page['chronology_date'][$i] = is_scalar($rawDate) ? (int) $rawDate : 0;
        }
    }
    if ($any_count == 3) {
        array_pop($page['chronology_date']);
    }

    $calendar->initialize($inner_sql);

    //echo ('<pre>'. var_export($calendar, true) . '</pre>');

    $must_show_list = true; // true until calendar generates its own display
    if (script_basename() != 'picture') { // basename without file extention
        if ($calendar->generate_category_content()) {
            $page['items'] = [];
            $must_show_list = false;
        }

        $page['comment'] = '';
        $template->assign('FILE_CHRONOLOGY_VIEW', 'month_calendar.tpl');

        foreach ($styles as $style => $style_data) {
            foreach ($views as $view) {
                if ($style_data['view_calendar'] or $view != CAL_VIEW_CALENDAR) {
                    $selected = false;

                    $chronologyDateAll = $page['chronology_date'];
                    if ($style != $cal_style) {
                        $chronology_date = [];
                        if (isset($chronologyDateAll[0])) {
                            $chronology_date[] = $chronologyDateAll[0];
                        }
                    } else {
                        $chronology_date = $chronologyDateAll;
                    }
                    $url = duplicate_index_url(
                        [
                          'chronology_style' => $style,
                          'chronology_view' => $view,
                          'chronology_date' => $chronology_date,
                          ]
                    );

                    if ($style == $cal_style and $view == $page['chronology_view']) {
                        $selected = true;
                    }

                    $template->append(
                        'chronology_views',
                        [
                        'VALUE' => $url,
                        'CONTENT' => l10n('chronology_'.$style.'_'.$view),
                        'SELECTED' => $selected,
                        ]
                    );
                }
            }
        }
        $url = duplicate_index_url(
            [],
            ['start', 'chronology_date']
        );
        $calendar_title = '<a href="'.$url.'">'
            .$fields[$chronologyField]['label'].'</a>';
        $calendar_title .= $calendar->get_display_name();
        $template->assign(
            'chronology',
            [
              'TITLE' => $calendar_title,
            ]
        );
    } // end category calling

    if ($must_show_list) {
        $chronologyDateList = $page['chronology_date'];
        if (isset($page['super_order_by'])) {
            $order_by = \Piwigo\Config\Config::orderBy();
        } else {
            if (count($chronologyDateList) == 0
                 or in_array('any', $chronologyDateList)) {// selected period is very big so we show newest first
                $order = ' DESC, ';
            } else {// selected period is small (month,week) so we show oldest first
                $order = ' ASC, ';
            }
            $order_by = str_replace(
                'ORDER BY ',
                'ORDER BY '.$calendar->date_field.$order,
                \Piwigo\Config\Config::orderBy()
            );
        }

        if ('categories' == ($page['section'] ?? null) && !isset($page['category'])
          && (count($chronologyDateList) == 0
                or ($chronologyDateList[0] == 'any' && count($chronologyDateList) == 1))
        ) {
            $cacheUpdateTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
            $cache_key = $persistent_cache->make_key($currentUser->id.$cacheUpdateTime
              .$calendar->date_field.$order_by);
        }

        if (!isset($cache_key) || !$persistent_cache->get($cache_key, $page['items'])) {
            $query = 'SELECT DISTINCT id '
              .$calendar->inner_sql.'
  '.$calendar->get_date_where().'
  '.$order_by;
            $page['items'] = \Piwigo\Db\QueryHelper::fetch($query, null, 'id');
            if (isset($cache_key)) {
                $persistent_cache->set($cache_key, $page['items']);
            }
        }
    }
    pwg_debug('end initialize_calendar');
}
