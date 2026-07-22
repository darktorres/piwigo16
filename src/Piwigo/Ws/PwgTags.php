<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Url\UrlService;

/**
 * P23 batch 8e-3: relocated from include/ws_functions/pwg.tags.php.
 * `pwg.tags.*` WS methods (8 registrations) -- registered via callable
 * arrays in include/ws_default_methods.inc.php.
 */
final class PwgTags
{
    private static function tagService(): TagService
    {
        $conn = DbConnection::build();
        return new TagService(new TagRepository($conn), new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn)), new ActivityService(new ActivityRepository(DbConnection::build())));
    }

    /**
     * Constructed repeatedly across add()/rename()/duplicate()/merge()
     * (including inside per-image loops in duplicate()/merge()) -- takes
     * the caller's own $conn instead of building a fresh one per call,
     * same "shared connection passed in" precedent as
     * Bootstrap\RequestBootstrap::activityService(Connection $conn).
     */
    private static function activityService(Connection $conn): ActivityService
    {
        return new ActivityService(new ActivityRepository($conn));
    }

    /**
     * API method
     * Returns a list of tags
     *
     * @param array{sort_by_counter: bool, ...} $params non-null bool default,
     *   WsParamType::BOOL -- always present.
     * @return array{tags: PwgNamedArray}
     */
    public static function getList(array $params, PwgServer &$service): array
    {
        $tags = self::tagService()->getAvailableTags();
        if ($params['sort_by_counter']) {
            usort($tags, function (array $a, array $b): int {
                $a_counter = is_numeric($a['counter']) ? (float) $a['counter'] : 0.0;
                $b_counter = is_numeric($b['counter']) ? (float) $b['counter'] : 0.0;
                return $b_counter <=> $a_counter;
            });
        } else {
            usort($tags, new HtmlService()->tagAlphaCompare(...));
        }

        $urlService = new UrlService(new HtmlService());
        for ($i = 0; $i < count($tags); $i++) {
            $tags[$i]['id'] = is_numeric($tags[$i]['id']) ? (int) $tags[$i]['id'] : 0;
            $tags[$i]['counter'] = is_numeric($tags[$i]['counter']) ? (int) $tags[$i]['counter'] : 0;
            $tags[$i]['url'] = $urlService->makeIndexUrl(
                [
                    'section' => 'tags',
                    'tags' => [$tags[$i]],
                ]
            );
        }

        return [
            'tags' => new PwgNamedArray(
                $tags,
                'tag',
                WsHelper::stdGetTagXmlAttributes()
            ),
        ];
    }

    /**
     * API method
     * Returns the list of tags as you can see them in administration
     *
     * Only admin can run this method and permissions are not taken into
     * account.
     *
     * @param array<string, mixed> $params this method is registered with a
     *   null signature (zero registered params) -- $params is the raw,
     *   entirely unvalidated request array, but the body doesn't read it.
     * @return array{tags: PwgNamedArray}
     */
    public static function getAdminList(array $params, PwgServer &$service): array
    {
        return [
            'tags' => new PwgNamedArray(
                self::tagService()->getAllTags(new HtmlService()),
                'tag',
                WsHelper::stdGetTagXmlAttributes()
            ),
        ];
    }

    /**
     * API method
     * Returns a list of images for tags
     *
     * @param array{tag_id: array<int, int>, tag_url_name: array<int, string>, tag_name: array<int, string>, tag_mode_and: bool, per_page: int, page: int, order: string|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     *   tag_id/tag_url_name/tag_name: FORCE_ARRAY with a null default --
     *   makeArrayParam() converts the null default to [], always a list
     *   (tag_id: positive ints via WsParamType::ID; tag_url_name/tag_name:
     *   untyped, so strings). tag_mode_and/per_page/page: non-null default,
     *   always present. order: null default, no 'type' flag -- always
     *   present, string|null. f_* keys: the shared $f_params block merged
     *   into this registration, see
     *   WsHelper::stdImageSqlFilter()/WsHelper::stdImageSqlOrder().
     * @return array{paging: PwgNamedStruct, images: PwgNamedArray}
     */
    public static function getImages(array $params, PwgServer &$service): array
    {
        $tagService = self::tagService();

        // first build all the tag_ids we are interested in
        $tags = $tagService->findTags($params['tag_id'], $params['tag_url_name'], $params['tag_name']);
        $tags_by_id = [];
        foreach ($tags as $tag) {
            if (! is_array($tag)) {
                continue;
            }
            $tag_id = isset($tag['id']) && is_numeric($tag['id']) ? (int) $tag['id'] : 0;
            $tag['id'] = $tag_id;
            $tags_by_id[$tag_id] = $tag;
        }
        unset($tags);
        $tag_ids = array_keys($tags_by_id);

        $where_clauses = WsHelper::stdImageSqlFilter($params, $service);
        $where_clauses = $where_clauses !== [] ? implode(' AND ', $where_clauses) : '';

        $order_by = WsHelper::stdImageSqlOrder($params, 'i.');
        if ($order_by !== '') {
            $order_by = 'ORDER BY ' . $order_by;
        }
        $image_ids = $tagService->getImageIdsForTags(
            $tag_ids,
            $params['tag_mode_and'] ? 'AND' : 'OR',
            $where_clauses,
            $order_by
        );
        // Cast to int at the source (not just at each read site) so
        // array_flip($image_ids) below produces int keys matching $row_id's
        // (int) cast, instead of leaving PHPStan-only string keys.
        $image_ids = array_values(array_map(intval(...), array_filter($image_ids, is_numeric(...))));

        $count_set = count($image_ids);
        $image_ids = array_slice($image_ids, $params['per_page'] * $params['page'], $params['per_page']);

        $conn = DbConnection::build();

        $image_tag_map = [];
        // build list of image ids with associated tags per image
        if ($image_ids !== [] and ! $params['tag_mode_and']) {
            $query = '
SELECT image_id, GROUP_CONCAT(tag_id) AS tag_ids
  FROM ' . Tables::imageTag() . '
  WHERE tag_id IN (' . implode(',', $tag_ids) . ')
    AND image_id IN (' . implode(',', $image_ids) . ')
  GROUP BY image_id
;';
            foreach ($conn->fetchAllAssociative($query) as $row) {
                $row['image_id'] = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
                $image_tag_map[$row['image_id']] = explode(',', is_scalar($row['tag_ids']) ? (string) $row['tag_ids'] : '');
            }
        }

        $images = [];
        $urlService = new UrlService(new HtmlService());
        if ($image_ids !== []) {
            $rank_of = array_flip($image_ids);
            $favorite_ids = $urlService->getUserFavorites();

            $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $image_ids) . ')
;';

            foreach ($conn->fetchAllAssociative($query) as $row) {
                if (! is_numeric($row['id'])) {
                    continue;
                }
                $row_id = (int) $row['id'];

                $image = [];
                $image['rank'] = $rank_of[$row_id];
                $image['is_favorite'] = isset($favorite_ids[$row_id]);

                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = is_numeric($row[$k]) ? (int) $row[$k] : 0;
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k];
                }

                $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_element_name', $image['name'], __FUNCTION__);
                $image['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
                $image['comment'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_element_description', $image['comment'], __FUNCTION__);

                $image = array_merge($image, WsHelper::stdGetUrls($row, $urlService));

                $image_tag_ids = ($params['tag_mode_and']) ? $tag_ids : $image_tag_map[$row_id];
                $image_tags = [];
                foreach ($image_tag_ids as $tag_id) {
                    $url = $urlService->makeIndexUrl(
                        [
                            'section' => 'tags',
                            'tags' => [$tags_by_id[$tag_id]],
                        ]
                    );
                    $page_url = $urlService->makePictureUrl(
                        [
                            'section' => 'tags',
                            'tags' => [$tags_by_id[$tag_id]],
                            'image_id' => $row['id'],
                            'image_file' => $row['file'],
                        ]
                    );
                    $image_tags[] = [
                        'id' => (int) $tag_id,
                        'url' => $url,
                        'page_url' => $page_url,
                    ];
                }

                $image['tags'] = new PwgNamedArray($image_tags, 'tag', WsHelper::stdGetTagXmlAttributes());
                $images[] = $image;
            }

            usort($images, CategoryService::compareByRank(...));
            unset($rank_of);
        }

        return [
            'paging' => new PwgNamedStruct(
                [
                    'page' => $params['page'],
                    'per_page' => $params['per_page'],
                    'count' => count($images),
                    'total_count' => $count_set,
                ]
            ),
            'images' => new PwgNamedArray(
                $images,
                'image',
                WsHelper::stdGetImageXmlAttributes()
            ),
        ];
    }

    /**
     * API method
     * Adds a tag
     *
     * @param array{name: string, ...} $params no 'default' key -- mandatory,
     *   always present.
     * @return PwgError|array{info: string, id: int|string, name: string, url_name: string}
     */
    public static function add(array $params, PwgServer &$service): PwgError|array
    {
        $conn = DbConnection::build();

        $creation_output = self::tagService()->createTag($params['name']);

        if (isset($creation_output['error'])) {
            return new PwgError(WsError::INVALID_PARAM, $creation_output['error']);
        }

        self::activityService($conn)->record('tag', $creation_output['id'], 'add');

        $query = '
SELECT name, url_name
FROM `' . Tables::tags() . '`
WHERE id = ' . $creation_output['id'] . ';';

        $new_tag = $conn->fetchAssociative($query);
        $new_tag_name = $new_tag !== false ? ($new_tag['name'] ?? null) : null;
        $new_tag_url_name = $new_tag !== false ? ($new_tag['url_name'] ?? null) : null;

        return [
            'info' => $creation_output['info'],
            'id' => $creation_output['id'],
            'name' => is_string($new_tag_name) ? $new_tag_name : '',
            'url_name' => is_string($new_tag_url_name) ? $new_tag_url_name : '',
        ];
    }

    /**
     * API method
     * Delete tag(s) by ID
     *
     * @param array{tag_id: array<int, int>, pwg_token: string, ...} $params
     *   neither has a 'default' key -- both mandatory, always present;
     *   FORCE_ARRAY always coerces tag_id to a list of positive ints.
     * @return PwgError|array{id: array<int, int>}
     */
    public static function delete(array $params, PwgServer &$service): PwgError|array
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $query = '
SELECT COUNT(*)
  FROM `' . Tables::tags() . '`
  WHERE id in (' . implode(',', $params['tag_id']) . ')
