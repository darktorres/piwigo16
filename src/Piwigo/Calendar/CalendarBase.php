<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlExpr;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;

/**
 * @package functions\calendar
 */
/**
 * Base class for monthly and weekly calendar styles
 */
abstract class CalendarBase
{
    /** db column on which this calendar works */
    public string $date_field = '';
    /** used for queries (INNER JOIN or normal) */
    public string $inner_sql = '';
    /** used to store db fields */
    /** @var array<mixed> */
    public array $calendar_levels = [];

    /**
     * Generate navigation bars for category page.
     *
     * @return boolean false indicates that thumbnails where not included
     */
    abstract public function generateCategoryContent();

    /**
     * Returns a sql WHERE subquery for the date field.
     *
     * @param int $max_levels (e.g. 2=only year and month)
     * @return string
     */
    abstract public function getDateWhere($max_levels = 3);

    /**
     * Initialize the calendar.
     *
     * @param string $inner_sql
     */
    public function initialize(mixed $inner_sql): void
    {
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];
        $chronologyField = $pageArr['chronology_field'] ?? '';
        if ($chronologyField === 'posted') {
            $this->date_field = 'date_available';
        } else {
            $this->date_field = 'date_creation';
        }
        $this->inner_sql = $inner_sql;
    }

    /**
     * Returns the calendar title (with HTML).
     */
    public function getDisplayName(): string
    {
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];
        $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];
        $res = '';

        for ($i = 0; $i < count($chronologyDate); $i++) {
            $res .= Config::levelSeparator();
            $component = $chronologyDate[$i];
            $componentTyped = is_int($component) ? $component : (is_string($component) ? $component : '');
            if (isset($chronologyDate[$i + 1])) {
                $sliced = array_slice($chronologyDate, 0, $i + 1);
                $url = UrlService::get()->duplicateIndexUrl(
                    [ 'chronology_date' => $sliced ],
                    [ 'start' ]
                );
                $res .=
                  '<a href="'.$url.'">'
                  .$this->getDateComponentLabel($i, $componentTyped)
                  .'</a>';
            } else {
                $res .=
                  '<span class="calInHere">'
                  .$this->getDateComponentLabel($i, $componentTyped)
                  .'</span>';
            }
        }
        return $res;
    }

    /**
     * Returns a display name for a date component optionally using labels.
     */
    protected function getDateComponentLabel(int $level, int|string $date_component): string
    {
        $level_data = $this->calendar_levels[$level] ?? [];
        $labels = is_array($level_data) ? ($level_data['labels'] ?? null) : null;
        $labelsArr = is_array($labels) ? $labels : null;

        $label = (string) $date_component;
        if ($labelsArr !== null && isset($labelsArr[$date_component])) {
            $rawLabel = $labelsArr[$date_component];
            $label = is_string($rawLabel) ? $rawLabel : (is_int($rawLabel) ? (string) $rawLabel : $label);
        } elseif ('any' === (string) $date_component) {
            $label = Lang::t('All');
        }
        return $label;
    }

    /**
     * Gets a nice display name for a date to be shown in previous/next links
     */
    protected function getDateNiceName(string $date): string
    {
        $date_components = explode('-', $date);
        $res = '';
        for ($i = count($date_components) - 1; $i >= 0; $i--) {
            if ('any' !== $date_components[$i]) {
                $label = $this->getDateComponentLabel($i, $date_components[$i]);
                if ($res != '') {
                    $res .= ' ';
                }
                $res .= $label;
            }
        }
        return $res;
    }

    /**
     * Creates a calendar navigation bar.
     *
     * @param array $date_components
     * @param array $items - hash of items to put in the bar (e.g. 2005,2006)
     * @param bool $show_any - adds any link to the end of the bar
     * @param bool $show_empty - shows all labels even those without items
     * @param array $labels - optional labels for items (e.g. Jan,Feb,...)
     * @return array
     */
    /**
     * @param array<mixed> $date_components
     * @param array<mixed> $items
     * @param array<mixed>|null $labels
     * @return array<mixed>
     */
    protected function getNavBarFromItems(
        array $date_components,
        array $items,
        bool $show_any,
        bool $show_empty = false,
        ?array $labels = null
    ): array {
        $nav_bar_datas = [];

        if (Config::calendarShowEmpty() and $show_empty and !empty($labels)) {
            foreach ($labels as $item => $label) {
                if (! isset($items[$item])) {
                    $items[$item] = -1;
                }
            }
            ksort($items);
        }

        foreach ($items as $item => $nb_images) {
            $label = $item;
            if (isset($labels[$item])) {
                $label = $labels[$item];
            }
            if ($nb_images == -1) {
                $tmp_datas = [
                  'LABEL' => $label,
                ];
            } else {
                $url = UrlService::get()->duplicateIndexUrl(
                    ['chronology_date' => array_merge($date_components, [$item])],
                    [ 'start' ]
                );
                $tmp_datas = [
                  'LABEL' => $label,
                  'URL' => $url,
                ];
            }
            if ($nb_images > 0) {
                $tmp_datas['NB_IMAGES'] = $nb_images;
            }
            $nav_bar_datas[] = $tmp_datas;

        }

        if (Config::calendarShowAny() and $show_any and count($items) > 1 and
              count($date_components) < count($this->calendar_levels) - 1) {
            $url = UrlService::get()->duplicateIndexUrl(
                ['chronology_date' => array_merge($date_components, ['any'])],
                [ 'start' ]
            );
            $nav_bar_datas[] = [
              'LABEL' => Lang::t('All'),
              'URL' => $url,
            ];
        }

        return $nav_bar_datas;
    }

    /**
     * Creates a calendar navigation bar for a given level.
     *
     * @param int $level - 0-year, 1-month/week, 2-day
     */
    /** @param array<mixed>|null $labels */
    protected function buildNavBar(int $level, ?array $labels = null): void
    {
        $template = TemplateRegistry::current();
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];

        $level_data = $this->calendar_levels[$level] ?? [];
        $levelSql = is_array($level_data) ? (is_string($level_data['sql'] ?? null) ? $level_data['sql'] : '') : '';

        $query = '
