<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Config\Config;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

final class CalendarService
{
    public function initializeCalendar(): void
    {
        $filter          = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
        $persistentCache = PersistentCacheRegistry::current();
        $template        = TemplateRegistry::current();
        $currentUser     = CurrentUser::get();
        $user            = $currentUser->rawAttributes;
        $page            = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }

        $innerSql = ' FROM ' . IMAGES_TABLE;

        if (($page['section'] ?? null) == 'categories') {
            $page['items'] = [];
            $innerSql .= '
INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id = image_id';

            if (isset($page['category']) && is_array($page['category'])) {
                $subIds = array_diff(
                    get_subcat_ids([is_numeric($page['category']['id'] ?? null) ? (int) $page['category']['id'] : 0]),
                    explode(',', is_scalar($user['forbidden_categories'] ?? null) ? (string) $user['forbidden_categories'] : '')
                );

                if (empty($subIds)) {
                    return;
                }
                $innerSql .= '
WHERE category_id IN (' . implode(',', $subIds) . ')';
                $innerSql .= '
    ' . get_sql_condition_FandF(['visible_images' => 'id'], 'AND', false);
            } else {
                $innerSql .= '
    ' . get_sql_condition_FandF(
                    ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'],
                    'WHERE',
                    true
                );
            }
        } else {
            if (empty($page['items'])) {
                return;
            }
            $items = [];
            if (is_array($page['items'])) {
                foreach ($page['items'] as $item) {
                    if (is_int($item) || is_string($item)) {
                        $items[] = (string) (int) $item;
                    }
                }
            }
            $innerSql .= '
WHERE id IN (' . implode(',', $items) . ')';
        }

        pwg_debug('start initialize_calendar');

        $fields = [
            'created' => ['label' => l10n('Creation date')],
            'posted'  => ['label' => l10n('Post date')],
        ];

        $styles = [
            'monthly' => ['view_calendar' => true,  'classname' => CalendarMonthly::class],
            'weekly'  => ['view_calendar' => false, 'classname' => CalendarWeekly::class],
        ];

        $views = [CAL_VIEW_LIST, CAL_VIEW_CALENDAR];

        $chronologyField = is_scalar($page['chronology_field'] ?? null) ? (string) $page['chronology_field'] : '';
        isset($fields[$chronologyField]) or fatal_error('bad chronology field');

        $chronologyStyle = is_scalar($page['chronology_style'] ?? null) ? (string) $page['chronology_style'] : '';
        if (!isset($styles[$chronologyStyle])) {
            $page['chronology_style'] = 'monthly';
        }
        $calStyle = is_scalar($page['chronology_style'] ?? null) ? (string) $page['chronology_style'] : 'monthly';
        $calendar = match ($calStyle) {
            'monthly' => new CalendarMonthly(),
            default   => new CalendarWeekly(),
        };

        if (!isset($page['chronology_view']) or !in_array($page['chronology_view'], $views)) {
            $page['chronology_view'] = CAL_VIEW_LIST;
        }

        $styleEntry = $styles[$calStyle] ?? null;
        if (CAL_VIEW_CALENDAR == $page['chronology_view'] and
              is_array($styleEntry) and
              !$styleEntry['view_calendar']) {
            $page['chronology_view'] = CAL_VIEW_LIST;
        }

        if (!isset($page['chronology_date']) || !is_array($page['chronology_date'])) {
            $page['chronology_date'] = [];
        }
        while (count($page['chronology_date']) > 3) {
            array_pop($page['chronology_date']);
        }

        $anyCount = 0;
        for ($i = 0; $i < count($page['chronology_date']); $i++) {
            if ($page['chronology_date'][$i] == 'any') {
                if ($page['chronology_view'] == CAL_VIEW_CALENDAR) {
                    while ($i < count($page['chronology_date'])) {
                        array_pop($page['chronology_date']);
                    }
                    break;
                }
                $anyCount++;
            } elseif ($page['chronology_date'][$i] == '') {
                while ($i < count($page['chronology_date'])) {
                    array_pop($page['chronology_date']);
                }
            } else {
                $rawDate = $page['chronology_date'][$i];
                $page['chronology_date'][$i] = is_scalar($rawDate) ? (int) $rawDate : 0;
            }
        }
        if ($anyCount == 3) {
            array_pop($page['chronology_date']);
        }

        $calendar->initialize($innerSql);

        $mustShowList = true;
        if (script_basename() != 'picture') {
            if ($calendar->generate_category_content()) {
                $page['items']  = [];
                $mustShowList   = false;
            }

            $page['comment'] = '';
            $template->assign('FILE_CHRONOLOGY_VIEW', 'month_calendar.tpl');

            foreach ($styles as $style => $styleData) {
                foreach ($views as $view) {
                    if ($styleData['view_calendar'] or $view != CAL_VIEW_CALENDAR) {
                        $selected         = false;
                        $chronologyDateAll = $page['chronology_date'];
                        if ($style != $calStyle) {
                            $chronologyDate = [];
                            if (isset($chronologyDateAll[0])) {
                                $chronologyDate[] = $chronologyDateAll[0];
                            }
                        } else {
                            $chronologyDate = $chronologyDateAll;
                        }
                        $url = duplicate_index_url([
                            'chronology_style' => $style,
                            'chronology_view'  => $view,
                            'chronology_date'  => $chronologyDate,
                        ]);

                        if ($style == $calStyle and $view == $page['chronology_view']) {
                            $selected = true;
                        }

                        $template->append('chronology_views', [
                            'VALUE'    => $url,
                            'CONTENT'  => l10n('chronology_' . $style . '_' . $view),
                            'SELECTED' => $selected,
                        ]);
                    }
                }
            }
            $url           = duplicate_index_url([], ['start', 'chronology_date']);
            $calendarTitle = '<a href="' . $url . '">' . $fields[$chronologyField]['label'] . '</a>';
            $calendarTitle .= $calendar->get_display_name();
            $template->assign('chronology', ['TITLE' => $calendarTitle]);
        }

        if ($mustShowList) {
            $chronologyDateList = $page['chronology_date'];
            if (isset($page['super_order_by'])) {
                $orderBy = Config::orderBy();
            } else {
                if (count($chronologyDateList) == 0 or in_array('any', $chronologyDateList)) {
                    $order = ' DESC, ';
                } else {
                    $order = ' ASC, ';
                }
                $orderBy = str_replace(
                    'ORDER BY ',
                    'ORDER BY ' . $calendar->date_field . $order,
                    Config::orderBy()
                );
            }

            if ('categories' == ($page['section'] ?? null) && !isset($page['category'])
              && (count($chronologyDateList) == 0
                    or ($chronologyDateList[0] == 'any' && count($chronologyDateList) == 1))
            ) {
                $cacheUpdateTime = is_scalar($user['cache_update_time'] ?? null) ? (string) $user['cache_update_time'] : '';
                $cacheKey        = $persistentCache->make_key($currentUser->id . $cacheUpdateTime . $calendar->date_field . $orderBy);
            }

            if (!isset($cacheKey) || !$persistentCache->get($cacheKey, $page['items'])) {
                $query = 'SELECT DISTINCT id '
                  . $calendar->inner_sql . '
  ' . $calendar->get_date_where() . '
  ' . $orderBy;
                $page['items'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
                if (isset($cacheKey)) {
                    $persistentCache->set($cacheKey, $page['items']);
                }
            }
        }
        pwg_debug('end initialize_calendar');
    }
}
