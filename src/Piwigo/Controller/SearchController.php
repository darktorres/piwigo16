<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Event\Location\LocBeginSearch;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\ResponseFactory;
use Piwigo\Search\SearchService;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Validation\InputValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a search query from GET params, saves it and redirects to results.
 * Corresponds to the former search.php entry-point.
 */
final readonly class SearchController implements ControllerInterface
{
    public function __construct(
        private Connection $conn,
        private ConfigService $configService,
        private HtmlService $htmlService,
        private SearchService $searchService,
        private TagService $tagService,
        private InputValidator $inputValidator,
        private RedirectResponder $redirectResponder,
        private PermissionService $permissionService,
        private PreferencesService $preferencesService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->permissionService->checkStatus(AccessLevel::Guest);

        $this->dispatcher->dispatch(new LocBeginSearch());

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        $search = ['mode' => 'AND', 'fields' => []];

        $filters_views_raw = $this->configService->confGetParam('filters_views', Config::defaultFiltersViews());
        $filters_views     = StringUtil::safeUnserialize(is_scalar($filters_views_raw) ? (string) $filters_views_raw : '');

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

        if ($this->permissionService->isAGuest() || $this->permissionService->isGeneric() || $filters_conf['last_filters_conf'] == false) {
            $fields = $default_fields;
        } else {
            $fields_raw = $this->preferencesService->userprefsGetParam('gallery_search_filters', $default_fields);
            $fields     = is_array($fields_raw) ? $fields_raw : $default_fields;
        }

        $words = [];
        $q     = StringUtil::inputString('q', null, $_GET);
        if ($q !== null && $q !== '') {
            $words = $this->searchService->splitAllwords($q);
        }

        if (count($words ?? []) > 0 || in_array('allwords', $fields)) {
            $search['fields']['allwords'] = [
                'words'  => $words,
                'mode'   => 'AND',
                'fields' => ['file', 'name', 'comment', 'tags', 'author', 'cat-title', 'cat-desc'],
            ];
        }

        $cat_ids  = [];
        $cat_id   = StringUtil::inputInt('cat_id', null, $_GET);
        if ($cat_id !== null) {
            $this->inputValidator->check('cat_id', $_GET, false, ValidationPattern::ID);
            $query = '
SELECT *
  FROM ' . Tables::userCacheCategories() . '
  WHERE cat_id = ' . $cat_id . '
    AND user_id = ' . (is_scalar($user['id']) ? (int) $user['id'] : 0) . '
;';
            $found_categories = $this->conn->executeQuery($query)->fetchAllAssociative();
            if (empty($found_categories)) {
                $this->htmlService->pageNotFound(Lang::t('Requested album does not exist'));
            }
            $cat_ids = [$cat_id];
        }

        if (count($cat_ids) > 0 || in_array('cat', $fields)) {
            $search['fields']['cat'] = ['words' => $cat_ids, 'sub_inc' => true];
        }

        if (count($this->tagService->getAvailableTags()) > 0) {
            $tag_ids = [];
            $tag_id  = StringUtil::inputString('tag_id', null, $_GET);
            if ($tag_id !== null) {
                $this->inputValidator->check('tag_id', $_GET, false, '/^\d+(,\d+)*$/');
                $tag_ids = explode(',', $tag_id);
            }
            if (count($tag_ids) > 0 || in_array('tags', $fields)) {
                $search['fields']['tags'] = ['words' => $tag_ids, 'mode' => 'AND'];
            }
        }

        if (in_array('author', $fields)) {
            $query = '
SELECT id
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  ' . $this->permissionService->getSqlConditionFandF(
                ['forbidden_categories' => 'category_id', 'visible_categories' => 'category_id', 'visible_images' => 'id'],
                ' WHERE '
            ) . '
    AND author IS NOT NULL
    LIMIT 1
;';
            $first_author = $this->conn->executeQuery($query)->fetchAllAssociative();
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

        [$search_uuid, $search_url] = $this->searchService->saveSearch($search);
        $this->redirectResponder->redirect(is_scalar($search_url) ? (string) $search_url : '');

        return ResponseFactory::create(200); // unreachable after redirect
    }
}
