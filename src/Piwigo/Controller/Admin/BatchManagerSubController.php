<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\BatchManager\Projection\DimensionFilter;
use Piwigo\Admin\BatchManager\Projection\DuplicateFieldFlags;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilter;
use Piwigo\Admin\BatchManagerGlobalPageRenderer;
use Piwigo\Admin\BatchManagerUnitPageRenderer;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Event\BatchManagerPerformFilters;
use Piwigo\Controller\Admin\Event\BatchManagerRegisterFilters;
use Piwigo\Controller\Admin\Event\PerformBatchManagerPrefilters;
use Piwigo\Controller\Admin\Projection\BatchManagerFilterOptionsPageContext;
use Piwigo\Controller\Admin\Projection\BatchManagerNoSearchResultsPageContext;
use Piwigo\Controller\Admin\Projection\BatchManagerSearchDebugPageContext;
use Piwigo\Controller\Admin\Request\BatchManagerRequest;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\SortRenderer;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageDuplicateField;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The tab-dispatch shell for admin/batch_manager.php (page slug
 * "batch_manager") -- same shape as AlbumSubController/PhotoSubController:
 * the shell logic lives in this sub-controller, and tab bodies are their
 * own renderer classes. This class handles session-filter parsing,
 * FilterResolver orchestration, pagination, the dimension/filesize
 * filter-form option aggregation, and tab dispatch to
 * BatchManagerGlobalPageRenderer/BatchManagerUnitPageRenderer. admin.php
 * itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so this class
 * has no check_status() call of its own.
 *
 * `action=empty_caddie` performs an unconditional `DELETE FROM caddie
 * WHERE user_id = ...`, so it requires CSRF verification
 * (`check_pwg_token()`) and carries a `pwg_token` on its link
 * (`CsrfService::check()` reads `$_REQUEST`, so a GET-carried token works
 * the same as a POST one). The other 2 GET actions (`delete_orphans`,
 * `sync_md5sum`) only render a `$_SESSION` message from an
 * already-validated count -- the real deletion/checksum work happens via
 * already-token-protected `ws.php` calls (`pwg.images.deleteOrphans`/
 * `pwg.images.setMd5sum`), so they need no token of their own.
 *
 * `array<string, mixed> $bulkFilter`/`$bulk_filter`/`$url_filter`
 * throughout this class are all `$_SESSION['bulk_manager_filter']` (or a
 * sub-array of it), itself built from raw `$_GET`/`$_POST` a few lines
 * above. Every real read below already narrows defensively
 * (`isset()`/`is_array()` + an is_*() check).
 */
