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
use Piwigo\Activity\ActivityService;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Tag\TagService;

/**
 * P23 batch 8e-3: relocated from include/ws_functions/pwg.tags.php.
 * `pwg.tags.*` WS methods (8 registrations) -- registered via callable
 * arrays in include/ws_default_methods.inc.php.
 */
final class PwgTags
{
    private static function tagService(): TagService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::tagService();
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
        return \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService();
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
            usort($tags, static fn (array $a, array $b): int => $b['counter'] <=> $a['counter']);
        } else {
            usort($tags, \Piwigo\Bootstrap\PresentationAccessor::htmlService()->tagAlphaCompare(...));
        }

        $urlService = \Piwigo\Bootstrap\PresentationAccessor::urlService();
        for ($i = 0; $i < count($tags); $i++) {
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
                self::tagService()->getAllTags(\Piwigo\Bootstrap\PresentationAccessor::htmlService()),
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
            $tags_by_id[$tag['id']] = $tag;
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
            array_map(TagId::from(...), $tag_ids),
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
            foreach ($tagService->getCommaJoinedTagIdsByImageIds($tag_ids, $image_ids) as $row_image_id => $tag_ids_csv) {
                $image_tag_map[$row_image_id] = explode(',', $tag_ids_csv);
            }
        }

        $images = [];
        $urlService = \Piwigo\Bootstrap\PresentationAccessor::urlService();
        if ($image_ids !== []) {
            $rank_of = array_flip($image_ids);
            $favorite_ids = $urlService->getUserFavorites();

            foreach (\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)->findByIds($image_ids) as $row_id => $imageRow) {
                // Unboxed here rather than kept as the typed object -- this
                // loop rebuilds a differently-shaped $image array from
                // $row's fields and separately passes the whole row to
                // WsHelper::stdGetUrls(array $image_row, ...), both of
                // which need real array semantics.
                $row = $imageRow->toArray();

                $image = [];
                $image['rank'] = $rank_of[$row_id];
                $image['is_favorite'] = isset($favorite_ids[$row_id]);

                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($row[$k])) {
                        $image[$k] = $row[$k];
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $row[$k] ?? null;
                }

                $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_element_name', $image['name'], __FUNCTION__);
                $image['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
                $image['comment'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_element_description', $image['comment'], __FUNCTION__);

                $image = array_merge($image, WsHelper::stdGetUrls($row, $urlService));

                $image_tag_ids = ($params['tag_mode_and']) ? $tag_ids : $image_tag_map[$row_id];
                $image_tags = [];
                foreach ($image_tag_ids as $tag_id_raw) {
                    $tag_id = is_numeric($tag_id_raw) ? (int) $tag_id_raw : 0;
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
                        'id' => $tag_id,
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

        $creation_id = $creation_output['id'];
        $creation_info = $creation_output['info'];

        self::activityService($conn)->record('tag', $creation_id, 'add');

        $new_tag = self::tagService()->getById(TagId::from($creation_id));

        return [
            'info' => $creation_info,
            'id' => $creation_id,
            'name' => $new_tag->name ?? '',
            'url_name' => $new_tag->urlName ?? '',
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

        if (self::tagService()->countExistingIds(array_values($params['tag_id'])) !== count($params['tag_id'])) {
            return new PwgError(WsError::INVALID_PARAM, 'All tags does not exist.');
        }

        $tag_ids = $params['tag_id'];

        if (count($tag_ids) > 0) {
            self::tagService()->deleteTags(array_values(array_map(TagId::from(...), $params['tag_id'])));
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
        if (! self::tagService()->existsById($tag_id)) {
            return new PwgError(WsError::INVALID_PARAM, 'This tag does not exist.');
        }

        $existing_names = self::tagService()->getOtherNames($tag_id);

        if (in_array($tag_name, $existing_names, true)) {
            return new PwgError(WsError::INVALID_PARAM, 'This name is already token');
        }

        self::activityService($conn)->record('tag', $tag_id, 'edit');

        if ($tag_name !== '') {
            $url_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_url', $tag_name);
            if (! is_string($url_name)) {
                // a misbehaving plugin handler could return a non-string;
                // fall back to the untransformed tag name rather than
                // propagate it -- same rule as TagService::tagIdFromTagName().
                $url_name = $tag_name;
            }
            self::tagService()->renameTag(TagId::from($tag_id), $tag_name, $url_name);
        }
        \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();

        $renamed_tag = self::tagService()->getById(TagId::from($tag_id));
        assert($renamed_tag !== null);

        $tag = [
            'id' => $renamed_tag->id->value,
            'name' => $renamed_tag->name,
            'url_name' => $renamed_tag->urlName,
        ];
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
     * url_name is EventDispatcher::triggerChange('render_tag_url', ...)'s
     * own genuinely-mixed plugin-filter return; id/name/count are real
     * (id is explicitly (int)-cast from lastInsertId(), name is the
     * mandatory string $params['copy_name'], count is count($inserts)).
     * @return PwgError|array{id: int, name: string, url_name: mixed, count: int}
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
        if (! self::tagService()->existsById($tag_id)) {
            return new PwgError(WsError::INVALID_PARAM, 'This tag does not exist.');
        }

        if (self::tagService()->existsByName($copy_name)) {
            return new PwgError(WsError::INVALID_PARAM, 'This name is already taken.');
        }

        $copy_url_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_url', $copy_name);
        if (! is_string($copy_url_name)) {
            $copy_url_name = $copy_name;
        }
        $destination_tag_id = self::tagService()->duplicateTag($copy_name, $copy_url_name)->value;
        \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();

        self::activityService($conn)->record('tag', $destination_tag_id, 'add', [
            'action' => 'duplicate',
            'source_tag' => $tag_id,
        ]);

        $destination_tag_image_ids = self::tagService()->getImageIdsForTagIds([TagId::from($tag_id)]);

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
            self::tagService()->copyImageTagAssociations($inserts);
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
     * @return PwgError|array{destination_tag: int, deleted_tag: array<int, int>, images_in_merged_tag: list<int>}
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

        if (self::tagService()->countExistingIds(array_values($all_tags)) !== count($all_tags)) {
            return new PwgError(WsError::INVALID_PARAM, 'All tags does not exist.');
        }

        $image_in_merge_tags = array_values(array_unique(
            self::tagService()->getImageIdsForTagIds(array_map(TagId::from(...), array_values($merge_tag)))
        ));

        $image_in_dest = self::tagService()->getImageIdsForTagIds([TagId::from($params['destination_tag_id'])]);

        $image_to_add = array_values(array_diff($image_in_merge_tags, $image_in_dest));

        $inserts = [];
        foreach ($image_to_add as $image) {
            $inserts[] = [
                'tag_id' => $params['destination_tag_id'],
                'image_id' => $image,
            ];
        }

        self::tagService()->copyImageTagAssociations($inserts, ignore: true);

        self::activityService($conn)->record('tag', $params['destination_tag_id'], 'edit');
        foreach ($image_to_add as $image_id) {
            self::activityService($conn)->record('photo', $image_id, 'edit', [
                'tag-add' => $params['destination_tag_id'],
            ]);
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('merge_tags', $params['destination_tag_id'], $merge_tag);

        self::tagService()->deleteTags(array_values(array_map(TagId::from(...), $merge_tag)));

        $image_in_merged = array_merge($image_in_dest, $image_to_add);

        return [
            'destination_tag' => $params['destination_tag_id'],
            'deleted_tag' => $params['merge_tag_id'],
            'images_in_merged_tag' => $image_in_merged,
        ];
    }
}
