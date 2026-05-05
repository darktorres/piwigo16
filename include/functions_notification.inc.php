<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Notification\NotificationService;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\notification
 */

function get_std_sql_where_restrict_filter(string $prefix_condition, string $img_field = 'ic.image_id', bool $force_one_condition = false): string
{
    return ServiceLocator::get(NotificationService::class)->getStdSqlWhereRestrictFilter($prefix_condition, $img_field, $force_one_condition);
}

/** @return array<mixed>|int|null */
function custom_notification_query(string $action, string $type, ?string $start = null, ?string $end = null): array|int|null
{
    return ServiceLocator::get(NotificationService::class)->customNotificationQuery($action, $type, $start, $end);
}

function nb_new_comments(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbNewComments($start, $end);
}

/** @return array<mixed> */
function new_comments(?string $start = null, ?string $end = null): array
{
    return ServiceLocator::get(NotificationService::class)->newComments($start, $end);
}

function nb_unvalidated_comments(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbUnvalidatedComments($start, $end);
}

function nb_new_elements(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbNewElements($start, $end);
}

/** @return array<mixed> */
function new_elements(?string $start = null, ?string $end = null): array
{
    return ServiceLocator::get(NotificationService::class)->newElements($start, $end);
}

function nb_updated_categories(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbUpdatedCategories($start, $end);
}

/** @return array<mixed> */
function updated_categories(?string $start = null, ?string $end = null): array
{
    return ServiceLocator::get(NotificationService::class)->updatedCategories($start, $end);
}

function nb_new_users(?string $start = null, ?string $end = null): mixed
{
    return ServiceLocator::get(NotificationService::class)->nbNewUsers($start, $end);
}

/** @return array<mixed> */
function new_users(?string $start = null, ?string $end = null): array
{
    return ServiceLocator::get(NotificationService::class)->newUsers($start, $end);
}

function news_exists(?string $start = null, ?string $end = null): bool
{
    return ServiceLocator::get(NotificationService::class)->newsExists($start, $end);
}

/** @param array<mixed> $news */
function add_news_line(array &$news, int $count, string $singular_key, string $plural_key, ?string $url = '', bool $add_url = false): void
{
    ServiceLocator::get(NotificationService::class)->addNewsLine($news, $count, $singular_key, $plural_key, $url, $add_url);
}

/** @return string[] */
function news(?string $start = null, ?string $end = null, bool $exclude_img_cats = false, bool $add_url = false, ?string $auth_key = null): array
{
    return ServiceLocator::get(NotificationService::class)->news($start, $end, $exclude_img_cats, $add_url, $auth_key);
}

/** @return array<mixed>|null */
function get_recent_post_dates(int $max_dates, int $max_elements, int $max_cats): ?array
{
    return ServiceLocator::get(NotificationService::class)->getRecentPostDates($max_dates, $max_elements, $max_cats);
}

/**
 * @param array<mixed> $args
 * @return array<mixed>
 */
function get_recent_post_dates_array(array $args): array
{
    return ServiceLocator::get(NotificationService::class)->getRecentPostDatesArray($args);
}

/** @param array<mixed> $date_detail */
function get_html_description_recent_post_date(array $date_detail, ?string $auth_key = null): string
{
    return ServiceLocator::get(NotificationService::class)->getHtmlDescriptionRecentPostDate($date_detail, $auth_key);
}

/** @param array<mixed> $date_detail */
function get_title_recent_post_date(array $date_detail): string
{
    return ServiceLocator::get(NotificationService::class)->getTitleRecentPostDate($date_detail);
}
