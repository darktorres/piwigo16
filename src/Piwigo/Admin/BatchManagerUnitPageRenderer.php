<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\BatchManager\FilterPanelRenderer;
use Piwigo\Admin\BatchManager\Projection\BulkManagerFilter;
use Piwigo\Admin\Event\BatchManagerUnitRendered;
use Piwigo\Admin\Event\BatchManagerUnitRendering;
use Piwigo\Admin\Projection\BatchManagerUnitView;
use Piwigo\Admin\Request\BatchManagerUnitRequest;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Cache\PermissionsCachePool;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\CategoryIdNamePermalink;
use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\PaginationService;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\SortRenderer;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\ForbiddenCategoriesCache;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/batch_manager_unit.php (the "unit" mode tab of the
 * "batch_manager" page slug, dispatched by BatchManagerSubController) --
 * per-photo inline edit grid (name/author/level/description/date/tags for
 * each photo individually, in the current filtered selection).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so this
 * renderer does not call check_status() itself. The one real mutation path
 * (isset($_POST['submit'])) already has its own check_pwg_token() call --
 * no CSRF gap here.
 */
final readonly class BatchManagerUnitPageRenderer
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ProcessCache $processCache,
        private LoadedPlugins $loadedPlugins,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private EntityManagerInterface $entityManager,
        private TagService $tagService,
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private ImageService $imageService,
        private UserService $userService,
        private HtmlService $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private PermissionsCachePool $permissionsCachePool,
        private Renderer $renderer,
        private Paths $paths,
    ) {}

    /**
     * @param array<array-key, int|string|float|bool> $catElementsId a
     *   scalar-filtered image id set -- see
     *   {@see \Piwigo\Controller\Admin\BatchManagerSubController::computeCurrentSet()}
     */
    public function render(array $catElementsId, int $pageStart): AdminPageResult
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;

        $this->eventDispatcher->dispatch(new BatchManagerUnitRendering());

        $batchManagerUnitRequest = BatchManagerUnitRequest::fromGlobals($this->inputValidator);

        if ($batchManagerUnitRequest->isSubmitted) {
            $this->csrfService
                ->checkOrFail($htmlRenderer, $this->redirectService);
            $collection = explode(',', $batchManagerUnitRequest->elementIds);

            $datas = [];

            $tagService = $this->tagService;

            foreach ($this->imageService->getIdsAndDatesForBatchUnitSave($collection) as $row) {
                $row_id_str = (string) $row['id'];
                $image_id = $row['id'];

                $data = [];

                $data['id'] = $row['id'];
                $data['name'] = $batchManagerUnitRequest->post['name-' . $row_id_str] ?? null;
                $data['author'] = $batchManagerUnitRequest->post['author-' . $row_id_str] ?? null;
                $data['level'] = $batchManagerUnitRequest->post['level-' . $row_id_str] ?? null;

                if ($this->currentConfig->allowHtmlDescriptions) {
                    $data['comment'] = $batchManagerUnitRequest->post['description-' . $row_id_str] ?? null;
                } else {
                    $description_post = $batchManagerUnitRequest->post['description-' . $row_id_str] ?? null;
                    $data['comment'] = strip_tags(is_string($description_post) ? $description_post : '');
                }

                if (($batchManagerUnitRequest->post['date_creation-' . $row_id_str] ?? '') !== '') {
                    $data['date_creation'] = $batchManagerUnitRequest->post['date_creation-' . $row_id_str];
                } else {
                    $data['date_creation'] = null;
                }

                $datas[] = $data;

                // tags management
                $tag_ids = [];
                $raw_tags_post = $batchManagerUnitRequest->post['tags-' . $row_id_str] ?? null;
                if ($raw_tags_post !== null && $raw_tags_post !== '' && $raw_tags_post !== '0' && $raw_tags_post !== []) {
                    if (is_array($raw_tags_post)) {
                        $raw_tags_post_strings = [];
                        foreach ($raw_tags_post as $raw_tag) {
                            if (is_string($raw_tag)) {
                                $raw_tags_post_strings[] = $raw_tag;
                            }
                        }
                        $tag_ids = $tagService->getTagIds($raw_tags_post_strings);
                    } elseif (is_string($raw_tags_post)) {
                        $tag_ids = $tagService->getTagIds($raw_tags_post);
                    }
                }
                $tagService->setTags($tag_ids, $image_id);
            }

            $this->imageService->massUpdateFields(
                [
                    'primary' => ['id'],
                    'update' => ['name', 'author', 'level', 'comment', 'date_creation'],
                ],
                $datas
            );
            $this->entityManager->clear();

            $this->pageState->addInfo($this->lang->t('Photo informations updated'));
            PermissionCacheInvalidator::invalidate();
        }

        // collection
        $collection = [];
        if ($batchManagerUnitRequest->nbPhotosDeletedPresent) {
            // let's fake a collection (we don't know the image_ids so we use "null", we only
            // care about the number of items here)
            $collection = array_fill(0, $batchManagerUnitRequest->nbPhotosDeleted, null);
        } elseif ($batchManagerUnitRequest->isSetSelected) {
            // Here we don't use check_input_parameter because preg_match has a limit in
            // the repetitive pattern. Found a limit to 3276 but may depend on memory.
            //
            // check_input_parameter('whole_set', $_POST, false, '/^\d+(,\d+)*$/');
            //
            // Instead, let's break the input parameter into pieces and check pieces one by one.
            $collection = explode(',', $batchManagerUnitRequest->wholeSet);

            foreach ($collection as $id) {
                if (! (bool) preg_match('/^\d+$/', $id)) {
                    $htmlRenderer->fatalError('Invalid request parameter "whole_set"');
                }
            }
        } elseif ($batchManagerUnitRequest->selectionPresent) {
            $collection = $batchManagerUnitRequest->selection;
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php';

        // $catElementsId is a list of scalar image ids; narrowed once here
        // for every use below (including the FilterPanelRenderer call).
        $cat_elements_id = array_filter($catElementsId, is_scalar(...));
        $page_start = $pageStart;

        new FilterPanelRenderer()
            ->render($this->lang, $template, $base_url, $collection, $cat_elements_id, $page_start, $this->urlService, $this->eventDispatcher, $this->pageState, $this->tagService, $this->htmlRenderer, $this->currentConfig, $this->entityManager, $this->csrfService);

        // Genuinely ambient -- FilterPanelRenderer::render() above
        // assigns it directly onto this same Template instance's own
        // $vars bag (still the old assignContext() mechanism), not via
        // this class's own BatchManagerUnitView construction below.
        $associated_categories_raw = $template->getTemplateVars('associated_categories');

        // batch_manager_filter.inc.latte's own exposedPageData() needs --
        // 'dimensions'/'filesize' are assigned by
        // BatchManagerSubController::handle() (before this renderer
        // runs at all), 'filter_category_selected' by
        // FilterPanelRenderer::render() above, same ambient-bag shape.
        $filter_dimensions_raw = $template->getTemplateVars('dimensions');
        $filter_filesize_raw = $template->getTemplateVars('filesize');
        $filter_category_selected_raw = $template->getTemplateVars('filter_category_selected');

        // how many items to display on this page
        if ($batchManagerUnitRequest->displayRequested) {
            // \Piwigo\Config\ConfigDb::confUpdateParam('batch_manager_images_per_page_unit' , intval($_GET['display']));
            // $nb_images = \Piwigo\Config\CurrentConfig::batchManagerImagesPerPageUnit();
            $nb_images = $batchManagerUnitRequest->display;
        } elseif (in_array($this->currentConfig->batchManagerImagesPerPageUnit, [5, 10, 50], true)) {
            $nb_images = $this->currentConfig->batchManagerImagesPerPageUnit;
        } else {
            $nb_images = 5;
        }

        $nav_bar = null;
        $element_ids_value = null;
        $elements = [];

        if (count($cat_elements_id) > 0) {
            $page_nb_images = $nb_images;

            $nav_bar = new PaginationService($this->currentConfig)
                ->createNavigationBar($base_url . $this->urlService->getQueryStringDiff(['start']), count($cat_elements_id), $page_start, $page_nb_images);

            $element_ids = [];

            // Locally-typed snapshot of $_SESSION['bulk_manager_filter']. It is
            // always written as an array by BatchManagerSubController (which runs
            // before dispatching to this renderer); this guards against
            // corrupted/foreign session state (this file never writes to
            // $_SESSION['bulk_manager_filter']).
            /** @var array<string, mixed> $bulk_manager_filter_raw */
            $bulk_manager_filter_raw = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];
            $bulk_manager_filter = BulkManagerFilter::fromArray($bulk_manager_filter_raw);

            $is_category = false;
            $filter_category_id = 0;
            if ($bulk_manager_filter->category !== null && ! $bulk_manager_filter->categoryRecursive) {
                $is_category = true;
                $filter_category_id = $bulk_manager_filter->category;
            }

            if ($bulk_manager_filter->prefilter === 'duplicates') {
                $order_by = ' ORDER BY file, id';
            } else {
                // order_by is a raw "ORDER BY ..." SQL fragment string --
                // see CurrentConfig::orderBy()'s own docblock.
                $order_by = new SortRenderer($this->entityManager->getConnection())
                    ->toSql($this->currentConfig->orderBy);
            }

            if ($is_category) {
                $category_info = $this->categoryService->getCategoryInfo($filter_category_id);

                $order_by = new SortRenderer($this->entityManager->getConnection())
                    ->toSql($this->currentConfig->orderByInsideCategory);
                $category_image_order = $category_info instanceof CategoryInfo ? $category_info->imageOrder : null;
                if (is_string($category_image_order) && $category_image_order !== '') {
                    $order_by = ' ORDER BY ' . $category_image_order;
                }
            }

            $images = $this->imageService->getBatchManagerUnitRows($cat_elements_id, $is_category ? $filter_category_id : null, $order_by, $page_nb_images, $page_start);
            $added_by_ids = array_values(array_unique(array_map(strval(...), array_filter(
                array_column($images, 'added_by'),
                static fn (mixed $v): bool => is_int($v) || is_string($v)
            ))));
            // Defaults to empty so the read inside the foreach loop below is always
            // a real array, whether or not $added_by_ids was non-empty (the
            // foreach loop only ever runs when $images -- and therefore
            // $added_by_ids -- is non-empty, but this default avoids relying on
            // that cross-block invariant).
            $added_by_username_of = [];
            if (count($added_by_ids) > 0) {
                $added_by_username_of = $this->userService->getUsernamesByIds($added_by_ids);
            }

            $tagService = $this->tagService;
            $imageService = $this->imageService;

            foreach ($images as $row) {
                // 'images'.id is a NOT NULL auto_increment primary key; this
                // guard only defends against the generic mixed element type a
                // fetched row carries for every column.
                if ($row['id'] === null || (! is_int($row['id']) && ! is_string($row['id']))) {
                    continue;
                }
                $row_id = $row['id'];
                $row_id_str = (string) $row_id;

                $element_ids[] = $row_id;

                // This image's own storage category (the album it's physically
                // stored under), used below to highlight STORAGE_CATEGORY among
                // its linked categories.
                $storage_category_id = is_numeric($row['storage_category_id'] ?? null) ? (int) $row['storage_category_id'] : null;

                $src_image = new SrcImage($row);

                $image_file = $row['file'];

                $tag_selection = $tagService->getTagListForImage(ImageId::from((int) $row_id_str), $htmlRenderer);

                $row_file = is_string($row['file']) ? $row['file'] : '';
                $legend = $htmlRenderer->renderElementName($row);
                if ($legend !== StringHelper::getNameFromFile($row_file)) {
                    $legend .= ' (' . $row_file . ')';
                }
                $row_path = is_scalar($row['path']) ? (string) $row['path'] : '';
                $extTab = explode('.', $row_path);

                // represent

                // categories

                $related_categories = [];
                $related_category_ids = [];
                $media = [
                    'image' => $imageService->getImageInfos($row_id, $htmlRenderer, true),
                ];
                // die_on_missing=true means getImageInfos() only returns null via
                // a fatal_error() path that never returns.
                assert($media['image'] !== null);

                foreach ($imageService->getCategoryLinksForImage(ImageId::from((int) $row_id)) as $item) {
                    $item_category_id = $item['category_id'];

                    $name =
                      $htmlRenderer->getCatDisplayNameCache(
                          $item['uppercats'],
                          $this->urlService->getRootUrl() . 'admin.php?page=album-'
                      );

                    $related_categories[$item_category_id] = [
                        'name' => $name,
                        'unlinkable' => $item_category_id !== $storage_category_id,
                    ];
                    $related_category_ids[] = $item_category_id;
                }

                // jump to link
                $image_file = $row['file'];

                $user = $this->currentUser->get();
                $authorizeds = array_diff(
                    array_map(
                        strval(...),
                        $imageService->getCategoryIdsForImage(ImageId::from((int) $row_id))
                    ),
                    explode(
                        ',',
                        new ForbiddenCategoriesCache($this->permissionService, $this->permissionsCachePool)
                            ->getForUser($user->id->value, $user->status->value)
                    )
                );

                // ProcessCache::get('cat_names') is populated by
                // get_cat_display_name_cache() (already called above, for
                // every $item in the while loop, before this point) as
                // array<int, CategoryIdNamePermalink> -- real DTOs, so each
                // one is unwrapped via toArray() before being handed to
                // UrlService, which narrows a non-array 'category' param to
                // [] and would otherwise drop the whole category segment.
                $cat_names_raw = $this->processCache->get('cat_names');
                $cat_names = is_array($cat_names_raw) ? $cat_names_raw : [];

                // The active category filter, straight from the local scope
                // that built this page's own image query -- not from $row,
                // which has never carried a 'cat_id' column.
                $row_cat_id = $is_category ? (string) $filter_category_id : null;

                if ($row_cat_id !== null
                and in_array($row_cat_id, $authorizeds, true)) {
                    $url_img = $this->urlService->makePictureUrl(
                        [
                            'image_id' => $row_id,
                            'image_file' => $image_file,
                            'category' => self::categoryUrlParam($cat_names, $row_cat_id),
                        ]
                    );
                } else {
                    foreach ($authorizeds as $category) {
                        $url_img = $this->urlService->makePictureUrl(
                            [
                                'image_id' => $row_id, // utile ?
                                'image_file' => $image_file,
                                'category' => self::categoryUrlParam($cat_names, $category),
                            ]
                        );
                        break;
                    }
                }
                $admin_photo_base_url = $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $row_id_str;
                $admin_url_start = $admin_photo_base_url . '-properties';
                $admin_url_start .= $row_cat_id !== null ? '&amp;cat_id=' . $row_cat_id : '';
                $selected_level = $row['level'] ?? null;
                $row_filesize = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
                $row_date_available = is_string($row['date_available']) ? $row['date_available'] : '';
                $row_width = is_scalar($row['width'] ?? null) ? (string) $row['width'] : '';
                $row_height = is_scalar($row['height'] ?? null) ? (string) $row['height'] : '';
                $row_name = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';
                $row_author = is_scalar($row['author'] ?? null) ? (string) $row['author'] : '';
                $row_comment = is_scalar($row['comment'] ?? null) ? (string) $row['comment'] : '';
                $row_added_by_raw = $row['added_by'] ?? null;
                $row_added_by = (is_int($row_added_by_raw) || is_string($row_added_by_raw)) ? $row_added_by_raw : null;

                $elements[] =
                    array_merge(
                        $row,
                        [
                            'ID' => $row_id,
                            'TN_SRC' => DerivativeImage::url(ImageStdParams::MEDIUM, $src_image),
                            'FILE_SRC' => DerivativeImage::url(ImageStdParams::LARGE, $src_image),
                            'LEGEND' => $legend,
                            'U_EDIT' => $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $row_id_str,
                            'NAME' => $row_name,
                            'AUTHOR' => $row_author,
                            'LEVEL' => ($row['level'] ?? '') !== '' && $row['level'] !== '0' ? ($row['level'] ?? '0') : '0',
                            'DESCRIPTION' => $row_comment,
                            'DATE_CREATION' => $row['date_creation'],
                            'TAGS' => $tag_selection,
                            'is_svg' => (strtoupper(end($extTab)) === 'SVG'),
                            'TITLE' => $htmlRenderer->renderElementName($row),
                            'DIMENSIONS' => $row_width . 'x' . $row_height . ' px',
                            'FORMAT' => ($row_width >= $row_height) ? 1 : 0, // 0:horizontal, 1:vertical
                            'FILESIZE' => $this->lang->t('%.2f MB', $row_filesize / 1024.0),
                            'REGISTRATION_DATE' => DateHelper::formatDate($row_date_available),
                            'EXT' => $this->lang->t('%s file type', end($extTab)),
                            'POST_DATE' => $this->lang->t('Added on %s', DateHelper::formatDate($row_date_available, ['day', 'month', 'year'])),
                            'AGE' => $this->lang->t(ucfirst(DateHelper::timeSince($row_date_available, 'year'))),
                            'ADDED_BY' => $this->lang->t('Added by %s', $row_added_by !== null ? ($added_by_username_of[$row_added_by] ?? $this->lang->t('N/A')) : $this->lang->t('N/A')),
                            'STATS' => $this->lang->t('Visited %d times', $row['hit']),
                            'FILE' => $this->lang->t('%s', $row['file']),
                            'related_categories' => $related_categories,
                            'related_category_ids' => json_encode($related_category_ids),
                            'U_JUMPTO' => (isset($url_img) and $user->level >= $media['image']['level']) ? $url_img : null,
                            'tag_selection' => $tag_selection,
                            'U_DOWNLOAD' => 'action.php?id=' . $row_id_str . '&amp;part=e&amp;pwg_token=' . $this->csrfService->getToken() . '&amp;download',
                            'U_HISTORY' => $this->urlService->getRootUrl() . 'admin.php?page=history&amp;filter_image_id=' . $row_id_str,
                            'U_ACTIVITY' => $this->urlService->getRootUrl() . 'admin.php?page=user_activity&photo=' . $row_id_str,
                            'U_DELETE' => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . $this->csrfService->getToken(),
                            'U_SYNC' => $admin_url_start . '&amp;sync_metadata=1',
                            'PATH' => $row['path'],
                            'level_options_selected' => [$selected_level],

                        ]
                    );
            }

            $element_ids_value = implode(',', $element_ids);
        }

        $adminContent = $this->renderer->render(new BatchManagerUnitView(
            uElementsPage: $base_url . $this->urlService->getQueryStringDiff(['display', 'start']),
            levelOptions: PermissionService::getPrivacyLevelOptions($this->currentConfig, $this->lang),
            csrfToken: $this->csrfService
                ->getToken(),
            activePlugins: array_keys($this->loadedPlugins->get()),
            perPage: $nb_images,
            navbar: $nav_bar,
            elementIds: $element_ids_value,
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['tags', 'categories']),
            elements: $elements,
            rootPath: $this->paths->root,
            jqueryCode: is_string($this->lang->langInfo()['jquery_code'] ?? null) ? $this->lang->langInfo()['jquery_code'] : '',
            colorscheme: $template->themeConf('colorscheme'),
            rootUrl: $this->urlService->getRootUrl(),
            associatedCategories: is_array($associated_categories_raw) ? $associated_categories_raw : [],
            filterDimensions: is_array($filter_dimensions_raw) ? $filter_dimensions_raw : [],
            filterFilesize: is_array($filter_filesize_raw) ? $filter_filesize_raw : [],
            filterCategorySelected: is_int($filter_category_selected_raw) ? $filter_category_selected_raw : null,
        ));

        $this->eventDispatcher->dispatch(new BatchManagerUnitRendered());

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Batch Manager'),
        );
    }

    /**
     * One entry of ProcessCache's 'cat_names' map, in the plain-array shape
     * {@see \Piwigo\Url\UrlService::makeSectionInUrl()} requires -- it
     * narrows a non-array `category` param to [] and then emits a category
     * segment with no id at all, so handing it the raw
     * {@see CategoryIdNamePermalink} DTO silently loses the album from every
     * URL built here. Same unwrap {@see \Piwigo\Html\HtmlService::getCatDisplayNameCache()}
     * already does against this same cache.
     *
     * @param array<array-key, mixed> $catNames
     * @return array{id: int, name: string, permalink: ?string}|array{}
     */
    private static function categoryUrlParam(array $catNames, int|string $categoryId): array
    {
        $cat = $catNames[$categoryId] ?? null;

        return $cat instanceof CategoryIdNamePermalink ? $cat->toArray() : [];
    }
}