SELECT DISTINCT('.$levelSql.') as period,
  COUNT(DISTINCT id) as nb_images'.
$this->inner_sql.
$this->getDateWhere($level).'
  GROUP BY period;';

        $level_items = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'nb_images', 'period');

        $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];

        if (count($level_items) == 1 and
             count($chronologyDate) < count($this->calendar_levels) - 1) {
            if (! isset($chronologyDate[$level])) {
                [$key] = array_keys($level_items);
                $chronologyDate[$level] = (int) $key;
                // Write back through the reference
                if (is_array($page)) {
                    $page['chronology_date'] = $chronologyDate;
                }

                if ($level < count($chronologyDate) and
                     $level != count($this->calendar_levels) - 1) {
                    return;
                }
            }
        }

        $dates = $chronologyDate;
        while ($level < count($dates)) {
            array_pop($dates);
        }

        $levelLabels = is_array($level_data) ? (is_array($level_data['labels'] ?? null) ? $level_data['labels'] : null) : null;

        $nav_bar = $this->getNavBarFromItems(
            $dates,
            $level_items,
            true,
            true,
            $labels ?? $levelLabels
        );

        $template->append(
            'chronology_navigation_bars',
            [
            'items' => $nav_bar,
            ]
        );
    }

    /**
     * Assigns the next/previous link to the template with regards to
     * the currently choosen date.
     */
    protected function buildNextPrev(): void
    {
        $template = TemplateRegistry::current();
        $page = &$GLOBALS['page'];
        $pageArr = is_array($page) ? $page : [];

        $chronologyDate = is_array($pageArr['chronology_date'] ?? null) ? $pageArr['chronology_date'] : [];

        $prev = $next = null;
        if (empty($chronologyDate)) {
            return;
        }

        $sub_queries = [];
        $nb_elements = count($chronologyDate);
        for ($i = 0; $i < $nb_elements; $i++) {
            $elem = $chronologyDate[$i];
            if ('any' === $elem) {
                $sub_queries[] = '\'any\'';
            } else {
                $level_data = $this->calendar_levels[$i] ?? [];
                $levelSql = is_array($level_data) ? (is_string($level_data['sql'] ?? null) ? $level_data['sql'] : '') : '';
                $sub_queries[] = ($levelSql);
            }
        }
        $query = 'SELECT '.SqlExpr::concatWs($sub_queries, '-').' AS period';
        $query .= $this->inner_sql .'
AND ' . $this->date_field . ' IS NOT NULL
GROUP BY period';

        $stringDate = [];
        foreach ($chronologyDate as $d) {
            $stringDate[] = is_string($d) ? $d : (is_int($d) ? (string) $d : '');
        }
        $current = implode('-', $stringDate);
        $upper_items = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'period');

        usort($upper_items, fn (mixed $a, mixed $b): int => version_compare(is_scalar($a) ? (string) $a : '', is_scalar($b) ? (string) $b : ''));
        $upper_items_str = array_map(fn (mixed $x): string => is_scalar($x) ? (string) $x : '', $upper_items);
        $upper_items_rank = array_flip($upper_items_str);
        if (!isset($upper_items_rank[$current])) {
            $upper_items_str[] = $current;// just in case (external link)
            usort($upper_items_str, fn (string $a, string $b): int => version_compare($a, $b));
            $upper_items_rank = array_flip($upper_items_str);
        }
        $current_rank = $upper_items_rank[$current];

        $tpl_var = [];

        if ($current_rank > 0) { // has previous
            $prev = $upper_items_str[$current_rank - 1];
            $chronology_date = explode('-', $prev);
            $tpl_var['previous'] =
              [
                'LABEL' => $this->getDateNiceName($prev),
                'URL' => UrlService::get()->duplicateIndexUrl(
                    ['chronology_date' => $chronology_date],
                    ['start']
                ),
              ];
        }

        if ($current_rank < count($upper_items_str) - 1) { // has next
            $next = $upper_items_str[$current_rank + 1];
            $chronology_date = explode('-', $next);
            $tpl_var['next'] =
              [
                'LABEL' => $this->getDateNiceName($next),
                'URL' => UrlService::get()->duplicateIndexUrl(
                    ['chronology_date' => $chronology_date],
                    ['start']
                ),
              ];
        }

        if (!empty($tpl_var)) {
            $existing = $template->smarty->getTemplateVars('chronology_navigation_bars');
            if (is_array($existing) && !empty($existing)) {
                $lastIdx = count($existing) - 1;
                $last = $existing[$lastIdx] ?? null;
                $existing[$lastIdx] = is_array($last) ? array_merge($last, $tpl_var) : $tpl_var;
                $template->assign('chronology_navigation_bars', $existing);
            } else {
                $template->append('chronology_navigation_bars', $tpl_var);
            }
        }
    }
}
