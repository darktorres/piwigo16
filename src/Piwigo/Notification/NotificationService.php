<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Doctrine\DBAL\Connection;
use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Image\DerivativeImage;
use Piwigo\Users\CurrentUser;

final class NotificationService
{
    public function __construct(
        private readonly Connection $conn,
    ) {}

    public function getStdSqlWhereRestrictFilter(string $prefixCondition, string $imgField = 'ic.image_id', bool $forceOneCondition = false): string
    {
        return get_sql_condition_FandF(
            [
                'forbidden_categories' => 'ic.category_id',
                'visible_categories'   => 'ic.category_id',
                'visible_images'       => $imgField,
            ],
            $prefixCondition,
            $forceOneCondition
        );
    }

    /** @return array<mixed>|int|null */
    public function customNotificationQuery(string $action, string $type, ?string $start = null, ?string $end = null): array|int|null
    {
        switch ($type) {
            case 'new_comments':
                $query = '
  FROM ' . COMMENTS_TABLE . ' AS c
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON c.image_id = ic.image_id
  WHERE 1=1';
                if (!empty($start)) {
                    $query .= '
    AND c.validation_date > \'' . $start . '\'';
                }
                if (!empty($end)) {
                    $query .= '
    AND c.validation_date <= \'' . $end . '\'';
                }
                $query .= $this->getStdSqlWhereRestrictFilter('AND');
                break;

            case 'unvalidated_comments':
                $query = '
  FROM ' . COMMENTS_TABLE . '
  WHERE 1=1';
                if (!empty($start)) {
                    $query .= '
    AND date > \'' . $start . '\'';
                }
                if (!empty($end)) {
                    $query .= '
    AND date <= \'' . $end . '\'';
                }
                $query .= '
    AND validated = \'false\'';
                break;

            case 'new_elements':
                $query = '
  FROM ' . IMAGES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON image_id = id
  WHERE 1=1';
                if (!empty($start)) {
                    $query .= '
    AND date_available > \'' . $start . '\'';
                }
                if (!empty($end)) {
                    $query .= '
    AND date_available <= \'' . $end . '\'';
                }
                $query .= $this->getStdSqlWhereRestrictFilter('AND', 'id');
                break;

            case 'updated_categories':
                $query = '
  FROM ' . IMAGES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON image_id = id
  WHERE 1=1';
                if (!empty($start)) {
                    $query .= '
    AND date_available > \'' . $start . '\'';
                }
                if (!empty($end)) {
                    $query .= '
    AND date_available <= \'' . $end . '\'';
                }
                $query .= $this->getStdSqlWhereRestrictFilter('AND', 'id');
                break;

            case 'new_users':
                $query = '
  FROM ' . USER_INFOS_TABLE . '
  WHERE 1=1';
                if (!empty($start)) {
                    $query .= '
    AND registration_date > \'' . $start . '\'';
                }
                if (!empty($end)) {
                    $query .= '
    AND registration_date <= \'' . $end . '\'';
                }
                break;

            default:
                return null;
        }

        switch ($action) {
            case 'count':
                $fieldId = match ($type) {
                    'new_comments'         => 'c.id',
                    'unvalidated_comments' => 'id',
                    'new_elements'         => 'image_id',
                    'updated_categories'   => 'category_id',
                    default                => 'user_id', // new_users
                };
                $count = $this->conn->executeQuery('SELECT COUNT(DISTINCT ' . $fieldId . ') ' . $query)->fetchOne();
                return is_numeric($count) ? (int) $count : null;

            case 'info':
                $fieldId = match ($type) {
                    'new_comments'         => 'c.id',
                    'unvalidated_comments' => 'id',
                    'new_elements'         => 'image_id',
                    'updated_categories'   => 'category_id',
                    default                => 'user_id', // new_users
                };
                return $this->conn->executeQuery('SELECT DISTINCT ' . $fieldId . ' ' . $query . ';')->fetchAllAssociative();

            default:
                return null;
        }
    }

    public function nbNewComments(?string $start = null, ?string $end = null): mixed
    {
        return $this->customNotificationQuery('count', 'new_comments', $start, $end);
    }

    /** @return array<mixed> */
    public function newComments(?string $start = null, ?string $end = null): array
    {
        $result = $this->customNotificationQuery('info', 'new_comments', $start, $end);
        return is_array($result) ? $result : [];
    }

    public function nbUnvalidatedComments(?string $start = null, ?string $end = null): mixed
    {
        return $this->customNotificationQuery('count', 'unvalidated_comments', $start, $end);
    }

    public function nbNewElements(?string $start = null, ?string $end = null): mixed
    {
        return $this->customNotificationQuery('count', 'new_elements', $start, $end);
    }

