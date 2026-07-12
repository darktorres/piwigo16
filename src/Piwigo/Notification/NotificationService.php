<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Cache\PersistentCache;
use Piwigo\Permission\PermissionService;

/**
 * "What's new" aggregation, ported from `include/functions_notification.inc.php`'s
 * 18 functions. `add_news_line()`/`news()`/`get_html_description_recent_post_date()`/
 * `get_title_recent_post_date()` stay as free-function delegates unchanged
 * -- all four are entirely `$page`/URL-building/l10n-coupled (make_index_url(),
 * make_picture_url(), DerivativeImage::thumb_url(), $lang) with no DB
 * access of their own, same split as every other P19 domain.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repo,
        private readonly PermissionService $permissionService,
        private readonly PersistentCache $cache,
    ) {}

    /**
     * The image/category junction table must be aliased "ic" in whatever
     * query this feeds into.
     */
    public function getSqlWhereRestrictFilter(?string $prefixCondition, string $imgField = 'ic.image_id', bool $forceOneCondition = false): string
    {
        return $this->permissionService->getSqlConditionFandF([
            'forbidden_categories' => 'ic.category_id',
            'visible_categories' => 'ic.category_id',
            'visible_images' => $imgField,
        ], $prefixCondition, $forceOneCondition);
    }

    private const KNOWN_TYPES = ['new_comments', 'unvalidated_comments', 'new_elements', 'updated_categories', 'new_users'];

    /**
     * @return int|list<int>|null null for an unrecognized $type/$action
     */
    public function customNotificationQuery(string $action, string $type, ?string $start = null, ?string $end = null): int|array|null
    {
        if (! in_array($type, self::KNOWN_TYPES, true)) {
            return null;
        }

        $restrictSql = match ($type) {
            'new_comments' => $this->getSqlWhereRestrictFilter('AND'),
            'new_elements', 'updated_categories' => $this->getSqlWhereRestrictFilter('AND', 'id'),
            default => '',
        };

        return match ($action) {
            'count' => $this->repo->countByType($type, $start, $end, $restrictSql),
            'info' => $this->repo->findIdsByType($type, $start, $end, $restrictSql),
            default => null,
        };
    }

    public function nbNewComments(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('new_comments', $start, $end, $this->getSqlWhereRestrictFilter('AND'));
    }

    /**
     * @return list<int>
     */
    public function newComments(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findIdsByType('new_comments', $start, $end, $this->getSqlWhereRestrictFilter('AND'));
    }

    public function nbUnvalidatedComments(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('unvalidated_comments', $start, $end, '');
    }

    public function nbNewElements(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('new_elements', $start, $end, $this->getSqlWhereRestrictFilter('AND', 'id'));
    }

    /**
     * @return list<int>
     */
    public function newElements(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findIdsByType('new_elements', $start, $end, $this->getSqlWhereRestrictFilter('AND', 'id'));
    }

    public function nbUpdatedCategories(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('updated_categories', $start, $end, $this->getSqlWhereRestrictFilter('AND', 'id'));
    }

    /**
     * @return list<int>
     */
    public function updatedCategories(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findIdsByType('updated_categories', $start, $end, $this->getSqlWhereRestrictFilter('AND', 'id'));
    }

    public function nbNewUsers(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('new_users', $start, $end, '');
    }

    /**
     * @return list<int>
     */
    public function newUsers(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findIdsByType('new_users', $start, $end, '');
    }

    /**
     * Administrators are also informed about unvalidated comments and new
     * users.
     */
    public function newsExists(?string $start = null, ?string $end = null): bool
    {
        return $this->nbNewComments($start, $end) > 0
            || $this->nbNewElements($start, $end) > 0
            || $this->nbUpdatedCategories($start, $end) > 0
            || (is_admin() && $this->nbUnvalidatedComments($start, $end) > 0)
            || (is_admin() && $this->nbNewUsers($start, $end) > 0);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getRecentPostDates(int $maxDates, int $maxElements, int $maxCats): array
    {
        /** @var array<string, mixed> $user */
        global $user;

        $userId = $user['id'] ?? null;
        $userId = is_scalar($userId) ? (string) $userId : '';
        $userCacheUpdateTime = $user['cache_update_time'] ?? null;
        $userCacheUpdateTime = is_scalar($userCacheUpdateTime) ? (string) $userCacheUpdateTime : '';

        $cacheKey = $this->cache->make_key('recent_posts' . $userId . $userCacheUpdateTime . $maxDates . $maxElements . $maxCats);
        $cached = null;
        if ($this->cache->get($cacheKey, $cached) && is_array($cached)) {
            /** @var array<int|string, mixed> $cached */
            return $cached;
        }

        $whereSql = $this->getSqlWhereRestrictFilter('WHERE', 'i.id', true);

        $dates = $this->repo->findRecentPostDates($whereSql, $maxDates);

        $result = [];
        foreach ($dates as $date) {
            $dateAvailable = $date['date_available'] ?? null;
            $dateAvailable = is_string($dateAvailable) ? $dateAvailable : '';

            if ($maxElements > 0) {
                $date['elements'] = $this->repo->findRecentElementsForDate($whereSql, $dateAvailable, $maxElements);
            }

            if ($maxCats > 0) {
                $date['categories'] = $this->repo->findRecentCategoriesForDate($whereSql, $dateAvailable, $maxCats);
            }

            $result[] = $date;
        }

        $this->cache->set($cacheKey, $result);

        return $result;
    }

    /**
     * @param  array<string, int>  $args
     * @return array<int|string, mixed>
     */
    public function getRecentPostDatesArray(array $args): array
    {
        $maxDates = $args['max_dates'] ?? 0;
        $maxElements = $args['max_elements'] ?? 0;
        $maxCats = $args['max_cats'] ?? 0;

        return $this->getRecentPostDates(
            $maxDates > 0 ? $maxDates : 3,
            $maxElements > 0 ? $maxElements : 3,
            $maxCats > 0 ? $maxCats : 3
        );
    }
}
