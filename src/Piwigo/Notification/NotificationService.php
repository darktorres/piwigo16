<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Config\NotificationChannelConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Db\SqlFragment;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Lang\Translator;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\Cache\CacheItemPoolInterface;

final readonly class NotificationService
{
    public function __construct(
        private NotificationRepository $repo,
        private HtmlService $htmlService,
        private UrlGenerator $urlGenerator,
        private PermissionService $permissionService,
        private UrlService $urlService,
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function getStdSqlWhereRestrictFilter(string $prefixCondition, string $imgField = 'ic.image_id', bool $forceOneCondition = false): SqlFragment
    {
        return $this->permissionService->getSqlConditionFandF(
            [
                'forbidden_categories' => 'ic.category_id',
                'visible_categories'   => 'ic.category_id',
                'visible_images'       => $imgField,
            ],
            $prefixCondition,
            $forceOneCondition
        );
    }

    public function nbNewComments(?string $start = null, ?string $end = null): int
    {
        $perm = $this->getStdSqlWhereRestrictFilter('AND');
        return $this->repo->countNewComments($start, $end, $perm->where, $perm->params, $perm->types);
    }

    /** @return list<int> */
    public function newComments(?string $start = null, ?string $end = null): array
    {
        $perm = $this->getStdSqlWhereRestrictFilter('AND');
        return $this->repo->findNewCommentIds($start, $end, $perm->where, $perm->params, $perm->types);
    }

    public function nbUnvalidatedComments(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countUnvalidatedComments($start, $end);
    }

    public function nbNewElements(?string $start = null, ?string $end = null): int
    {
        $perm = $this->getStdSqlWhereRestrictFilter('AND', 'id');
        return $this->repo->countNewElements($start, $end, $perm->where, $perm->params, $perm->types);
    }

    /** @return list<int> */
    public function newElements(?string $start = null, ?string $end = null): array
    {
        $perm = $this->getStdSqlWhereRestrictFilter('AND', 'id');
        return $this->repo->findNewElementIds($start, $end, $perm->where, $perm->params, $perm->types);
    }

    public function nbUpdatedCategories(?string $start = null, ?string $end = null): int
    {
        $perm = $this->getStdSqlWhereRestrictFilter('AND', 'id');
        return $this->repo->countUpdatedCategories($start, $end, $perm->where, $perm->params, $perm->types);
    }

    /** @return list<int> */
    public function updatedCategories(?string $start = null, ?string $end = null): array
    {
        $perm = $this->getStdSqlWhereRestrictFilter('AND', 'id');
        return $this->repo->findUpdatedCategoryIds($start, $end, $perm->where, $perm->params, $perm->types);
    }

    public function nbNewUsers(?string $start = null, ?string $end = null): int
    {
        return $this->repo->countNewUsers($start, $end);
    }

    /** @return list<int> */
    public function newUsers(?string $start = null, ?string $end = null): array
    {
        return $this->repo->findNewUserIds($start, $end);
    }

    public function newsExists(?string $start = null, ?string $end = null): bool
    {
        return (
            ($this->nbNewComments($start, $end) > 0) or
            ($this->nbNewElements($start, $end) > 0) or
            ($this->nbUpdatedCategories($start, $end) > 0) or
            (($this->permissionService->isAdmin()) and ($this->nbUnvalidatedComments($start, $end) > 0)) or
            (($this->permissionService->isAdmin()) and ($this->nbNewUsers($start, $end) > 0)));
    }

    /**
     * @param array<mixed> $news
     */
    public function addNewsLine(array &$news, int $count, string $singularKey, string $pluralKey, string $url = '', bool $addUrl = false): void
    {
        if ($count > 0) {
            $line = Translator::get()->plural($singularKey, $pluralKey, $count);
            if ($addUrl and !empty($url)) {
                $line = '<a href="' . $url . '">' . $line . '</a>';
            }
            $news[] = $line;
        }
    }

    /** @return string[] */
    public function news(?string $start = null, ?string $end = null, bool $excludeImgCats = false, bool $addUrl = false, ?string $authKey = null): array
    {
        $newsArr        = [];
        $addUrlParams   = [];
        if (isset($authKey)) {
            $addUrlParams['auth'] = $authKey;
        }

        if (!$excludeImgCats) {
            $this->addNewsLine($newsArr, $this->nbNewElements($start, $end), '%d new photo', '%d new photos', $this->urlService->addUrlParams($this->urlService->makeIndexUrl(['section' => 'recent_pics']), $addUrlParams), $addUrl);
            $this->addNewsLine($newsArr, $this->nbUpdatedCategories($start, $end), '%d album updated', '%d albums updated', $this->urlService->addUrlParams($this->urlService->makeIndexUrl(['section' => 'recent_cats']), $addUrlParams), $addUrl);
        }

        $this->addNewsLine($newsArr, $this->nbNewComments($start, $end), '%d new comment', '%d new comments', $this->urlService->addUrlParams($this->urlGenerator->comments(), $addUrlParams), $addUrl);

        if ($this->permissionService->isAdmin()) {
            $this->addNewsLine($newsArr, $this->nbUnvalidatedComments($start, $end), '%d comment to validate', '%d comments to validate', $this->urlGenerator->admin('comments'), $addUrl);
            $this->addNewsLine($newsArr, $this->nbNewUsers($start, $end), '%d new user', '%d new users', $this->urlGenerator->admin('user_list'), $addUrl);
        }

        /** @var string[] $newsArr */
        return $newsArr;
    }

    /** @return array<mixed>|null */
    public function getRecentPostDates(int $maxDates, int $maxElements, int $maxCats): ?array
    {
        $userId      = CurrentUser::get()->id;
        $cacheUpdate = is_scalar(CurrentUser::get()->rawAttributes['cache_update_time'] ?? null)
            ? (string) CurrentUser::get()->rawAttributes['cache_update_time']
            : '';

        $cacheKey = md5('recent_posts' . $userId . $cacheUpdate . $maxDates . $maxElements . $maxCats . AppInfo::VERSION);
        $item     = $this->pool->getItem($cacheKey);
        if ($item->isHit()) {
            $cached = $item->get();
            return is_array($cached) ? $cached : null;
        }
        $where = $this->getStdSqlWhereRestrictFilter('WHERE', 'i.id', true);

        $dates = $this->repo->findRecentPostDates($maxDates, $where->where, $where->params, $where->types);

        for ($i = 0; $i < count($dates); $i++) {
            $dateAvailable = is_scalar($dates[$i]['date_available']) ? (string) $dates[$i]['date_available'] : '';
            if ($maxElements > 0) {
                $dates[$i]['elements'] = $this->repo->findRecentImagesForDate($dateAvailable, $maxElements, $where->where, $where->params, $where->types);
            }
            if ($maxCats > 0) {
                $dates[$i]['categories'] = $this->repo->findRecentCategoriesForDate($dateAvailable, $maxCats, $where->where, $where->params, $where->types);
            }
        }

        $item->set($dates);
        $item->expiresAfter(86400);
        $this->pool->save($item);
        return $dates;
    }

    /** @return array<mixed> */
    public function getRecentPostDatesArray(NotificationChannelConfig $config): array
    {
        return $this->getRecentPostDates($config->maxDates, $config->maxElements, $config->maxCats) ?? [];
    }

    /** @param array<mixed> $dateDetail */
    public function getHtmlDescriptionRecentPostDate(array $dateDetail, ?string $authKey = null): string
    {
        $addUrlParams = [];
        if (isset($authKey)) {
            $addUrlParams['auth'] = $authKey;
        }

        $description  = '<ul>';
        $description .= '<li>'
          . Translator::get()->plural('%d new photo', '%d new photos', is_numeric($dateDetail['nb_elements'] ?? null) ? (int) $dateDetail['nb_elements'] : 0)
          . ' (<a href="' . $this->urlService->addUrlParams($this->urlService->makeIndexUrl(['section' => 'recent_pics']), $addUrlParams) . '">' . Lang::t('Recent photos') . '</a>)'
          . '</li><br>';

        $elements = is_array($dateDetail['elements'] ?? null) ? $dateDetail['elements'] : [];
        foreach ($elements as $element) {
            $element = is_array($element) ? $element : [];
            $tnSrcRaw = DerivativeImage::thumbUrl($element);
            $tnSrc    = is_string($tnSrcRaw) ? $tnSrcRaw : '';
            $description .= '<a href="'
              . $this->urlService->addUrlParams($this->urlService->makePictureUrl(['image_id' => $element['id'], 'image_file' => $element['file']]), $addUrlParams)
              . '"><img src="' . $tnSrc . '"></a>';
        }
        $description .= '...<br>';

        $description .= '<li>'
          . Translator::get()->plural('%d album updated', '%d albums updated', is_numeric($dateDetail['nb_cats'] ?? null) ? (int) $dateDetail['nb_cats'] : 0)
          . '</li>';

        $description .= '<ul>';
        $categories = is_array($dateDetail['categories'] ?? null) ? $dateDetail['categories'] : [];
        foreach ($categories as $cat) {
            $uppercats = $cat instanceof \Piwigo\Notification\Projection\RecentCategoryRow
                ? $cat->uppercats
                : (is_scalar($cat['uppercats'] ?? null) ? (string) $cat['uppercats'] : '');
            $imgCount = $cat instanceof \Piwigo\Notification\Projection\RecentCategoryRow
                ? $cat->imgCount
                : (is_numeric($cat['img_count'] ?? null) ? (int) $cat['img_count'] : 0);
            $description .= '<li>'
              . $this->htmlService->getCatDisplayNameCache($uppercats, '', false, null, $authKey)
              . ' (' . Translator::get()->plural('%d new photo', '%d new photos', $imgCount) . ')'
              . '</li>';
        }
        $description .= '</ul></ul>';

        return $description;
    }

    /** @param array<mixed> $dateDetail */
    public function getTitleRecentPostDate(array $dateDetail): string
    {
        $title = Translator::get()->plural('%d new photo', '%d new photos', is_numeric($dateDetail['nb_elements'] ?? null) ? (int) $dateDetail['nb_elements'] : 0);

        if (preg_match('/^\d+-(\d+)-(\d+) /', is_scalar($dateDetail['date_available'] ?? null) ? (string) $dateDetail['date_available'] : '', $matches)) {
            $monthName = Lang::month((int) $matches[1]);
            $title    .= ' (' . $monthName . ' ' . $matches[2] . ')';
        }

        return $title;
    }
}
