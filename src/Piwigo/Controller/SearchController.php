<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Event\SearchPageRendering;
use Piwigo\Controller\Request\SearchQueryRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ControllerInterface;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\Projection\AllwordsRule;
use Piwigo\Search\Projection\AuthorRule;
use Piwigo\Search\Projection\CategoryRule;
use Piwigo\Search\Projection\DateRule;
use Piwigo\Search\Projection\SearchRules;
use Piwigo\Search\Projection\TagsRule;
use Piwigo\Search\SearchService;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces search.php -- builds a $search descriptor from $_GET/user
 * preferences, persists it via save_search(), and always redirects to the
 * generated search URL. No rendering of its own; redirect() is typed
 * `never` (calls header()+exit() directly), same exit()-based-termination
 * limitation as every other controller this phase.
 */
final readonly class SearchController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private CurrentUser $currentUser,
        private SearchService $searchService,
        private PermissionService $permissionService,
        private PreferencesService $preferencesService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private ImageService $imageService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $searchService = $this->searchService;

        $this->accessControl->checkStatus(AccessLevel::Guest);

        $this->eventDispatcher->dispatch(new SearchPageRendering());

        $rules = new SearchRules();

        // list of filters in user preferences
        $filters_views = $this->currentConfig->filtersViews->filters ?? $this->currentConfig->defaultFiltersViews;

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
            if ($filt_conf->default) {
                $default_fields[] = $filt_name;
            }
        }

        // filtersViews's own lastFiltersConf field (not a commingled key
        // inside $filters_conf) -- defaultFiltersViews (the fallback when
        // filtersViews is null) never had one either, matching the old
        // raw-array shape's own null-when-absent behavior.
        $last_filters_conf = $this->currentConfig->filtersViews->lastFiltersConf ?? false;
        if ($this->accessControl->isAGuest() or $this->accessControl->isGeneric() or ! $last_filters_conf) {
            $fields = $default_fields;
        } else {
            $fields = $this->preferencesService->getGallerySearchFilters() ?? $default_fields;
        }

        $searchQuery = SearchQueryRequest::fromGlobals($this->inputValidator);

        $words = [];
        $q = $searchQuery->q;
        if ($q !== '' and $q !== '0') {
            $words = array_values(SearchService::splitAllwords($q) ?? []);
        }

        if (count($words) > 0 or in_array('allwords', $fields, true)) {
            $rules->allwords = new AllwordsRule(
                words: $words,
                mode: 'AND',
                fields: ['file', 'name', 'comment', 'tags', 'author', 'cat-title', 'cat-desc'],
            );
        }

        $cat_ids = [];
        if ($searchQuery->hasCatId) {
            $catId = $searchQuery->catId;
            if (! $catId instanceof CategoryId) {
                $this->htmlRenderer
                    ->pageNotFound($this->redirectService, $this->lang->t('Requested album does not exist'));
            }

            $forbidden_categories = $this->currentUser->get()
                ->forbiddenCategories;
            $forbidden_categories_csv = $forbidden_categories !== '' ? $forbidden_categories : '0';

            $category_accessible = $this->categoryService
                ->existsAndNotForbidden($catId, $forbidden_categories_csv);
            if (! $category_accessible) {
                $this->htmlRenderer
                    ->pageNotFound($this->redirectService, $this->lang->t('Requested album does not exist'));
            }

            $cat_ids = [(string) $catId->value];
        }

        if (count($cat_ids) > 0 or in_array('cat', $fields, true)) {
            $rules->cat = new CategoryRule(words: $cat_ids, subInc: true);
        }

        $tagService = $this->tagService;

        if (count($tagService->getAvailableTags()) > 0) {
            $tag_ids = [];
            if ($searchQuery->hasTagId) {
                $tag_id_value = $searchQuery->tagId;
                if (! is_string($tag_id_value)) {
                    $this->htmlRenderer
                        ->fatalError('Invalid request parameter "tag_id"');
                }

                $tag_ids = explode(',', $tag_id_value);
            }

            if (count($tag_ids) > 0 or in_array('tags', $fields, true)) {
                $rules->tags = new TagsRule(words: $tag_ids, mode: 'AND');
            }
        }

        if (in_array('author', $fields, true)) {
            // does this Piwigo has authors for current user?
            $has_author = $this->imageService->hasAccessibleImageWithAuthor(
                $this->permissionService->getPermissionCriteria()
            );

            if ($has_author) {
                $rules->author = new AuthorRule(words: []);
            }
        }

        if (in_array('added_by', $fields, true)) {
            $rules->addedBy = [];
        }
        if (in_array('filetypes', $fields, true)) {
            $rules->filetypes = [];
        }
        if (in_array('ratios', $fields, true)) {
            $rules->ratios = [];
        }
        if (in_array('ratings', $fields, true)) {
            $rules->ratings = [];
        }

        if (in_array('date_posted', $fields, true)) {
            $rules->datePosted = new DateRule();
        }
        if (in_array('date_created', $fields, true)) {
            $rules->dateCreated = new DateRule();
        }

        if (in_array('filesize_min', $fields, true)) {
            $rules->filesizeMin = '';
        }
        if (in_array('filesize_max', $fields, true)) {
            $rules->filesizeMax = '';
        }
        if (in_array('width_min', $fields, true)) {
            $rules->widthMin = '';
        }
        if (in_array('width_max', $fields, true)) {
            $rules->widthMax = '';
        }
        if (in_array('height_min', $fields, true)) {
            $rules->heightMin = '';
        }
        if (in_array('height_max', $fields, true)) {
            $rules->heightMax = '';
        }

        $search = [
            'mode' => 'AND',
            'fields' => $rules->toArray(),
        ];

        [$search_uuid, $search_url] = $searchService->saveSearch($search, $this->urlService);
        $this->redirectService->redirect($search_url);
    }
}
