<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Cache\PersistentCache;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\DerivativeImage;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

/**
 * Get standard sql where in order to restrict and filter categories and images.
 * The image/category junction table must be named "ic" in the query
 *
 * @param string $prefix_condition
 * @param string $img_field
 * @param bool $force_one_condition
 */
function get_std_sql_where_restrict_filter(
    $prefix_condition,
    $img_field = 'ic.image_id',
    $force_one_condition = false
): string {
    global $persistent_cache;
    if (! $persistent_cache instanceof PersistentCache) {
        fatal_error('persistent cache not initialized');
    }

    $conn = DbConnection::build();

    return new NotificationService(
        new NotificationRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        $persistent_cache
    )->getSqlWhereRestrictFilter($prefix_condition, $img_field, $force_one_condition);
}

/**
 * Execute custom notification query.
 * @todo use a cache for all data returned by custom_notification_query()
 *
 * @param string $action 'count', 'info'
 * @param string $type 'new_comments', 'unvalidated_comments', 'new_elements', 'updated_categories', 'new_users'
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 * @return int|array<int>|null int for action count, int[] of ids for
 *   info, null for an unrecognized $type/$action
 */
function custom_notification_query($action, $type, $start = null, $end = null): null|int|array
{
    global $persistent_cache;
    if (! $persistent_cache instanceof PersistentCache) {
        fatal_error('persistent cache not initialized');
    }

    $conn = DbConnection::build();

    return new NotificationService(
        new NotificationRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        $persistent_cache
    )->customNotificationQuery($action, $type, $start, $end);
}

/**
 * Returns number of new comments between two dates.
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 */
function nb_new_comments($start = null, $end = null): int
{
    $result = custom_notification_query('count', 'new_comments', $start, $end);
    return is_int($result) ? $result : 0;
}

/**
 * Returns new comments between two dates.
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 * @return int[] comment ids
 */
function new_comments($start = null, $end = null): array
{
    $result = custom_notification_query('info', 'new_comments', $start, $end);
    return is_array($result) ? $result : [];
}

/**
 * Returns number of unvalidated comments between two dates.
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 */
function nb_unvalidated_comments($start = null, $end = null): int
{
    $result = custom_notification_query('count', 'unvalidated_comments', $start, $end);
    return is_int($result) ? $result : 0;
}

/**
 * Returns number of new photos between two dates.
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 */
function nb_new_elements($start = null, $end = null): int
{
    $result = custom_notification_query('count', 'new_elements', $start, $end);
    return is_int($result) ? $result : 0;
}

/**
 * Returns new photos between two dates.es
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 * @return int[] photos ids
 */
function new_elements($start = null, $end = null): array
{
    $result = custom_notification_query('info', 'new_elements', $start, $end);
    return is_array($result) ? $result : [];
}

/**
 * Returns number of updated categories between two dates.
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 */
function nb_updated_categories($start = null, $end = null): int
{
    $result = custom_notification_query('count', 'updated_categories', $start, $end);
    return is_int($result) ? $result : 0;
}

/**
 * Returns updated categories between two dates.
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 * @return int[] categories ids
 */
function updated_categories($start = null, $end = null): array
{
    $result = custom_notification_query('info', 'updated_categories', $start, $end);
    return is_array($result) ? $result : [];
}

/**
 * Returns number of new users between two dates.
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 */
function nb_new_users($start = null, $end = null): int
{
    $result = custom_notification_query('count', 'new_users', $start, $end);
    return is_int($result) ? $result : 0;
}

/**
 * Returns new users between two dates.
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 * @return int[] user ids
 */
function new_users($start = null, $end = null): array
{
    $result = custom_notification_query('info', 'new_users', $start, $end);
    return is_array($result) ? $result : [];
}

