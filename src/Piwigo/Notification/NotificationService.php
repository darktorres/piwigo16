<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\DerivativeImage;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;

/**
 * "What's new" aggregation, ported from the deleted
 * `include/functions_notification.inc.php`'s 18 functions (P23 batch 8c
 * folded the remaining 4 -- `addNewsLine()`/`news()`/
 * `getHtmlDescriptionRecentPostDate()`/`getTitleRecentPostDate()` -- in
 * here too; none read `$page`/`$template`, only `global $conf;` plus
 * URL-building/l10n free functions (make_index_url(), make_picture_url(),
 * DerivativeImage::thumb_url()), same "global read inside a service
 * method" precedent as TagService::addLevelToTags().
 */
final readonly class NotificationService
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private NotificationRepository $repo,
        private PermissionService $permissionService,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private Translator $translator,
        private \Piwigo\Users\CurrentUser $currentUser,
    ) {}

    /**
     * The image/category junction table must be aliased "ic" in whatever
     * query this feeds into -- {@see NotificationRepository::buildQuery()}'s
     * own `new_comments` case (`comments c INNER JOIN image_category ic`).
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: replaces the old
     * `getSqlWhereRestrictCondition('ic.image_id')` default -- fieldName
     * `'ic.image_id'` isn't `'id'`/`'i.id'`, so the old
     * `getSqlConditionFandFAsCondition()`'s own `visible_images` fallthrough
     * into `forbidden_images` hit the `image_access_list` branch, not the
     * level check; imageAccessCondition applies here, not maxLevel. Every
     * real consumer of the returned SqlCondition applies it via
     * `applyCondition()`'s own `isEmpty()`-skip (never an unguarded
     * splice), so dropping the old `$forceOneCondition` parameter (no real
     * caller ever passed `true` for this specific field) changes nothing.
     */
    public function getCommentsRestrictCondition(): SqlCondition
    {
        $criteria = $this->permissionService->getPermissionCriteria();

        return SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.category_id'),
            $criteria->visibleCategoriesCondition('ic.category_id'),
            $criteria->visibleImagesCondition('ic.image_id'),
            $criteria->imageAccessCondition('ic.image_id'),
        );
    }

    /**
     * The images table must be aliased "i" in whatever query this feeds
     * into -- {@see NotificationRepository::buildQuery()}'s own
     * `new_elements`/`updated_categories` cases, and
     * findRecentPostDates()/findRecentElementsForDate()/
     * findRecentCategoriesForDate() below.
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: replaces the old
     * `getSqlWhereRestrictCondition('id')`/`getSqlWhereRestrictCondition('i.id',
     * true)` call sites -- fieldName `'id'`/`'i.id'` matches the old
     * `getSqlConditionFandFAsCondition()`'s own `$fieldName === 'id' ||
     * $fieldName === 'i.id'` branch, so the `visible_images` fallthrough
     * into `forbidden_images` hit the images-table's own level check;
     * maxLevel applies here, against `i.level`. Every real consumer
     * applies the returned SqlCondition via `applyCondition()`'s own
     * `isEmpty()`-skip, so dropping the old `$forceOneCondition` parameter
     * (only ever passed `true` by getRecentPostDates(), and a no-op for
     * that consumer too) changes nothing.
     */
    public function getElementsRestrictCondition(): SqlCondition
    {
        $criteria = $this->permissionService->getPermissionCriteria();

        return SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.category_id'),
            $criteria->visibleCategoriesCondition('ic.category_id'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        );
    }

    private const array KNOWN_TYPES = ['new_comments', 'unvalidated_comments', 'new_elements', 'updated_categories', 'new_users'];

    /**
     * @return int|list<int>|null null for an unrecognized $type/$action
     */
    public function customNotificationQuery(string $action, string $type, ?string $start = null, ?string $end = null): int|array|null
    {
        if (! in_array($type, self::KNOWN_TYPES, true)) {
            return null;
        }

        $restrictCondition = match ($type) {
            'new_comments' => $this->getCommentsRestrictCondition(),
            'new_elements', 'updated_categories' => $this->getElementsRestrictCondition(),
            default => new SqlCondition(''),
        };

        return match ($action) {
            'count' => $this->repo->countByType($type, $start, $end, $restrictCondition),
            'info' => $this->repo->findIdsByType($type, $start, $end, $restrictCondition),
            default => null,
        };
    }

    public function nbNewComments(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('new_comments', $start, $end, $this->getCommentsRestrictCondition());
    }

    /**
     * @return list<int>
     */
    public function newComments(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findIdsByType('new_comments', $start, $end, $this->getCommentsRestrictCondition());
    }

    public function nbUnvalidatedComments(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('unvalidated_comments', $start, $end, new SqlCondition(''));
    }

    public function nbNewElements(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('new_elements', $start, $end, $this->getElementsRestrictCondition());
    }

    /**
     * @return list<int>
     */
    public function newElements(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findIdsByType('new_elements', $start, $end, $this->getElementsRestrictCondition());
    }

    public function nbUpdatedCategories(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('updated_categories', $start, $end, $this->getElementsRestrictCondition());
    }

    /**
     * @return list<int>
     */
    public function updatedCategories(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findIdsByType('updated_categories', $start, $end, $this->getElementsRestrictCondition());
    }

    public function nbNewUsers(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countByType('new_users', $start, $end, new SqlCondition(''));
    }

    /**
     * @return list<int>
     */
    public function newUsers(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findIdsByType('new_users', $start, $end, new SqlCondition(''));
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
            || ($this->accessControl->isAdmin() && $this->nbUnvalidatedComments($start, $end) > 0)
            || ($this->accessControl->isAdmin() && $this->nbNewUsers($start, $end) > 0);
    }

    /**
     * @return list<array{date_available: ?string, nb_elements: int, nb_cats: int, elements?: list<array<string, mixed>>, categories?: list<array{uppercats: string, img_count: int}>}>
     */
    public function getRecentPostDates(int $maxDates, int $maxElements, int $maxCats): array
    {
        $userId = (string) $this->currentUser->get()
            ->id->value;

        $pool = \Piwigo\Cache\CachePools::notifications();
        $cacheItem = $pool->getItem('recent_posts_' . $userId . '_' . $maxDates . '_' . $maxElements . '_' . $maxCats);
        if ($cacheItem->isHit()) {
            $cached = $cacheItem->get();
            if (is_array($cached)) {
                /** @var list<array{date_available: ?string, nb_elements: int, nb_cats: int, elements?: list<array<string, mixed>>, categories?: list<array{uppercats: string, img_count: int}>}> $cached */
                return $cached;
            }
        }

        $restrictCondition = $this->getElementsRestrictCondition();

        $dates = $this->repo->findRecentPostDates($restrictCondition, $maxDates);

        $result = [];
        foreach ($dates as $date) {
            $dateAvailable = $date['date_available'] ?? null;
            $dateAvailable = is_string($dateAvailable) ? $dateAvailable : '';

            if ($maxElements > 0) {
                $date['elements'] = $this->repo->findRecentElementsForDate($restrictCondition, $dateAvailable, $maxElements);
            }

            if ($maxCats > 0) {
                $date['categories'] = $this->repo->findRecentCategoriesForDate($restrictCondition, $dateAvailable, $maxCats);
            }

            $result[] = $date;
        }

        $cacheItem->set($result);
        $pool->save($cacheItem);

        return $result;
    }

    /**
     * @param  array<string, int>  $args
     * @return list<array{date_available: ?string, nb_elements: int, nb_cats: int, elements?: list<array<string, mixed>>, categories?: list<array{uppercats: string, img_count: int}>}>
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

    /**
     * Formats a news line and adds it to the array (e.g. '5 new elements')
     *
     * @param array<int, string> $news
     */
    public function addNewsLine(array &$news, int $count, string $singularKey, string $pluralKey, string $url = '', bool $addUrl = false): void
    {
        if ($count > 0) {
            $line = $this->translator->plural($singularKey, $pluralKey, $count);
            if ($addUrl and $url !== '') {
                $line = '<a href="' . $url . '">' . $line . '</a>';
            }
            $news[] = $line;
        }
    }

    /**
     * Returns new activity between two dates.
     *
     * Takes in account: number of new comments, number of new elements,
     * number of updated categories. Administrators are also informed
     * about: number of unvalidated comments, number of new users.
     *
     * @return array<int, string>
     */
    public function news(?string $start = null, ?string $end = null, bool $excludeImgCats = false, bool $addUrl = false, ?string $authKey = null): array
    {
        $news = [];

        $addUrlParams = [];
        if ($authKey !== null) {
            $addUrlParams['auth'] = $authKey;
        }

        if (! $excludeImgCats) {
            $this->addNewsLine(
                $news,
                $this->nbNewElements($start, $end),
                '%d new photo',
                '%d new photos',
                $this->urlService->addUrlParams($this->urlService->makeIndexUrl([
                    'section' => 'recent_pics',
                ]), $addUrlParams),
                $addUrl
            );

            $this->addNewsLine(
                $news,
                $this->nbUpdatedCategories($start, $end),
                '%d album updated',
                '%d albums updated',
                $this->urlService->addUrlParams($this->urlService->makeIndexUrl([
                    'section' => 'recent_cats',
                ]), $addUrlParams),
                $addUrl
            );
        }

        $this->addNewsLine(
            $news,
            $this->nbNewComments($start, $end),
            '%d new comment',
            '%d new comments',
            $this->urlService->addUrlParams($this->urlService->getRootUrl() . 'comments.php', $addUrlParams),
            $addUrl
        );

        if ($this->accessControl->isAdmin()) {
            $this->addNewsLine(
                $news,
                $this->nbUnvalidatedComments($start, $end),
                '%d comment to validate',
                '%d comments to validate',
                $this->urlService->getRootUrl() . 'admin.php?page=comments',
                $addUrl
            );

            $this->addNewsLine(
                $news,
                $this->nbNewUsers($start, $end),
                '%d new user',
                '%d new users',
                $this->urlService->getRootUrl() . 'admin.php?page=user_list',
                $addUrl
            );
        }

        return $news;
    }

    /**
     * Returns html description about recently published elements grouped
     * by post date.
     *
     * @param array{date_available: ?string, nb_elements: int, nb_cats: int, elements?: list<array<string, mixed>>, categories?: list<array{uppercats: string, img_count: int}>} $dateDetail one element of getRecentPostDates()'s own return
     */
    public function getHtmlDescriptionRecentPostDate(array $dateDetail, ?string $authKey = null): string
    {

        $addUrlParams = [];
        if ($authKey !== null) {
            $addUrlParams['auth'] = $authKey;
        }

        $description = '<ul>';

        $nbElements = $dateDetail['nb_elements'];

        $description .=
              '<li>'
              . $this->translator->plural('%d new photo', '%d new photos', $nbElements)
              . ' ('
              . '<a href="' . $this->urlService->addUrlParams($this->urlService->makeIndexUrl([
                  'section' => 'recent_pics',
              ]), $addUrlParams) . '">'
                . $this->lang->t('Recent photos') . '</a>'
              . ')'
              . '</li><br>';

        $elements = $dateDetail['elements'] ?? [];
        foreach ($elements as $element) {
            $tnSrc = DerivativeImage::thumb_url($element);
            $description .= '<a href="' .
              $this->urlService->addUrlParams(
                  $this->urlService->makePictureUrl(
                      [
                          'image_id' => $element['id'],
                          'image_file' => $element['file'],
                      ]
                  ),
                  $addUrlParams
              )
              . '"><img src="' . $tnSrc . '"></a>';
        }
        $description .= '...<br>';

        $nbCats = $dateDetail['nb_cats'];

        $description .=
              '<li>'
              . $this->translator->plural('%d album updated', '%d albums updated', $nbCats)
              . '</li>';

        $description .= '<ul>';
        $categories = $dateDetail['categories'] ?? [];
        foreach ($categories as $cat) {
            $uppercats = $cat['uppercats'];
            $imgCount = $cat['img_count'];
            $description .=
                  '<li>'
                  . $this->htmlRenderer->getCatDisplayNameCache($uppercats, '', false, null, $authKey)
                  . ' (' .
                  $this->translator->plural('%d new photo', '%d new photos', $imgCount) . ')'
                  . '</li>';
        }
        $description .= '</ul>';

        $description .= '</ul>';

        return $description;
    }

    /**
     * Returns title about recently published elements grouped by post date.
     *
     * @param array{date_available: ?string, nb_elements: int, nb_cats: int, elements?: list<array<string, mixed>>, categories?: list<array{uppercats: string, img_count: int}>} $dateDetail one element of getRecentPostDates()'s own return
     */
    public function getTitleRecentPostDate(array $dateDetail): string
    {
        $nbElements = $dateDetail['nb_elements'];
        $title = $this->translator->plural('%d new photo', '%d new photos', $nbElements);

        $dateAvailable = $dateDetail['date_available'] ?? '';
        if ((bool) preg_match('/^\d+-(\d+)-(\d+) /', $dateAvailable, $matches)) {
            $monthName = $this->lang->month((int) $matches[1]);
            $title .= ' (' . $monthName . ' ' . $matches[2] . ')';
        }

        return $title;
    }
}