final readonly class BatchManagerSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private CoreTabs $coreTabs,
        private SessionService $sessionService,
        private Translator $translator,
        private EventDispatcher $eventDispatcher,
        private ImageStdParams $imageStdParams,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private EntityManagerInterface $entityManager,
        private FilterResolver $filterResolver,
        private BatchManagerUnitPageRenderer $batchManagerUnitPageRenderer,
        private SearchService $searchService,
        private ActivityService $activityService,
        private ImageService $imageService,
        private TagService $tagService,
        private CategoryService $categoryService,
        private HtmlService $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Paths $paths,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        $batchManagerRequest = BatchManagerRequest::fromGlobals($this->inputValidator);

        $user_id = $this->currentUser->get()
            ->id;

        $available_permission_levels = $this->currentConfig->availablePermissionLevels;
        $conf_order_by = new SortRenderer($this->entityManager->getConnection())
            ->toSql($this->currentConfig->orderBy);

        // used both for the action-specific redirects below and for the
        // "category no longer exists" redirect further down
        $get_page = $batchManagerRequest->page;

        $this->handleGetActions($batchManagerRequest, $get_page, $user_id);

        $this->resolveSessionFilter($batchManagerRequest, $available_permission_levels);

        /** @var array<string, mixed> $bulk_filter */
        $bulk_filter = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];

        $filter_resolver = $this->filterResolver;

        $duplicates_on_fields = null;
        $cat_elements_id = $this->computeCurrentSet(
            $filter_resolver,
            $bulk_filter,
            $get_page,
            $user_id,
            $conf_order_by,
            $duplicates_on_fields,
        );

        // $start contains the number of the first element in its category.
        // For example, $start = 12 means we must show elements #12 and the
        // renderer's own nb_images next elements.

        $start = $batchManagerRequest->start;

        $tab = $batchManagerRequest->tab;

        // CoreTabs::setContext() needs an explicit managerLink for this
        // page (same as ConfigurationSubController's own $conf_link), or
        // this page's tab strip renders broken relative hrefs.
        $this->coreTabs->setContext(new CoreTabsContext(managerLink: $this->urlService->getRootUrl() . 'admin.php?page=batch_manager&amp;mode='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('batch_manager');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $template->assignContext(new BatchManagerFilterOptionsPageContext(
            dimensions: $this->computeDimensionOptions($bulk_filter),
            filesize: $this->computeFilesizeOptions($bulk_filter),
        ));

        if ($tab === 'unit') {
            $this->batchManagerUnitPageRenderer
                ->render($cat_elements_id, $start);
        } else {
            new BatchManagerGlobalPageRenderer($this->lang, $this->redirectService, $this->urlService, $this->currentLogger, $this->sessionService, $this->translator, $this->eventDispatcher, $this->imageStdParams, $this->pageState, $this->currentUser, $this->currentTemplate, $this->entityManager, $this->activityService, $this->tagService, $this->categoryService, $this->imageService, $this->htmlRenderer, $this->currentConfig, $this->csrfService, $this->inputValidator, $this->paths)
                ->render($cat_elements_id, $start, $duplicates_on_fields);
        }
    }

    private function handleGetActions(BatchManagerRequest $batchManagerRequest, string $getPage, UserId $userId): void
    {
        $action = $batchManagerRequest->action;
        if ($action === null) {
            return;
        }

        if ($action === 'empty_caddie') {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $this->entityManager->getRepository(CaddieEntity::class)
                ->replaceForUser($userId->value, []);

            $_SESSION['page_infos'] = [
                $this->lang->t('Information data registered in database'),
            ];

            $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=' . $getPage);
        }

        if ($action === 'delete_orphans' and $batchManagerRequest->nbOrphansDeleted !== null) {
            $nb_orphans_deleted = $batchManagerRequest->nbOrphansDeleted;

            if ($nb_orphans_deleted > 0) {
                if (! isset($_SESSION['page_infos']) || ! is_array($_SESSION['page_infos'])) {
                    $_SESSION['page_infos'] = [];
                }
                $_SESSION['page_infos'][] = $this->translator->plural(
                    '%d photo was deleted',
                    '%d photos were deleted',
                    $nb_orphans_deleted
                );

                $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=' . $getPage);
            }
        }

        if ($action === 'sync_md5sum' and $batchManagerRequest->nbMd5sumAdded !== null) {
            $nb_md5sum_added = $batchManagerRequest->nbMd5sumAdded;

            if ($nb_md5sum_added > 0) {
                if (! isset($_SESSION['page_infos']) || ! is_array($_SESSION['page_infos'])) {
                    $_SESSION['page_infos'] = [];
                }
                $_SESSION['page_infos'][] = $this->translator->plural(
                    '%d checksums were added',
                    '%d checksums were added',
                    $nb_md5sum_added
                );

                $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=' . $getPage);
            }
        }
    }

    /**
     * @param list<int> $availablePermissionLevels matches
     *   CurrentConfig::availablePermissionLevels()'s own already-precise
     *   return type (this method's only real caller passes it straight through)
     */
    private function resolveSessionFilter(BatchManagerRequest $batchManagerRequest, array $availablePermissionLevels): void
    {
        $post = $batchManagerRequest->post;

        // filters from form
        if ($batchManagerRequest->isSubmitFilter) {
            // Built up locally (instead of writing straight into
            // $_SESSION['bulk_manager_filter']) for the same reason the
            // urlFilterPresent branch below already does: PHPStan cannot
            // keep a precise array shape for a superglobal offset mutated
            // across many independent if-blocks and impure method calls
            // (validate()/getTagIds() below invalidate any narrowing PHPStan
            // could otherwise track); the whole array is committed to the
            // session once, after every filter's been applied.
            /** @var array<string, mixed> $bulk_manager_filter */
            $bulk_manager_filter = [];

            if (isset($post['filter_prefilter_use'])) {
                $bulk_manager_filter['prefilter'] = $post['filter_prefilter'];

                if ($post['filter_prefilter'] === 'duplicates') {
                    $has_options = false;

                    if (isset($post['filter_duplicates_checksum'])) {
                        $bulk_manager_filter['duplicates_checksum'] = true;
                        $has_options = true;
                    }

                    if (isset($post['filter_duplicates_date'])) {
                        $bulk_manager_filter['duplicates_date'] = true;
                        $has_options = true;
                    }

                    if (isset($post['filter_duplicates_dimensions'])) {
                        $bulk_manager_filter['duplicates_dimensions'] = true;
                        $has_options = true;
                    }

                    if (! $has_options or isset($post['filter_duplicates_filename'])) {
                        $bulk_manager_filter['duplicates_filename'] = true;
                    }
                }
            }

            if (isset($post['filter_category_use'])) {
                $this->inputValidator
                    ->validate('filter_category', $post, false, ValidationPattern::ID);

                $bulk_manager_filter['category'] = $post['filter_category'];

                if (isset($post['filter_category_recursive'])) {
                    $bulk_manager_filter['category_recursive'] = true;
                }
            }

            if (isset($post['filter_tags_use'])) {
                $raw_filter_tags = $post['filter_tags'] ?? '';
                if (is_array($raw_filter_tags)) {
                    $filter_tags = [];
                    foreach ($raw_filter_tags as $raw_filter_tag) {
                        if (is_string($raw_filter_tag)) {
                            $filter_tags[] = $raw_filter_tag;
                        }
                    }
                } else {
                    $filter_tags = is_string($raw_filter_tags) ? $raw_filter_tags : '';
                }
                // $_SESSION crosses a serialization boundary read back by a
                // later, unrelated request (see the plain is_numeric()
                // read further down in this same class) -- unwrap to raw
                // ints here rather than storing TagId objects.
                $bulk_manager_filter['tags'] = array_map(
                    static fn (TagId $id): int => $id->value,
                    $this->tagService->getTagIds($filter_tags, false)
                );

                if (isset($post['tag_mode']) and in_array($post['tag_mode'], ['AND', 'OR'], true)) {
                    $bulk_manager_filter['tag_mode'] = $post['tag_mode'];
                }
            }

            if (isset($post['filter_level_use'])) {
                $this->inputValidator
                    ->validate('filter_level', $post, false, '/^\d+$/');

                // $_POST['filter_level'] is a numeric string (validated by
                // check_input_parameter() above); $availablePermissionLevels holds
                // real ints (config_default.inc.php seeds [0, 1, 2, 4, 8]) -- cast
                // before the strict in_array() so a numeric-string match still
                // succeeds, matching the original loose-comparison behavior.
                $filter_level_raw = $post['filter_level'] ?? null;
                if (is_numeric($filter_level_raw) && in_array((int) $filter_level_raw, $availablePermissionLevels, true)) {
                    $bulk_manager_filter['level'] = (int) $filter_level_raw;

                    if (isset($post['filter_level_include_lower'])) {
                        $bulk_manager_filter['level_include_lower'] = true;
                    }
                }
            }

            if (isset($post['filter_dimension_use'])) {
                // Same reason as the urlFilterPresent branch's own $dimension
                // local below: $bulk_manager_filter['dimension']'s value type
                // is mixed (per the array<string, mixed> shape above), so
                // PHPStan won't allow offset-writing into it a second level
                // deep -- build the sub-array locally, commit it once.
                $dimension = [];
                foreach (['min_width', 'max_width', 'min_height', 'max_height'] as $type) {
                    if (filter_var($post['filter_dimension_' . $type], FILTER_VALIDATE_INT) !== false) {
                        $dimension[$type] = $post['filter_dimension_' . $type];
                    }
                }
                foreach (['min_ratio', 'max_ratio'] as $type) {
                    if (filter_var($post['filter_dimension_' . $type], FILTER_VALIDATE_FLOAT) !== false) {
                        $dimension[$type] = $post['filter_dimension_' . $type];
                    }
                }
                $bulk_manager_filter['dimension'] = $dimension;
            }

            if (isset($post['filter_filesize_use'])) {
                $filesize = [];
                foreach (['min', 'max'] as $type) {
                    if (filter_var($post['filter_filesize_' . $type], FILTER_VALIDATE_FLOAT) !== false) {
                        $filesize[$type] = $post['filter_filesize_' . $type];
                    }
                }
                $bulk_manager_filter['filesize'] = $filesize;
            }

            if (isset($post['filter_search_use'])) {
                // $bulk_manager_filter starts empty above, so 'search' can't
                // already exist here.
                $bulk_manager_filter['search'] = [];
                $bulk_manager_filter['search']['q'] = $post['q'];
            }

            $registerFiltersEvent = $this->eventDispatcher->dispatch(new BatchManagerRegisterFilters($bulk_manager_filter));
            $_SESSION['bulk_manager_filter'] = $registerFiltersEvent->bulkManagerFilter;
        }
        // filters from url
        elseif ($batchManagerRequest->urlFilterPresent) {
            // Built up locally (instead of writing straight into
            // $_SESSION['bulk_manager_filter']) because PHPStan cannot keep a
            // precise array shape for a superglobal offset that is mutated with
            // dynamic keys across loop iterations; the whole array is committed to
            // the session once, after the loop.
            /** @var array<string, mixed> $url_filter */
            $url_filter = [];

            foreach ($batchManagerRequest->urlFilterTokens as $filter) {
                $filter_parts = explode('-', $filter, 2);
                $type = $filter_parts[0];
                $value = $filter_parts[1] ?? '';

                switch ($type) {
                    case 'prefilter':
                        if ((bool) preg_match('/^duplicates-?/', $value)) {
                            $duplicate_field = explode('-', $value, 2)[1] ?? '';
                            $url_filter['prefilter'] = 'duplicates';

                            if (in_array($duplicate_field, ['filename', 'checksum', 'date', 'dimensions'], true)) {
                                $url_filter['duplicates_' . $duplicate_field] = true;
                            }
                        } else {
                            $url_filter['prefilter'] = $value;
                        }
                        break;

                    case 'album': case 'category': case 'cat':
                        if (is_numeric($value)) {
                            $url_filter['category'] = $value;
                        }
                        break;

                    case 'tag':
                        if (is_numeric($value)) {
                            $url_filter['tags'] = [$value];
                            $url_filter['tag_mode'] = 'AND';
                        }
                        break;

                    case 'level':
                        if (is_numeric($value) && in_array((int) $value, $availablePermissionLevels, true)) {
                            $url_filter['level'] = $value;
                        }
                        break;

                    case 'search':
                        $url_filter['search'] = [
                            'q' => $value,
                        ];
                        break;

                    case 'dimension':
                        // filter=dimension-w10..1000-h100..5000-r0.70..2
                        $dim_map = [
                            'w' => 'width',
                            'h' => 'height',
                            'r' => 'ratio',
                        ];
                        // accumulated locally: a single 'dimension' filter token can
                        // set width/height/ratio bounds together, across several
                        // iterations of this inner loop.
                        $dimension = is_array($url_filter['dimension'] ?? null) ? $url_filter['dimension'] : [];
                        foreach (explode('-', $value) as $part) {
                            $values = explode('..', substr($part, 1));
                            if (isset($dim_map[$part[0]])) {
                                $type = $dim_map[$part[0]];

                                $filter_to_validate_for_type = [
                                    'width' => FILTER_VALIDATE_INT,
                                    'height' => FILTER_VALIDATE_INT,
                                    'ratio' => FILTER_VALIDATE_FLOAT,
                                ];

                                $valid = true;
                                foreach ($values as $bound_value) {
                                    if (filter_var($bound_value, $filter_to_validate_for_type[$type]) === false) {
                                        $valid = false;
                                    }
                                }

                                if ($valid) {
                                    [
                                        $dimension['min_' . $type],
                                        $dimension['max_' . $type]
                                    ] = $values;
                                }
                            }
                        }
                        $url_filter['dimension'] = $dimension;
                        break;

                    case 'filesize':
                        // filter=filesize-1..10
                        $values = explode('..', $value);

                        $valid = true;
                        foreach ($values as $bound_value) {
                            if (filter_var($bound_value, FILTER_VALIDATE_FLOAT) === false) {
                                $valid = false;
                            }
                        }

                        if ($valid) {
                            $url_filter['filesize'] = [
                                'min' => $values[0],
                                'max' => $values[1],
                            ];
                        }

                        break;
                }
            }

            $_SESSION['bulk_manager_filter'] = $url_filter;
        }

        if (! isset($_SESSION['bulk_manager_filter']) || $_SESSION['bulk_manager_filter'] === []) {
            $_SESSION['bulk_manager_filter'] = [
                'prefilter' => 'caddie',
            ];
        }

        if (! is_array($_SESSION['bulk_manager_filter'])) {
            // Defensive: bulk_manager_filter is only ever written as an array by
            // this method (the $_POST/$_GET branches above, or the default fallback
            // just above); this guards against corrupted/foreign session state,
            // and lets PHPStan track a real array shape for the reads below.
            $_SESSION['bulk_manager_filter'] = [
                'prefilter' => 'caddie',
            ];
        }
    }

    /**
     * @param array<string, mixed> $bulkFilter
     * @param ?list<ImageDuplicateField> $duplicatesOnFields by-ref out-param, only ever
     *   computed for the 'duplicates' prefilter -- fed back to the caller
     *   so it can pass it on to BatchManagerGlobalPageRenderer for its own
     *   duplicates-mode thumbnail ordering.
     *
     * @return array<array-key, int|string|float|bool> a scalar-filtered
     *   image id set -- see the array_filter(..., is_scalar(...)) calls
     *   below, the same "filter sets are always image id lists" contract
     *   BatchManagerUnitPageRenderer/BatchManagerGlobalPageRenderer/
     *   FilterPanelRenderer's own $catElementsId already documents
     */
    private function computeCurrentSet(
        FilterResolver $filterResolver,
        array $bulkFilter,
        string $getPage,
        UserId $userId,
        string $confOrderBy,
        ?array &$duplicatesOnFields = null,
    ): array {
        $template = $this->currentTemplate->get();

        $filter_sets = [];
        if (isset($bulkFilter['prefilter']) && is_string($bulkFilter['prefilter'])) {
            $prefilter = $bulkFilter['prefilter'];
            $duplicateFlags = DuplicateFieldFlags::fromBulkFilter($bulkFilter);

            if ($prefilter === 'duplicates') {
                $duplicatesOnFields = $filterResolver->duplicateFieldsFromFilter($duplicateFlags);
            }

            $prefilter_result = match ($prefilter) {
                // getOrphans()/getPhotosNoMd5sum() are existing, already-tested
                // ImageService methods -- not duplicated into FilterResolver.
                'no_album' => $this->imageService->getOrphans(),
                'no_sync_md5sum' => $this->imageService->getPhotosNoMd5sum(),
                default => $filterResolver->resolvePrefilter($prefilter, $duplicateFlags, count($bulkFilter) === 1, $userId, $confOrderBy),
            };

            if ($prefilter_result !== null) {
                $filter_sets[] = $prefilter_result;
            } else {
                $filter_sets = $this->eventDispatcher->dispatch(new PerformBatchManagerPrefilters($filter_sets, $prefilter))
                    ->filterSets;
            }
        }

        if (isset($bulkFilter['category']) && is_numeric($bulkFilter['category'])) {
            $category_id = (int) $bulkFilter['category'];

            // we need to check the category still exists (it may have been deleted since it was added in the session)
            if (! $filterResolver->categoryExists(CategoryId::from($category_id))) {
                unset($_SESSION['bulk_manager_filter']);
                $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=' . $getPage);
            }

            $categories = isset($bulkFilter['category_recursive'])
                ? $this->categoryService->getSubcatIds([$category_id])
                : [$category_id];
            $categories = array_values(array_map(intval(...), array_filter($categories, is_numeric(...))));

            $filter_sets[] = $filterResolver->categoryImageIds($categories);
        }

        if (isset($bulkFilter['level']) && is_numeric($bulkFilter['level'])) {
            $filter_sets[] = $filterResolver->levelPhotoIds(
                (int) $bulkFilter['level'],
                isset($bulkFilter['level_include_lower']),
                $confOrderBy
            );
        }

        if (is_array($bulkFilter['tags'] ?? null) && count($bulkFilter['tags']) > 0) {
            $filter_tag_ids = [];
            foreach ($bulkFilter['tags'] as $filter_tag_id) {
                if (is_numeric($filter_tag_id)) {
                    $filter_tag_ids[] = (int) $filter_tag_id;
                }
            }

            $filter_tag_mode = is_string($bulkFilter['tag_mode'] ?? null) ? $bulkFilter['tag_mode'] : 'AND';

            $filter_sets[] = $this->tagService
                ->getImageIdsForTags(
                    array_map(TagId::from(...), $filter_tag_ids),
                    $filter_tag_mode,
                    null,
                    null,
                    false // we don't apply permissions in administration screens
                );
        }

        if (isset($bulkFilter['dimension']) && is_array($bulkFilter['dimension'])) {
            // $bulkFilter is only known as array<string, mixed>, so a nested array
            // offset only narrows to array<mixed, mixed> after is_array() --
            // rebuild with only string keys so dimensionPhotoIds()'s declared
            // array<string, mixed> parameter type-checks against a real, verified
            // shape rather than a trust-me cast.
            $filter_dimension = [];
            foreach ($bulkFilter['dimension'] as $dimension_key => $dimension_value) {
                if (is_string($dimension_key)) {
                    $filter_dimension[$dimension_key] = $dimension_value;
                }
            }
            $dimension_ids = $filterResolver->dimensionPhotoIds(DimensionFilter::fromArray($filter_dimension), $confOrderBy);
            if ($dimension_ids !== null) {
                $filter_sets[] = $dimension_ids;
            }
        }

        if (isset($bulkFilter['filesize']) && is_array($bulkFilter['filesize'])) {
            $filter_filesize = [];
            foreach ($bulkFilter['filesize'] as $filesize_key => $filesize_value) {
                if (is_string($filesize_key)) {
                    $filter_filesize[$filesize_key] = $filesize_value;
                }
            }
            $filesize_ids = $filterResolver->filesizePhotoIds(FilesizeFilter::fromArray($filter_filesize), $confOrderBy);
            if ($filesize_ids !== null) {
                $filter_sets[] = $filesize_ids;
            }
        }

        if (isset($bulkFilter['search']) && is_array($bulkFilter['search'])
            && isset($bulkFilter['search']['q']) && is_string($bulkFilter['search']['q'])
            && (bool) strlen($bulkFilter['search']['q'])) {
            $res = $this->searchService->getQuickSearchResultsNoCache($bulkFilter['search']['q'], [
                'permissions' => false,
            ]);
            $res_debug = $res['debug'];
            unset($res['debug']);
            $template->assignContext(new BatchManagerSearchDebugPageContext(implode("\n", $res_debug)));
            $res_items = $res['items'];
            if (count($res_items) > 0 && is_array($res['qs']['unmatched_terms'] ?? null) && count($res['qs']['unmatched_terms']) > 0) {
                $unmatched_terms = array_filter($res['qs']['unmatched_terms'], is_string(...));
                $template->assignContext(new BatchManagerNoSearchResultsPageContext(array_values(array_map(htmlspecialchars(...), $unmatched_terms))));
            }
            $filter_sets[] = $res_items;
        }

        $filter_sets = $this->eventDispatcher->dispatch(new BatchManagerPerformFilters($filter_sets, $bulkFilter))
            ->filterSets;

        $current_set = array_shift($filter_sets);
        // filter sets are always image id lists (either this method's own search
        // results or a plugin-returned replacement set), so only scalar elements
        // are ever meaningful here -- array_intersect() also requires
        // string-castable values.
        $current_set = is_array($current_set) ? array_filter($current_set, is_scalar(...)) : [];
        foreach ($filter_sets as $set) {
            if (is_array($set)) {
                $current_set = array_intersect($current_set, array_filter($set, is_scalar(...)));
            }
        }

        return $current_set;
    }

    /**
     * @param array<string, mixed> $bulkFilter
     *
     * @return array<string, mixed>
     */
    private function computeDimensionOptions(array $bulkFilter): array
    {
        $widths = [];
        $heights = [];
        $ratios = [];
        $dimensions = [];

        // get all width, height and ratios
        foreach ($this->imageService->getDistinctDimensions() as $row) {
            if ($row['width'] > 0 && $row['height'] > 0) {
                $widths[] = $row['width'];
                $heights[] = $row['height'];
                $ratios[] = floor($row['width'] / $row['height'] * 100.0) / 100.0;
            }
        }
        if ($widths === []) { // arbitrary values, only used when no photos on the gallery
            $widths = [600, 1920, 3500];
            $heights = [480, 1080, 2300];
            $ratios = [1.25, 1.52, 1.78];
        }

        $dimension_arrays = [
            'widths' => &$widths,
            'heights' => &$heights,
            'ratios' => &$ratios,
        ];
        foreach ($dimension_arrays as $type => &$dimension_values) {
            $dimension_values = array_unique($dimension_values);
            sort($dimension_values);
            $dimensions[$type] = implode(',', $dimension_values);
        }
        unset($dimension_values);

        $dimensions['bounds'] = [
            'min_width' => $widths[0],
            'max_width' => end($widths),
            'min_height' => $heights[0],
            'max_height' => end($heights),
            'min_ratio' => $ratios[0],
            'max_ratio' => end($ratios),
        ];

        // find ratio categories
        $ratio_categories = [
            'portrait' => [],
            'square' => [],
            'landscape' => [],
            'panorama' => [],
        ];

        foreach ($ratios as $ratio) {
            if ($ratio < 0.95) {
                $ratio_categories['portrait'][] = $ratio;
            } elseif ($ratio >= 0.95 and $ratio <= 1.05) {
                $ratio_categories['square'][] = $ratio;
            } elseif ($ratio > 1.05 and $ratio < 2) {
                $ratio_categories['landscape'][] = $ratio;
            } elseif ($ratio >= 2) {
                $ratio_categories['panorama'][] = $ratio;
            }
        }

        foreach ($ratio_categories as $type => $category) {
            if (count($category) > 0) {
                $dimensions['ratio_' . $type] = [
                    'min' => $category[0],
                    'max' => end($category),
                ];
            }
        }

        // selected=bound if nothing selected
        $selected_dimension = isset($bulkFilter['dimension']) && is_array($bulkFilter['dimension']) ? $bulkFilter['dimension'] : [];
        foreach (array_keys($dimensions['bounds']) as $type) {
            $dimensions['selected'][$type] = $selected_dimension[$type]
              ?? $dimensions['bounds'][$type]
            ;
        }

        return $dimensions;
    }

    /**
     * @param array<string, mixed> $bulkFilter
     *
     * @return array<string, mixed>
     */
    private function computeFilesizeOptions(array $bulkFilter): array
    {
        $filesizes = [];
        $filesize = [];

        foreach ($this->imageService->getDistinctFilesizes() as $row) {
            $filesizes[] = sprintf('%.1f', $row['filesize'] / 1024.0);
        }

        if ($filesizes === []) { // arbitrary values, only used when no photos on the gallery
            $filesizes = [0, 1, 2, 5, 8, 15];
        }

        $filesizes = array_unique($filesizes);
        sort($filesizes);

        $filesize['list'] = implode(',', $filesizes);

        $filesize['bounds'] = [
            'min' => $filesizes[0],
            'max' => end($filesizes),
        ];

        // selected=bound if nothing selected
        $selected_filesize = isset($bulkFilter['filesize']) && is_array($bulkFilter['filesize']) ? $bulkFilter['filesize'] : [];
        foreach (array_keys($filesize['bounds']) as $type) {
            $filesize['selected'][$type] = $selected_filesize[$type]
              ?? $filesize['bounds'][$type]
            ;
        }

        return $filesize;
    }
}