;';
        $count = DbConnection::build()->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count !== count($params['tag_id'])) {
            return new PwgError(WsError::INVALID_PARAM, 'All tags does not exist.');
        }

        $tag_ids = $params['tag_id'];

        if (count($tag_ids) > 0) {
            self::tagService()->deleteTags($params['tag_id']);
            return [
                'id' => $tag_ids,
            ];
        } else {
            return [
                'id' => [],
            ];
        }
    }

    /**
     * API method
     * Rename tag
     *
     * @param array{tag_id: int, new_name: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, WsParamType::ID
     *   guarantees a plain int for tag_id.
     * @return PwgError|array<string, mixed>
     */
    public static function rename(array $params, PwgServer &$service): PwgError|array
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $conn = DbConnection::build();
        $tag_id = $params['tag_id'];
        $tag_name = strip_tags(stripslashes($params['new_name']));

        // does the tag exist ?
        $query = '
SELECT COUNT(*)
  FROM `' . Tables::tags() . '`
  WHERE id = ' . $tag_id . '
;';
        $count = $conn->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count === 0) {
            return new PwgError(WsError::INVALID_PARAM, 'This tag does not exist.');
        }

        $query = '
SELECT name
  FROM ' . Tables::tags() . '
  WHERE id != ' . $tag_id . '