    /** @return array<mixed> */
    public function newElements(?string $start = null, ?string $end = null): array
    {
        $result = $this->customNotificationQuery('info', 'new_elements', $start, $end);
        return is_array($result) ? $result : [];
    }

    public function nbUpdatedCategories(?string $start = null, ?string $end = null): mixed
    {
        return $this->customNotificationQuery('count', 'updated_categories', $start, $end);
    }

    /** @return array<mixed> */
    public function updatedCategories(?string $start = null, ?string $end = null): array
    {
        $result = $this->customNotificationQuery('info', 'updated_categories', $start, $end);
        return is_array($result) ? $result : [];
    }

    public function nbNewUsers(?string $start = null, ?string $end = null): mixed
    {
        return $this->customNotificationQuery('count', 'new_users', $start, $end);
    }

    /** @return array<mixed> */
    public function newUsers(?string $start = null, ?string $end = null): array
    {
        $result = $this->customNotificationQuery('info', 'new_users', $start, $end);
        return is_array($result) ? $result : [];
    }

    public function newsExists(?string $start = null, ?string $end = null): bool
    {
        return (
            ($this->nbNewComments($start, $end) > 0) or
            ($this->nbNewElements($start, $end) > 0) or
            ($this->nbUpdatedCategories($start, $end) > 0) or
            ((is_admin()) and ($this->nbUnvalidatedComments($start, $end) > 0)) or
            ((is_admin()) and ($this->nbNewUsers($start, $end) > 0)));
    }

    /**
     * @param array<mixed> $news
     */
    public function addNewsLine(array &$news, int $count, string $singularKey, string $pluralKey, ?string $url = '', bool $addUrl = false): void
    {
        if ($count > 0) {
            $line = l10n_dec($singularKey, $pluralKey, $count);
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
            $nbElements = $this->nbNewElements($start, $end);
            $this->addNewsLine($newsArr, is_numeric($nbElements) ? (int) $nbElements : 0, '%d new photo', '%d new photos', add_url_params(make_index_url(['section' => 'recent_pics']), $addUrlParams), $addUrl);

            $nbCats = $this->nbUpdatedCategories($start, $end);
            $this->addNewsLine($newsArr, is_numeric($nbCats) ? (int) $nbCats : 0, '%d album updated', '%d albums updated', add_url_params(make_index_url(['section' => 'recent_cats']), $addUrlParams), $addUrl);
        }

        $nbComments = $this->nbNewComments($start, $end);
        $this->addNewsLine($newsArr, is_numeric($nbComments) ? (int) $nbComments : 0, '%d new comment', '%d new comments', add_url_params(get_root_url() . 'comments.php', $addUrlParams), $addUrl);

        if (is_admin()) {
            $nbUnvalidated = $this->nbUnvalidatedComments($start, $end);
            $this->addNewsLine($newsArr, is_numeric($nbUnvalidated) ? (int) $nbUnvalidated : 0, '%d comment to validate', '%d comments to validate', get_root_url() . 'admin.php?page=comments', $addUrl);

            $nbUsers = $this->nbNewUsers($start, $end);
            $this->addNewsLine($newsArr, is_numeric($nbUsers) ? (int) $nbUsers : 0, '%d new user', '%d new users', get_root_url() . 'admin.php?page=user_list', $addUrl);
        }

        /** @var string[] $newsArr */
        return $newsArr;
    }

