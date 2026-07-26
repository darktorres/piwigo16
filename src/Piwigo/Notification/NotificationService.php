<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\DerivativeImage;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionService;

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
        private NotificationRepository $repo,
        private PermissionService $permissionService,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
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

    private const array KNOWN_TYPES = ['new_comments', 'unvalidated_comments', 'new_elements', 'updated_categories', 'new_users'];

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
            || (\Piwigo\Auth\AccessControl::isAdmin() && $this->nbUnvalidatedComments($start, $end) > 0)
            || (\Piwigo\Auth\AccessControl::isAdmin() && $this->nbNewUsers($start, $end) > 0);
    }

    /**
     * @return list<array{date_available: ?string, nb_elements: int, nb_cats: int, elements?: list<array<string, mixed>>, categories?: list<array{uppercats: string, img_count: int}>}>
     */
    public function getRecentPostDates(int $maxDates, int $maxElements, int $maxCats): array
    {
        $currentUser = \Piwigo\Users\CurrentUser::get();
        $userId = (string) $currentUser->id;

        $pool = \Piwigo\Cache\CachePools::notifications();
        $cacheItem = $pool->getItem('recent_posts_' . $userId . '_' . $maxDates . '_' . $maxElements . '_' . $maxCats);
        if ($cacheItem->isHit()) {
            $cached = $cacheItem->get();
            if (is_array($cached)) {
                /** @var list<array{date_available: ?string, nb_elements: int, nb_cats: int, elements?: list<array<string, mixed>>, categories?: list<array{uppercats: string, img_count: int}>}> $cached */
                return $cached;
            }
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
            $line = Translator::get()->plural($singularKey, $pluralKey, $count);
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

        if (\Piwigo\Auth\AccessControl::isAdmin()) {
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

        $nbElements = $dateDetail['nb_elements'] ?? null;
        $nbElements = is_numeric($nbElements) ? (int) $nbElements : 0;

        $description .=
              '<li>'
              . Translator::get()->plural('%d new photo', '%d new photos', $nbElements)
              . ' ('
              . '<a href="' . $this->urlService->addUrlParams($this->urlService->makeIndexUrl([
                  'section' => 'recent_pics',
              ]), $addUrlParams) . '">'
                . Lang::t('Recent photos') . '</a>'
              . ')'
              . '</li><br>';

        $elements = $dateDetail['elements'] ?? [];
        $elements = is_array($elements) ? $elements : [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            /** @var array<string, mixed> $element */
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

        $nbCats = $dateDetail['nb_cats'] ?? null;
        $nbCats = is_numeric($nbCats) ? (int) $nbCats : 0;

        $description .=
              '<li>'
              . Translator::get()->plural('%d album updated', '%d albums updated', $nbCats)
              . '</li>';

        $description .= '<ul>';
        $categories = $dateDetail['categories'] ?? [];
        $categories = is_array($categories) ? $categories : [];
        foreach ($categories as $cat) {
            if (! is_array($cat)) {
                continue;
            }
            $uppercats = $cat['uppercats'] ?? null;
            $uppercats = is_string($uppercats) ? $uppercats : '';
            $imgCount = $cat['img_count'] ?? null;
            $imgCount = is_numeric($imgCount) ? (int) $imgCount : 0;
            $description .=
                  '<li>'
                  . $this->htmlRenderer->getCatDisplayNameCache($uppercats, '', false, null, $authKey)
                  . ' (' .
                  Translator::get()->plural('%d new photo', '%d new photos', $imgCount) . ')'
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
        $nbElements = $dateDetail['nb_elements'] ?? null;
        $nbElements = is_numeric($nbElements) ? (int) $nbElements : 0;
        $title = Translator::get()->plural('%d new photo', '%d new photos', $nbElements);

        $dateAvailable = $dateDetail['date_available'] ?? null;
        $dateAvailable = is_string($dateAvailable) ? $dateAvailable : '';
        if ((bool) preg_match('/^\d+-(\d+)-(\d+) /', $dateAvailable, $matches)) {
            $monthName = \Piwigo\Core\Lang::month((int) $matches[1]);
            $title .= ' (' . $monthName . ' ' . $matches[2] . ')';
        }

        return $title;
    }
}