/**
 * Returns if there was new activity between two dates.
 *
 * Takes in account: number of new comments, number of new elements, number of
 * updated categories. Administrators are also informed about: number of
 * unvalidated comments, number of new users.
 * @todo number of unvalidated elements
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 */
function news_exists($start = null, $end = null): bool
{
    return
            (nb_new_comments($start, $end) > 0) or
            (nb_new_elements($start, $end) > 0) or
            (nb_updated_categories($start, $end) > 0) or
            ((is_admin()) and (nb_unvalidated_comments($start, $end) > 0)) or
            ((is_admin()) and (nb_new_users($start, $end) > 0));
}

/**
 * Formats a news line and adds it to the array (e.g. '5 new elements')
 *
 * @param array<int, string> $news
 * @param int $count
 * @param string $singular_key
 * @param string $plural_key
 * @param string $url
 * @param bool $add_url
 */
function add_news_line(array &$news, $count, $singular_key, $plural_key, $url = '', $add_url = false): void
{
    if ($count > 0) {
        $line = l10n_dec($singular_key, $plural_key, $count);
        if ($add_url and ! empty($url)) {
            $line = '<a href="' . $url . '">' . $line . '</a>';
        }
        $news[] = $line;
    }
}

/**
 * Returns new activity between two dates.
 *
 * Takes in account: number of new comments, number of new elements, number of
 * updated categories. Administrators are also informed about: number of
 * unvalidated comments, number of new users.
 * @todo number of unvalidated elements
 *
 * @param string $start (mysql datetime format)
 * @param string $end (mysql datetime format)
 * @param bool $exclude_img_cats if true, no info about new images/categories
 * @param bool $add_url add html link around news
 * @param string|null $auth_key
 * @return array<int, string>
 */
function news($start = null, $end = null, $exclude_img_cats = false, $add_url = false, $auth_key = null): array
{
    $news = [];

    $add_url_params = [];
    if (isset($auth_key)) {
        $add_url_params['auth'] = $auth_key;
    }

    if (! $exclude_img_cats) {
        add_news_line(
            $news,
            nb_new_elements($start, $end),
            '%d new photo',
            '%d new photos',
            add_url_params(make_index_url([
                'section' => 'recent_pics',
            ]), $add_url_params),
            $add_url
        );

        add_news_line(
            $news,
            nb_updated_categories($start, $end),
            '%d album updated',
            '%d albums updated',
            add_url_params(make_index_url([
                'section' => 'recent_cats',
            ]), $add_url_params),
            $add_url
        );
    }

    add_news_line(
        $news,
        nb_new_comments($start, $end),
        '%d new comment',
        '%d new comments',
        add_url_params(get_root_url() . 'comments.php', $add_url_params),
        $add_url
    );

    if (is_admin()) {
        add_news_line(
            $news,
            nb_unvalidated_comments($start, $end),
            '%d comment to validate',
            '%d comments to validate',
            get_root_url() . 'admin.php?page=comments',
            $add_url
        );

        add_news_line(
            $news,
            nb_new_users($start, $end),
            '%d new user',
            '%d new users',
            get_root_url() . 'admin.php?page=user_list',
            $add_url
        );
    }

    return $news;
}

/**
 * Returns information about recently published elements grouped by post date.
 *
 * @param int $max_dates maximum number of recent dates
 * @param int $max_elements maximum number of elements per date
 * @param int $max_cats maximum number of categories per date
 * @return array<int|string, mixed>
 */
function get_recent_post_dates($max_dates, $max_elements, $max_cats): array
{
    global $persistent_cache;
    if (! $persistent_cache instanceof PersistentCache) {
        fatal_error('persistent cache not initialized');
    }

    $conn = DbConnection::build();

    return new NotificationService(
        new NotificationRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
        $persistent_cache
    )->getRecentPostDates($max_dates, $max_elements, $max_cats);
}

/**
 * Returns information about recently published elements grouped by post date.
 * Same as get_recent_post_dates() but parameters as an indexed array.
 * @see get_recent_post_dates()
 *
 * @param array<string, int> $args
 * @return array<int|string, mixed>
 */