;';
        $existing_names = array_column($conn->fetchAllAssociative($query), 'name');

        $update = [];

        if (in_array($tag_name, array_map(strval(...), array_filter($existing_names, is_scalar(...))), true)) {
            return new PwgError(WsError::INVALID_PARAM, 'This name is already token');
        }
        if ($tag_name !== '') {
            // realEscapeString() dropped: BatchWriter::singleUpdate() below
            // parameterizes $update['name'] instead of interpolating it,
            // same "dead pre-escaping" rationale as
            // Bootstrap\UserBootstrap's HTTP_X_PIWIGO_API fix (Phase 1d).
            $update = [
                'name' => $tag_name,
                'url_name' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_url', $tag_name),
            ];

        }

        self::activityService($conn)->record('tag', $tag_id, 'edit');

        new BatchWriter($conn)
            ->singleUpdate(
                Tables::tags(),
                $update,
                [
                    'id' => $tag_id,
                ]
            );

        $query = '
SELECT
    id,
    name,
    url_name
  FROM ' . Tables::tags() . '
  WHERE id = ' . $tag_id . '
;';

        $tag = $conn->fetchAssociative($query);
        assert($tag !== false);
        $tag['raw_name'] = $tag['name'];
        $tag['name'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_name', $tag['raw_name'], $tag);
        $tag['alt_names'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_tag_alt_names', [], $tag['raw_name']);
        return $tag;
    }

    /**
     * API method
     * Create a copy of a tag
     *
     * @param array{tag_id: int, copy_name: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, WsParamType::ID
     *   guarantees a plain int for tag_id.
     * @return PwgError|array{id: int|string, name: string, url_name: mixed, count: int}
     */
    public static function duplicate(array $params, PwgServer &$service): PwgError|array
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $conn = DbConnection::build();
        $tag_id = $params['tag_id'];
        $copy_name = $params['copy_name'];

        // does the tag exist ?
        $query = '
SELECT COUNT(*)
  FROM `' . Tables::tags() . '`
  WHERE id = ' . $tag_id . '
;';
        $count = $conn->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count === 0) {
            return new PwgError(WsError::INVALID_PARAM, 'This tag does not exist.');
        }

        $query = '
