<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Cache\PersistentFileCache;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Http\ControllerInterface;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces search.php -- builds a $search descriptor from $_GET/user
 * preferences, persists it via save_search(), and always redirects to the
 * generated search URL. No rendering of its own; redirect() is typed
 * `never` (calls header()+exit() directly), same exit()-based-termination
 * limitation as every other controller this phase.
 */
final class SearchController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         */
        global $conf, $user;

        $searchConn = DbConnection::build();
        $searchService = new SearchService(
            new SearchRepository($searchConn),
            new PermissionService(new PermissionRepository($searchConn), new GroupRepository($searchConn)),
            new PersistentFileCache(),
            new MailService(),
        );

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        trigger_notify('loc_begin_search');

        // +---------------------------------------------------------------+
        // | Create a default search                                      |
        // +---------------------------------------------------------------+

        $search = [
            'mode' => 'AND',
            'fields' => [],
        ];

        // list of filters in user preferences
        $raw_filters_views = conf_get_param('filters_views', $conf['default_filters_views']);
        $filters_views = (is_array($raw_filters_views) or is_string($raw_filters_views))
            ? safe_unserialize($raw_filters_views)
            : [];

        // change the name of the keys so that they can be used with this
        // part of the program
        $filter_rename_for = [
            'words' => 'allwords',
            'post_date' => 'date_posted',
            'creation_date' => 'date_created',
            'album' => 'cat',
            'file_type' => 'filetypes',
            'ratio' => 'ratios',
            'rating' => 'ratings',
            'file_size' => 'filesize',
        ];

        $filters_conf = [];
        if (is_array($filters_views)) {
            foreach ($filters_views as $filter_name => $filter_value) {
                $key = $filter_rename_for[$filter_name] ?? $filter_name;

                $filters_conf[$key] = $filter_value;
            }
        }

        // get all default filters
        $default_fields = [];
        foreach ($filters_conf as $filt_name => $filt_conf) {
            if (! is_array($filt_conf)) {
                continue;
            }

            if (isset($filt_conf['default'])) {
                if ((bool) $filt_conf['default']) {
                    $default_fields[] = $filt_name;
                }
            }
        }

        $last_filters_conf = $filters_conf['last_filters_conf'] ?? null;
        if (\Piwigo\Auth\AccessControl::isAGuest() or \Piwigo\Auth\AccessControl::isGeneric() or ! (bool) $last_filters_conf) {
            $fields = $default_fields;
        } else {
            $raw_fields = (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->getParam('gallery_search_filters', $default_fields);
            $fields = is_array($raw_fields) ? $raw_fields : $default_fields;
        }

        $words = [];
        $q = $_GET['q'] ?? null;
        if (is_string($q) and $q !== '' and $q !== '0') {
            $words = SearchService::splitAllwords($q) ?? [];
        }

        if (count($words) > 0 or in_array('allwords', $fields, true)) {
            $search['fields']['allwords'] = [
                'words' => $words,
                'mode' => 'AND',
                'fields' => ['file', 'name', 'comment', 'tags', 'author', 'cat-title', 'cat-desc'],
            ];
        }

        $cat_ids = [];
        if (isset($_GET['cat_id'])) {
            (new \Piwigo\Validation\InputValidator())->validate('cat_id', $_GET, false, ValidationPattern::ID);

            $cat_id_value = $_GET['cat_id'];
            if (! is_scalar($cat_id_value)) {
                fatal_error('[Hacking attempt] the input parameter "cat_id" is not valid');
            }

            $cat_id = (string) $cat_id_value;

            // P23 batch 3: user_cache_categories's row-existence check below
            // used to mean "category exists AND isn't forbidden/empty for
            // this user" -- exactly what build_user()/getuserdata()
            // (include/functions_user.inc.php) already computes into
            // $user['forbidden_categories'] (see SearchService::
            // qsearchGetCategories()'s identical fix for the full trace).
            $forbidden_categories = $user['forbidden_categories'] ?? null;
            $forbidden_categories_csv = is_string($forbidden_categories) && $forbidden_categories !== '' ? $forbidden_categories : '0';

            $query = '
SELECT
    id
  FROM ' . Tables::categories() . '
  WHERE id = ' . $cat_id . '
    AND id NOT IN (' . $forbidden_categories_csv . ')
;';
            $found_categories = query2array($query);
            if ($found_categories === []) {
                page_not_found(l10n('Requested album does not exist'));
            }

            $cat_ids = [$cat_id];
        }

        if (count($cat_ids) > 0 or in_array('cat', $fields, true)) {
            $search['fields']['cat'] = [
                'words' => $cat_ids,
                'sub_inc' => true,
            ];
        }

        $tagConn = DbConnection::build();
        $tagService = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)));

        if (count($tagService->getAvailableTags()) > 0) {
            $tag_ids = [];
            if (isset($_GET['tag_id'])) {
                (new \Piwigo\Validation\InputValidator())->validate('tag_id', $_GET, false, '/^\d+(,\d+)*$/');

                $tag_id_value = $_GET['tag_id'];
                if (! is_scalar($tag_id_value)) {
                    fatal_error('[Hacking attempt] the input parameter "tag_id" is not valid');
                }

                $tag_ids = explode(',', (string) $tag_id_value);
            }

            if (count($tag_ids) > 0 or in_array('tags', $fields, true)) {
                $search['fields']['tags'] = [
                    'words' => $tag_ids,
                    'mode' => 'AND',
                ];
            }
        }

        if (in_array('author', $fields, true)) {
            // does this Piwigo has authors for current user?
            $query = '
SELECT
    id
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  ' . (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
                'forbidden_categories' => 'category_id',
                'visible_categories' => 'category_id',
                'visible_images' => 'id',
            ], ' WHERE ') . '
    AND author IS NOT NULL
    LIMIT 1
;';
            $first_author = query2array($query);

            if (count($first_author) > 0) {
                $search['fields']['author'] = [
                    'words' => [],
                    'mode' => 'OR',
                ];
            }
        }

        foreach (['added_by', 'filetypes', 'ratios', 'ratings'] as $field) {
            if (in_array($field, $fields, true)) {
                $search['fields'][$field] = [];
            }
        }

        foreach (['date_posted', 'date_created'] as $field) {
            if (in_array($field, $fields, true)) {
                $search['fields'][$field] = [
                    'preset' => '',
                ];
            }
        }

        foreach (['filesize_min', 'filesize_max', 'width_min', 'width_max', 'height_min', 'height_max'] as $field) {
            if (in_array($field, $fields, true)) {
                $search['fields'][$field] = '';
            }
        }

        [$search_uuid, $search_url] = $searchService->saveSearch($search);
        redirect($search_url);
    }
}