function get_recent_post_dates_array(array $args): array
{
    return get_recent_post_dates(
        (empty($args['max_dates']) ? 3 : $args['max_dates']),
        (empty($args['max_elements']) ? 3 : $args['max_elements']),
        (empty($args['max_cats']) ? 3 : $args['max_cats'])
    );
}

/**
 * Returns html description about recently published elements grouped by post date.
 * @todo clean up HTML output, currently messy and invalid !
 *
 * @param array<string, mixed> $date_detail returned value of get_recent_post_dates()
 * @param string|null $auth_key
 */
function get_html_description_recent_post_date(array $date_detail, $auth_key = null): string
{
    global $conf;

    $add_url_params = [];
    if (isset($auth_key)) {
        $add_url_params['auth'] = $auth_key;
    }

    $description = '<ul>';

    $nb_elements = $date_detail['nb_elements'] ?? null;
    $nb_elements = is_numeric($nb_elements) ? (int) $nb_elements : 0;

    $description .=
          '<li>'
          . l10n_dec('%d new photo', '%d new photos', $nb_elements)
          . ' ('
          . '<a href="' . add_url_params(make_index_url([
              'section' => 'recent_pics',
          ]), $add_url_params) . '">'
            . l10n('Recent photos') . '</a>'
          . ')'
          . '</li><br>';

    $elements = $date_detail['elements'] ?? [];
    $elements = is_array($elements) ? $elements : [];
    foreach ($elements as $element) {
        if (! is_array($element)) {
            continue;
        }
        /** @var array<string, mixed> $element */
        $tn_src = DerivativeImage::thumb_url($element);
        $description .= '<a href="' .
          add_url_params(
              make_picture_url(
                  [
                      'image_id' => $element['id'],
                      'image_file' => $element['file'],
                  ]
              ),
              $add_url_params
          )
          . '"><img src="' . $tn_src . '"></a>';
    }
    $description .= '...<br>';

    $nb_cats = $date_detail['nb_cats'] ?? null;
    $nb_cats = is_numeric($nb_cats) ? (int) $nb_cats : 0;

    $description .=
          '<li>'
          . l10n_dec('%d album updated', '%d albums updated', $nb_cats)
          . '</li>';

    $description .= '<ul>';
    $categories = $date_detail['categories'] ?? [];
    $categories = is_array($categories) ? $categories : [];
    foreach ($categories as $cat) {
        if (! is_array($cat)) {
            continue;
        }
        $uppercats = $cat['uppercats'] ?? null;
        $uppercats = is_string($uppercats) ? $uppercats : '';
        $img_count = $cat['img_count'] ?? null;
        $img_count = is_numeric($img_count) ? (int) $img_count : 0;
        $description .=
              '<li>'
              . get_cat_display_name_cache($uppercats, '', false, null, $auth_key)
              . ' (' .
              l10n_dec('%d new photo', '%d new photos', $img_count) . ')'
              . '</li>';
    }
    $description .= '</ul>';

    $description .= '</ul>';

    return $description;
}

/**
 * Returns title about recently published elements grouped by post date.
 *
 * @param array<string, mixed> $date_detail returned value of get_recent_post_dates()
 */
function get_title_recent_post_date(array $date_detail): string
{
    /** @var array<string, mixed> $lang */
    global $lang;

    $nb_elements = $date_detail['nb_elements'] ?? null;
    $nb_elements = is_numeric($nb_elements) ? (int) $nb_elements : 0;
    $title = l10n_dec('%d new photo', '%d new photos', $nb_elements);

    $date_available = $date_detail['date_available'] ?? null;
    $date_available = is_string($date_available) ? $date_available : '';
    if ((bool) preg_match('/^\d+-(\d+)-(\d+) /', $date_available, $matches)) {
        // $lang['month'] is the language file's month-index (1-12) to name map
        $lang_month = is_array($lang['month'] ?? null) ? $lang['month'] : [];
        $month_name = $lang_month[(int) $matches[1]] ?? '';
        $month_name = is_string($month_name) ? $month_name : '';
        $title .= ' (' . $month_name . ' ' . $matches[2] . ')';
    }

    return $title;
}
