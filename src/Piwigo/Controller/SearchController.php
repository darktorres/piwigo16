<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocBeginSearch;
use Piwigo\Http\ControllerInterface;
use Piwigo\Permission\PermissionService;
use Piwigo\Search\SearchService;
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
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
    ) {}

    private static function permissionService(): PermissionService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::permissionService();
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $searchService = \Piwigo\Bootstrap\ExtendedDomainAccessor::searchService();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $this->eventDispatcher->dispatchNotify(new LocBeginSearch());

        // +---------------------------------------------------------------+
        // | Create a default search                                      |
        // +---------------------------------------------------------------+

        $search = [
            'mode' => 'AND',
            'fields' => [],
        ];

        // list of filters in user preferences
        $filters_views = \Piwigo\Config\CurrentConfig::filtersViews() ?? \Piwigo\Config\CurrentConfig::defaultFiltersViews();

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
        foreach ($filters_views as $filter_name => $filter_value) {
            $key = $filter_rename_for[$filter_name] ?? $filter_name;

            $filters_conf[$key] = $filter_value;
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
            $raw_fields = \Piwigo\Bootstrap\CoreDomainAccessor::preferencesService()
                ->getParam('gallery_search_filters', $default_fields);
            $fields = is_array($raw_fields) ? $raw_fields : $default_fields;
        }

        $searchQuery = Request\SearchQueryRequest::fromGlobals();

        $words = [];
        $q = $searchQuery->q;
        if ($q !== '' and $q !== '0') {
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
        if ($searchQuery->hasCatId) {
            $cat_id_value = $searchQuery->catId;
            if (! is_string($cat_id_value)) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('[Hacking attempt] the input parameter "cat_id" is not valid');
            }

            $cat_id = $cat_id_value;

            // P23 batch 3: user_cache_categories's row-existence check below
            // used to mean "category exists AND isn't forbidden/empty for
            // this user" -- exactly what build_user()/getuserdata()
            // (include/functions_user.inc.php) already computes into
            // CurrentUser::get()->forbiddenCategories (see SearchService::
            // qsearchGetCategories()'s identical fix for the full trace).
            $forbidden_categories = \Piwigo\Users\CurrentUser::get()->forbiddenCategories;
            $forbidden_categories_csv = $forbidden_categories !== '' ? $forbidden_categories : '0';

            $category_accessible = \Piwigo\Bootstrap\CoreDomainAccessor::categoryService()
                ->existsAndNotForbidden((int) $cat_id, $forbidden_categories_csv);
            if (! $category_accessible) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->pageNotFound($this->redirectService, Lang::t('Requested album does not exist'));
            }

            $cat_ids = [$cat_id];
        }

        if (count($cat_ids) > 0 or in_array('cat', $fields, true)) {
            $search['fields']['cat'] = [
                'words' => $cat_ids,
                'sub_inc' => true,
            ];
        }

        $tagService = \Piwigo\Bootstrap\CoreDomainAccessor::tagService();

        if (count($tagService->getAvailableTags()) > 0) {
            $tag_ids = [];
            if ($searchQuery->hasTagId) {
                $tag_id_value = $searchQuery->tagId;
                if (! is_string($tag_id_value)) {
                    \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                        ->fatalError('[Hacking attempt] the input parameter "tag_id" is not valid');
                }

                $tag_ids = explode(',', $tag_id_value);
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
            $has_author = \Piwigo\Bootstrap\CoreDomainAccessor::imageService()->hasAccessibleImageWithAuthor(
                self::permissionService()->getSqlConditionFandFAsCondition([
                    'forbidden_categories' => 'category_id',
                    'visible_categories' => 'category_id',
                    'visible_images' => 'id',
                ])
            );

            if ($has_author) {
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

        [$search_uuid, $search_url] = $searchService->saveSearch($search, $this->urlService);
        $this->redirectService->redirect($search_url);
    }
}
