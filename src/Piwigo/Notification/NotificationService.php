<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CachePools;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\DerivativeImage;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\Users\CurrentUser;

/**
 * "What's new" aggregation for the gallery: new comments, new elements,
 * updated categories, and (for admins) unvalidated comments and new
 * users.
 */
final readonly class NotificationService
{
    public function __construct(
        private Lang $lang,
        private AccessLevelChecker $accessLevelChecker,
        private NotificationRepository $repo,
        private PermissionService $permissionService,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private Translator $translator,
        private CurrentUser $currentUser,
    ) {}

    /**
     * The image/category junction table must be aliased "ic" in whatever
     * query this feeds into -- {@see NotificationRepository::buildQuery()}'s
     * own `new_comments` case (`comments c INNER JOIN image_category ic`).
     *
     * Applies imageAccessCondition rather than maxLevel: images are gated
     * here by the image_category access list, not by category level. The
     * field names are DQL property paths (`ic.categoryId`/`ic.imageId`),
     * not raw SQL column names.
     */
    public function getCommentsRestrictCondition(): SqlCondition
    {
        $criteria = $this->permissionService->getPermissionCriteria();

        return SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->visibleCategoriesCondition('ic.categoryId'),
            $criteria->visibleImagesCondition('ic.imageId'),
            $criteria->imageAccessCondition('ic.imageId'),
        );
    }

    /**
     * The images table must be aliased "i" in whatever query this feeds
     * into -- {@see NotificationRepository::buildQuery()}'s own
     * `new_elements`/`updated_categories` cases, and
     * findRecentPostDates()/findRecentElementsForDate()/
     * findRecentCategoriesForDate() below.
     *
     * Applies maxLevel against `i.level` rather than imageAccessCondition.
     * The field names are DQL property paths (`ic.categoryId`/`ic.imageId`);
     * `i.id`/`i.level` match ImageEntity's own property names directly.
     * findRecentElementsForDate() stays on a raw-SQL query (see that
     * method's own docblock), which this same condition applies to fine
     * since `SqlCondition`'s `sql` text is caller-composed either way.
     */
    public function getElementsRestrictCondition(): SqlCondition
    {
        $criteria = $this->permissionService->getPermissionCriteria();

        return SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->visibleCategoriesCondition('ic.categoryId'),
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
            || ($this->accessLevelChecker->isAdmin() && $this->nbUnvalidatedComments($start, $end) > 0)
            || ($this->accessLevelChecker->isAdmin() && $this->nbNewUsers($start, $end) > 0);
    }

    /**
     * @return list<array{date_available: ?string, nb_elements: int, nb_cats: int, elements?: list<array<string, mixed>>, categories?: list<array{uppercats: string, img_count: int}>}>
     */
    public function getRecentPostDates(int $maxDates, int $maxElements, int $maxCats): array
    {
        $userId = (string) $this->currentUser->get()
            ->id->value;

        $pool = CachePools::notifications();
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

        if ($this->accessLevelChecker->isAdmin()) {
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