    /** @return array<mixed>|null */
    public function getRecentPostDates(int $maxDates, int $maxElements, int $maxCats): ?array
    {
        $persistentCache = PersistentCacheRegistry::current();
        $userId          = CurrentUser::get()->id;
        $cacheUpdate     = is_scalar(CurrentUser::get()->rawAttributes['cache_update_time'] ?? null)
            ? (string) CurrentUser::get()->rawAttributes['cache_update_time']
            : '';

        $cacheKey = $persistentCache->make_key('recent_posts' . $userId . $cacheUpdate . $maxDates . $maxElements . $maxCats);
        $cached   = null;
        if ($persistentCache->get($cacheKey, $cached)) {
            return is_array($cached) ? $cached : null;
        }
        $whereSql = $this->getStdSqlWhereRestrictFilter('WHERE', 'i.id', true);

        $query = '
SELECT
    date_available,
    COUNT(DISTINCT id) AS nb_elements,
    COUNT(DISTINCT category_id) AS nb_cats
  FROM ' . IMAGES_TABLE . ' i INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id=image_id
  ' . $whereSql . '
  GROUP BY date_available
  ORDER BY date_available DESC
  LIMIT ' . $maxDates . '
;';
        $dates = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

        for ($i = 0; $i < count($dates); $i++) {
            $dateAvailable = is_scalar($dates[$i]['date_available']) ? (string) $dates[$i]['date_available'] : '';
            if ($maxElements > 0) {
                $query = '
SELECT DISTINCT i.*
  FROM ' . IMAGES_TABLE . ' i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON id=image_id
  ' . $whereSql . '
    AND date_available=\'' . $dateAvailable . '\'
  ORDER BY ' . DB_RANDOM_FUNCTION . '()
  LIMIT ' . $maxElements . '
;';
                $dates[$i]['elements'] = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
            }

            if ($maxCats > 0) {
                $query = '
SELECT
    DISTINCT c.uppercats,
    COUNT(DISTINCT i.id) AS img_count
  FROM ' . IMAGES_TABLE . ' i
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON i.id=image_id
    INNER JOIN ' . CATEGORIES_TABLE . ' c ON c.id=category_id
  ' . $whereSql . '
    AND date_available=\'' . $dateAvailable . '\'
  GROUP BY category_id, c.uppercats
  ORDER BY img_count DESC
  LIMIT ' . $maxCats . '
;';
                $dates[$i]['categories'] = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
            }
        }

        $persistentCache->set($cacheKey, $dates);
        return $dates;
    }

    /**
     * @param array<mixed> $args
     * @return array<mixed>
     */
    public function getRecentPostDatesArray(array $args): array
    {
        return $this->getRecentPostDates(
            empty($args['max_dates'])    ? 3 : (is_numeric($args['max_dates'])    ? (int) $args['max_dates']    : 3),
            empty($args['max_elements']) ? 3 : (is_numeric($args['max_elements']) ? (int) $args['max_elements'] : 3),
            empty($args['max_cats'])     ? 3 : (is_numeric($args['max_cats'])     ? (int) $args['max_cats']     : 3)
        ) ?? [];
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
          . l10n_dec('%d new photo', '%d new photos', is_numeric($dateDetail['nb_elements'] ?? null) ? (int) $dateDetail['nb_elements'] : 0)
          . ' (<a href="' . add_url_params(make_index_url(['section' => 'recent_pics']), $addUrlParams) . '">' . l10n('Recent photos') . '</a>)'
          . '</li><br>';

        $elements = is_array($dateDetail['elements'] ?? null) ? $dateDetail['elements'] : [];
        foreach ($elements as $element) {
            $element = is_array($element) ? $element : [];
            $tnSrcRaw = DerivativeImage::thumb_url($element);
            $tnSrc    = is_string($tnSrcRaw) ? $tnSrcRaw : '';
            $description .= '<a href="'
              . add_url_params(make_picture_url(['image_id' => $element['id'], 'image_file' => $element['file']]), $addUrlParams)
              . '"><img src="' . $tnSrc . '"></a>';
        }
        $description .= '...<br>';

        $description .= '<li>'
          . l10n_dec('%d album updated', '%d albums updated', is_numeric($dateDetail['nb_cats'] ?? null) ? (int) $dateDetail['nb_cats'] : 0)
          . '</li>';

        $description .= '<ul>';
        $categories = is_array($dateDetail['categories'] ?? null) ? $dateDetail['categories'] : [];
        foreach ($categories as $cat) {
            $cat = is_array($cat) ? $cat : [];
            $description .= '<li>'
              . get_cat_display_name_cache(is_scalar($cat['uppercats'] ?? null) ? (string) $cat['uppercats'] : '', '', false, null, $authKey)
              . ' (' . l10n_dec('%d new photo', '%d new photos', is_numeric($cat['img_count'] ?? null) ? (int) $cat['img_count'] : 0) . ')'
              . '</li>';
        }
        $description .= '</ul></ul>';

        return $description;
    }

    /** @param array<mixed> $dateDetail */
    public function getTitleRecentPostDate(array $dateDetail): string
    {
        $title = l10n_dec('%d new photo', '%d new photos', is_numeric($dateDetail['nb_elements'] ?? null) ? (int) $dateDetail['nb_elements'] : 0);

        if (preg_match('/^\d+-(\d+)-(\d+) /', is_scalar($dateDetail['date_available'] ?? null) ? (string) $dateDetail['date_available'] : '', $matches)) {
            $lang      = is_array($GLOBALS['lang'] ?? null) ? $GLOBALS['lang'] : [];
            $months    = is_array($lang['month'] ?? null) ? $lang['month'] : [];
            $monthName = is_string($months[(int) $matches[1]] ?? null) ? $months[(int) $matches[1]] : '';
            $title    .= ' (' . $monthName . ' ' . $matches[2] . ')';
        }

        return $title;
    }
}