SELECT COUNT(*)
  FROM `' . Tables::tags() . '`
  WHERE name = "' . $copy_name . '"
;';
        $count = $conn->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count !== 0) {
            return new PwgError(WsError::INVALID_PARAM, 'This name is already taken.');
        }

        new BatchWriter($conn)
            ->singleInsert(
                Tables::tags(),
                [
                    'name' => $copy_name,
                    'url_name' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_url', $copy_name),
                ]
            );
        $destination_tag_id = (int) $conn->lastInsertId();

        self::activityService($conn)->record('tag', $destination_tag_id, 'add', [
            'action' => 'duplicate',
            'source_tag' => $tag_id,
        ]);

        $query = '
SELECT image_id
  FROM ' . Tables::imageTag() . '
  WHERE tag_id = ' . $tag_id . '
;';
        $destination_tag_image_ids = array_column($conn->fetchAllAssociative($query), 'image_id');
        $destination_tag_image_ids = array_values(array_map(intval(...), array_filter($destination_tag_image_ids, is_numeric(...))));

        $inserts = [];

        foreach ($destination_tag_image_ids as $image_id) {
            $inserts[] = [
                'tag_id' => $destination_tag_id,
                'image_id' => $image_id,
            ];
            self::activityService($conn)->record('photo', $image_id, 'edit', [
                'add-tag' => $destination_tag_id,
            ]);
        }

        if (count($inserts) > 0) {
            new BatchWriter($conn)
                ->massInsert(
                    Tables::imageTag(),
                    array_keys($inserts[0]),
                    $inserts
                );
        }

        return [
            'id' => $destination_tag_id,
            'name' => $copy_name,
            'url_name' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_url', $copy_name),
            'count' => count($inserts),
        ];
    }

    /**
     * API method
     * Merge tags in one other group
     *
     * @param array{destination_tag_id: int, merge_tag_id: array<int, int>, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present;
     *   destination_tag_id: WsParamType::ID guarantees a plain int; merge_tag_id:
     *   FORCE_ARRAY always coerces to a list of positive ints.
     * @return PwgError|array{destination_tag: int, deleted_tag: array<int, int>, images_in_merged_tag: array<int, mixed>}
     */
    public static function merge(array $params, PwgServer &$service): PwgError|array
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $conn = DbConnection::build();
        $all_tags = $params['merge_tag_id'];
        array_push($all_tags, $params['destination_tag_id']);

        $all_tags = array_unique($all_tags);
        $merge_tag = array_diff($params['merge_tag_id'], [$params['destination_tag_id']]);

        $query = '
SELECT COUNT(*)
  FROM `' . Tables::tags() . '`
  WHERE id in (' . implode(',', $all_tags) . ')
;';
        $count = $conn->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count !== count($all_tags)) {
            return new PwgError(WsError::INVALID_PARAM, 'All tags does not exist.');
        }

        $query = '
SELECT DISTINCT(image_id)
  FROM `' . Tables::imageTag() . '`
  WHERE
    tag_id IN (' . implode(',', $merge_tag) . ')
;';
        $image_in_merge_tags = array_values(array_map(intval(...), array_filter(array_column($conn->fetchAllAssociative($query), 'image_id'), is_numeric(...))));

        $query = '
SELECT image_id
  FROM `' . Tables::imageTag() . '`
  WHERE tag_id = ' . $params['destination_tag_id'] . '
;';

        $image_in_dest = array_values(array_map(intval(...), array_filter(array_column($conn->fetchAllAssociative($query), 'image_id'), is_numeric(...))));

        $image_to_add = array_values(array_diff($image_in_merge_tags, $image_in_dest));

        $inserts = [];
        foreach ($image_to_add as $image) {
            $inserts[] = [
                'tag_id' => $params['destination_tag_id'],
                'image_id' => $image,
            ];
        }

        new BatchWriter($conn)
            ->massInsert(
                Tables::imageTag(),
                ['tag_id', 'image_id'],
                $inserts,
                [
                    'ignore' => true,
                ]
            );

        self::activityService($conn)->record('tag', $params['destination_tag_id'], 'edit');
        foreach ($image_to_add as $image_id) {
            self::activityService($conn)->record('photo', $image_id, 'edit', [
                'tag-add' => $params['destination_tag_id'],
            ]);
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('merge_tags', $params['destination_tag_id'], $merge_tag);

        self::tagService()->deleteTags($merge_tag);

        $image_in_merged = array_merge($image_in_dest, $image_to_add);

        return [
            'destination_tag' => $params['destination_tag_id'],
            'deleted_tag' => $params['merge_tag_id'],
            'images_in_merged_tag' => $image_in_merged,
        ];
    }
}
