<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Cache\PersistentCache;

/** URL keyword for list view */
define('CAL_VIEW_LIST', 'list');
/** URL keyword for calendar view */
define('CAL_VIEW_CALENDAR', 'calendar');

/**
 * Initialize _$page_ and _$template_ vars for calendar view.
 */
function initialize_calendar(): void
{
    /**
     * @var array<string, mixed> $page
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     * @var \Template $template
     */
    global $page, $conf, $user, $template, $persistent_cache, $filter;
    if (! $persistent_cache instanceof PersistentCache) {
        fatal_error('persistent cache not initialized');
    }

    // ------------------ initialize the condition on items to take into account ---
    $inner_sql = ' FROM ' . IMAGES_TABLE;

    if ($page['section'] == 'categories') { // we will regenerate the items by including subcats elements
        $page['items'] = [];
        $inner_sql .= '
INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id = image_id';

        if (isset($page['category'])) {
            $page_category = $page['category'];
            $category_id = is_array($page_category) && isset($page_category['id'])
                && (is_int($page_category['id']) || is_string($page_category['id']))
                ? $page_category['id']
                : null;
            $forbidden_categories = $user['forbidden_categories'] ?? null;
            $forbidden_categories = is_scalar($forbidden_categories) ? (string) $forbidden_categories : '';

            $sub_ids = $category_id === null ? [] : array_diff(
                get_subcat_ids([$category_id]),
                explode(',', $forbidden_categories)
            );

            if (empty($sub_ids)) {
                return; // nothing to do
            }
            $inner_sql .= '
WHERE category_id IN (' . implode(',', $sub_ids) . ')';
            $inner_sql .= '
    ' . get_sql_condition_FandF(
                [
                    'visible_images' => 'id',
                ],
                'AND',
                false
            );
        } else {
            $inner_sql .= '
    ' . get_sql_condition_FandF(
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
        if (empty($page['items']) || ! is_array($page['items'])) {
            return; // nothing to do
        }
        $inner_sql .= '
WHERE id IN (' . implode(',', array_filter($page['items'], is_scalar(...))) . ')';
    }

    // -------------------------------------- initialize the calendar parameters ---
    pwg_debug('start initialize_calendar');

    $fields = [
        // Created
        'created' => [
            'label' => l10n('Creation date'),
        ],
        // Posted
        'posted' => [
            'label' => l10n('Post date'),
        ],
    ];

    $styles = [
        // Monthly style
        'monthly' => [
            'include' => 'calendar_monthly.class.php',
            'view_calendar' => true,
            'classname' => 'CalendarMonthly',
        ],
        // Weekly style
        'weekly' => [
            'include' => 'calendar_weekly.class.php',
            'view_calendar' => false,
            'classname' => 'CalendarWeekly',
        ],
    ];

    $views = [CAL_VIEW_LIST, CAL_VIEW_CALENDAR];

    // Retrieve calendar field
    $chronology_field = $page['chronology_field'] ?? null;
    $chronology_field = is_string($chronology_field) ? $chronology_field : '';
    isset($fields[$chronology_field]) or fatal_error('bad chronology field');

    // Retrieve style
    $chronology_style = $page['chronology_style'] ?? null;
    $chronology_style = is_string($chronology_style) ? $chronology_style : null;
    if ($chronology_style === null || ! isset($styles[$chronology_style])) {
        $chronology_style = 'monthly';
    }
    $page['chronology_style'] = $chronology_style;
    $cal_style = $chronology_style;
    $classname = $styles[$cal_style]['classname'];

    include PHPWG_ROOT_PATH . 'include/' . $styles[$cal_style]['include'];
    $calendar = new $classname();

    // Retrieve view

    if (! isset($page['chronology_view']) or
         ! in_array($page['chronology_view'], $views)) {
        $page['chronology_view'] = CAL_VIEW_LIST;
    }

    if ($page['chronology_view'] == CAL_VIEW_CALENDAR and
          ! $styles[$cal_style]['view_calendar']) {

        $page['chronology_view'] = CAL_VIEW_LIST;
    }

    // perform a sanity check on $requested
    $page_chronology_date = $page['chronology_date'] ?? null;
    if (! is_array($page_chronology_date)) {
        $page_chronology_date = [];
    }
    while (count($page_chronology_date) > 3) {
        array_pop($page_chronology_date);
    }

    $any_count = 0;
    for ($i = 0; $i < count($page_chronology_date); $i++) {
        if ($page_chronology_date[$i] == 'any') {
            if ($page['chronology_view'] == CAL_VIEW_CALENDAR) {// we dont allow any in calendar view
                while ($i < count($page_chronology_date)) {
                    array_pop($page_chronology_date);
                }
                break;
            }
            $any_count++;
        } elseif ($page_chronology_date[$i] == '') {
            while ($i < count($page_chronology_date)) {
                array_pop($page_chronology_date);
            }
        } else {
            $chronology_date_part = $page_chronology_date[$i];
            $page_chronology_date[$i] = is_numeric($chronology_date_part) ? (int) $chronology_date_part : 0;
        }
    }
    if ($any_count == 3) {
        array_pop($page_chronology_date);
    }
    $page['chronology_date'] = $page_chronology_date;

    $calendar->initialize($inner_sql);

    // echo ('<pre>'. var_export($calendar, true) . '</pre>');

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

                    if ($style != $cal_style) {
                        $chronology_date = [];
                        if (isset($page_chronology_date[0])) {
                            $chronology_date[] = $page_chronology_date[0];
                        }
                    } else {
                        $chronology_date = $page_chronology_date;
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
                            'CONTENT' => l10n('chronology_' . $style . '_' . $view),
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
        $calendar_title = '<a href="' . $url . '">'
            . $fields[$chronology_field]['label'] . '</a>';
        $calendar_title .= $calendar->get_display_name();
        $template->assign(
            'chronology',
            [
                'TITLE' => $calendar_title,
            ]
        );
    } // end category calling

    if ($must_show_list) {
        $conf_order_by = $conf['order_by'] ?? null;
        $conf_order_by = is_string($conf_order_by) ? $conf_order_by : '';

        if (isset($page['super_order_by'])) {
            $order_by = $conf_order_by;
        } else {
            if (count($page_chronology_date) == 0
                 or in_array('any', $page_chronology_date)) {// selected period is very big so we show newest first
                $order = ' DESC, ';
            } else {// selected period is small (month,week) so we show oldest first
                $order = ' ASC, ';
            }
            $order_by = str_replace(
                'ORDER BY ',
                'ORDER BY ' . $calendar->date_field . $order,
                $conf_order_by
            );
        }

        if ($page['section'] == 'categories' && ! isset($page['category'])
          && (count($page_chronology_date) == 0
                or ($page_chronology_date[0] == 'any' && count($page_chronology_date) == 1))
        ) {
            $user_id = $user['id'] ?? null;
            $user_id = is_scalar($user_id) ? (string) $user_id : '';
            $user_cache_update_time = $user['cache_update_time'] ?? null;
            $user_cache_update_time = is_scalar($user_cache_update_time) ? (string) $user_cache_update_time : '';
            $cache_key = $persistent_cache->make_key($user_id . $user_cache_update_time
              . $calendar->date_field . $order_by);
        }

        $cache_key_str = isset($cache_key) && is_string($cache_key) ? $cache_key : null;

        if ($cache_key_str === null || ! $persistent_cache->get($cache_key_str, $page['items'])) {
            $query = 'SELECT DISTINCT id '
              . $calendar->inner_sql . '
  ' . $calendar->get_date_where() . '
  ' . $order_by;
            $page['items'] = array_from_query($query, 'id');
            if ($cache_key_str !== null) {
                $persistent_cache->set($cache_key_str, $page['items']);
            }
        }
    }
    pwg_debug('end initialize_calendar');
}
