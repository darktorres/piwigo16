<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a search query from GET params, saves it and redirects to results.
 * Corresponds to the former search.php entry-point.
 */
final class SearchController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        require_once PHPWG_ROOT_PATH . 'include/functions_search.inc.php';

        check_status(ACCESS_GUEST);

        trigger_notify('loc_begin_search');

        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $search = ['mode' => 'AND', 'fields' => []];

        $filters_views_raw = conf_get_param('filters_views', Config::defaultFiltersViews());
        $filters_views     = safe_unserialize(is_scalar($filters_views_raw) ? (string) $filters_views_raw : '');

        $filter_rename_for = [
            'words'         => 'allwords',
            'post_date'     => 'date_posted',
            'creation_date' => 'date_created',
            'album'         => 'cat',
            'file_type'     => 'filetypes',
            'ratio'         => 'ratios',
            'rating'        => 'ratings',
            'file_size'     => 'filesize',
        ];

        $filters_conf = [];
        foreach ($filters_views as $filter_name => $filter_value) {
            $key                 = $filter_rename_for[$filter_name] ?? $filter_name;
            $filters_conf[$key]  = $filter_value;
        }

        $default_fields = [];
        foreach ($filters_conf as $filt_name => $filt_conf) {
            if (is_array($filt_conf) && isset($filt_conf['default']) && $filt_conf['default'] == true) {
                $default_fields[] = $filt_name;
            }
        }

        if (is_a_guest() || is_generic() || $filters_conf['last_filters_conf'] == false) {
            $fields = $default_fields;
        } else {
            $fields_raw = userprefs_get_param('gallery_search_filters', $default_fields);
            $fields     = is_array($fields_raw) ? $fields_raw : $default_fields;
        }

        $words = [];
        $q     = input_string('q', null, $_GET);
        if (!empty($q)) {
            $words = split_allwords($q);
        }

        if (count($words ?? []) > 0 || in_array('allwords', $fields)) {
            $search['fields']['allwords'] = [
                'words'  => $words,
                'mode'   => 'AND',
                'fields' => ['file', 'name', 'comment', 'tags', 'author', 'cat-title', 'cat-desc'],
            ];
        }

        $cat_ids  = [];
        $cat_id   = input_int('cat_id', null, $_GET);
        if ($cat_id !== null) {
            check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
            $query = '
SELECT *
  FROM ' . USER_CACHE_CATEGORIES_TABLE . '
  WHERE cat_id = ' . $cat_id . '
    AND user_id = ' . (is_scalar($user['id']) ? (int) $user['id'] : 0) . '
;';
            $found_categories = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
            if (empty($found_categories)) {
                page_not_found(l10n('Requested album does not exist'));
            }
            $cat_ids = [$cat_id];
        }

        if (count($cat_ids) > 0 || in_array('cat', $fields)) {
            $search['fields']['cat'] = ['words' => $cat_ids, 'sub_inc' => true];
        }

        if (count(get_available_tags()) > 0) {
            $tag_ids = [];
            $tag_id  = input_string('tag_id', null, $_GET);
            if ($tag_id !== null) {
                check_input_parameter('tag_id', $_GET, false, '/^\d+(,\d+)*$/');
                $tag_ids = explode(',', $tag_id);
            }
            if (count($tag_ids) > 0 || in_array('tags', $fields)) {
                $search['fields']['tags'] = ['words' => $tag_ids, 'mode' => 'AND'];
            }
        }

        if (in_array('author', $fields)) {
            $query = '
SELECT id
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  ' . get_sql_condition_FandF(
                ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'],
                ' WHERE '
            ) . '
    AND author IS NOT NULL
    LIMIT 1
;';
            $first_author = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
            if (count($first_author) > 0) {
                $search['fields']['author'] = ['words' => [], 'mode' => 'OR'];
            }
        }

        foreach (['added_by', 'filetypes', 'ratios', 'ratings'] as $field) {
            if (in_array($field, $fields)) {
                $search['fields'][$field] = [];
            }
        }
        foreach (['date_posted', 'date_created'] as $field) {
            if (in_array($field, $fields)) {
                $search['fields'][$field] = ['preset' => ''];
            }
        }
        foreach (['filesize_min', 'filesize_max', 'width_min', 'width_max', 'height_min', 'height_max'] as $field) {
            if (in_array($field, $fields)) {
                $search['fields'][$field] = '';
            }
        }

        [$search_uuid, $search_url] = save_search($search);
        redirect(is_scalar($search_url) ? (string) $search_url : '');

        return ResponseFactory::create(200); // unreachable after redirect
    }
}
