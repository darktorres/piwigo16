<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\BatchManager\FilterPanelRenderer;
use Piwigo\Admin\Projection\BatchManagerGlobalPageContext;
use Piwigo\Admin\Request\BatchManagerGlobalRequest;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Caddie\CaddieService;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\PaginationService;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Admin\ElementSetGlobalAction;
use Piwigo\Event\Location\LocBeginElementSetGlobal;
use Piwigo\Event\Location\LocEndElementSetGlobal;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageDuplicateField;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\ImageTextField;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;

/**
 * Renders the "global" mode tab of the "batch_manager" admin page
 * (dispatched by BatchManagerSubController) -- applies bulk actions (tags,
 * associate/move/dissociate album, author/title/date/level, caddie,
 * delete, derivatives) to the whole current photo selection at once.
 *
 * Access control is enforced by admin.php's dispatch gate before this
 * renderer runs. A single CSRF check at the top of render() covers every
 * mutation branch below.
 *
 * $duplicatesOnFields is only computed by BatchManagerSubController for the
 * 'duplicates' prefilter, and is passed in here as an explicit parameter
 * for this file's own duplicates-mode thumbnail ordering.
 */
final readonly class BatchManagerGlobalPageRenderer
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private SessionService $sessionService,
        private Translator $translator,
        private EventDispatcher $eventDispatcher,
        private ImageStdParams $imageStdParams,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private EntityManagerInterface $entityManager,
        private ActivityService $activityService,
        private TagService $tagService,
        private CategoryService $categoryService,
        private ImageService $imageService,
        private HtmlService $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Paths $paths,
    ) {}

    /**
     * @param array<array-key, int|string|float|bool> $catElementsId a
     *   scalar-filtered image id set -- see
     *   {@see \Piwigo\Controller\Admin\BatchManagerSubController::computeCurrentSet()}
     * @param ?list<ImageDuplicateField> $duplicatesOnFields
     */
    public function render(array $catElementsId, int $pageStart, ?array $duplicatesOnFields = null): void
    {
        $template = $this->currentTemplate->get();

        // Runs before Request\BatchManagerGlobalRequest::fromGlobals() below
        // (matching the original's own ordering exactly, CSRF check before
        // any field validation), so it can't read the DTO's own post bag
        // yet -- a minimal, single-fact existence check, same shape as
        // Ws\Server::isPost()'s own already-reviewed raw $_POST read.
        if (count($_POST) > 0) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $this->eventDispatcher->dispatchNotify(new LocBeginElementSetGlobal());

        $batchManagerGlobalRequest = BatchManagerGlobalRequest::fromGlobals($this->inputValidator);

        $collection = [];
        if ($batchManagerGlobalRequest->nbPhotosDeletedPresent) {
            // let's fake a collection (we don't know the image_ids so we use 0 as a
            // placeholder, we only care about the number of items here): this
            // branch is reached only after an ajax-driven "delete" action (see
            // batchManagerGlobal.js), whose photos are already gone, so no
            // downstream code in the 'delete' action below needs real ids
            $collection = array_fill(0, $batchManagerGlobalRequest->nbPhotosDeleted, 0);
        } elseif ($batchManagerGlobalRequest->isSetSelected) {
            // Here we don't use check_input_parameter because preg_match has a limit in
            // the repetitive pattern. Found a limit to 3276 but may depend on memory.
            //
            // check_input_parameter('whole_set', $_POST, false, '/^\d+(,\d+)*$/');
            //
            // Instead, let's break the input parameter into pieces and check pieces one by one.
            foreach (explode(',', $batchManagerGlobalRequest->wholeSet) as $id) {
                if (! (bool) preg_match('/^\d+$/', $id)) {
                    $this->htmlRenderer
                        ->fatalError('Invalid request parameter "whole_set"');
                }
                $collection[] = (int) $id;
            }
        } elseif ($batchManagerGlobalRequest->selectionPresent) {
            foreach ($batchManagerGlobalRequest->selection as $selected_id) {
                if (is_numeric($selected_id)) {
                    $collection[] = (int) $selected_id;
                }
            }
        }

        // Locally-typed snapshot of $_SESSION['bulk_manager_filter']. It is always
        // written as an array by BatchManagerSubController (which runs before
        // dispatching to this renderer); this guards against corrupted/foreign
        // session state and lets PHPStan track a real array shape for the reads
        // below (this file never writes to $_SESSION['bulk_manager_filter']).
        /** @var array<string, mixed> $bulk_manager_filter */
        $bulk_manager_filter = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

        // prefilter is a shortcut to test if the current filter contains a
        // given prefilter. The idea is to make conditions simpler to write in the
        // code.
        $prefilter = 'none';
        if (isset($bulk_manager_filter['prefilter'])) {
            $prefilter = $bulk_manager_filter['prefilter'];
        }

        $get_page = $batchManagerGlobalRequest->page;
        $redirect_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $get_page;

        // $prefilter never changes after the assignment above; narrowed once
        // here and reused for every `== 'xxx'` comparison below ($bulk_manager_filter
        // is only known as array<string, mixed>, so a bare offset read stays
        // `mixed` even though it's provably a string at this point).
        $prefilter_value = is_string($prefilter) ? $prefilter : 'none';

        // A local working copy of post -- the author/title branches below
        // used to null $_POST['author']/$_POST['title'] in place when their
        // remove_author/remove_title companion checkbox was set, so every
        // later read in this same method call saw the override.
        $post = $batchManagerGlobalRequest->post;

        if ($batchManagerGlobalRequest->isSubmitted) {
            // if the user tries to apply an action, it means that there is at least 1
            // photo in the selection
            if (count($collection) === 0) {
                $this->pageState->addError($this->lang->t('Select at least one photo'));
            }

            $action = $batchManagerGlobalRequest->selectAction;
            $redirect = false;

            $tagService = $this->tagService;
            $imageService = new ImageService($this->entityManager->getRepository(ImageEntity::class), $this->activityService, $this->sessionService, $this->eventDispatcher, $this->currentConfig, $this->paths, $this->categoryService);

            if ($action === 'remove_from_caddie') {
                $current_user_id = $this->currentUser->get()
                    ->id->value;
                $this->entityManager->getRepository(CaddieEntity::class)
                    ->removeElementsForUser($current_user_id, $collection);

                // remove from caddie action available only in caddie so reload content
                $redirect = true;
            } elseif ($action === 'add_tags') {
                $post_add_tags = $post['add_tags'] ?? null;
                if (! is_array($post_add_tags) || count($post_add_tags) === 0) {
                    $this->pageState->addError($this->lang->t('Select at least one tag'));
                } else {
                    $add_tags = [];
                    foreach ($post_add_tags as $raw_tag) {
                        if (is_string($raw_tag)) {
                            $add_tags[] = $raw_tag;
                        }
                    }

                    $tag_ids = $tagService->getTagIds($add_tags);
                    $tagService->addTags($tag_ids, $collection);

                    if ($prefilter_value === 'no_tag') {
                        $redirect = true;
                    }
                }
            } elseif ($action === 'del_tags') {
                $post_del_tags = $post['del_tags'] ?? null;
                $del_tags = [];
                if (is_array($post_del_tags)) {
                    foreach ($post_del_tags as $raw_del_tag) {
                        if (is_scalar($raw_del_tag)) {
                            $del_tags[] = $raw_del_tag;
                        }
                    }
                }
                if (count($del_tags) > 0) {
                    $taglist_before = $tagService->getImageTagIds($collection);

                    $tagService->removeTagsFromImages(
                        $collection,
                        array_values(array_filter(array_map(TagId::tryFrom(...), $del_tags), static fn (?TagId $id): bool => $id instanceof TagId))
                    );

                    $taglist_after = $tagService->getImageTagIds($collection);
                    $images_to_update = $tagService->compareImageTagLists($taglist_before, $taglist_after);
                    $imageService->updateImagesLastmodified($images_to_update);

                    if (isset($bulk_manager_filter['tags']) && is_array($bulk_manager_filter['tags']) &&
                      (bool) count(array_intersect(array_filter($bulk_manager_filter['tags'], is_scalar(...)), $del_tags))) {
                        $redirect = true;
                    }
                } else {
                    $this->pageState->addError($this->lang->t('Select at least one tag'));
                }
            }

            if ($action === 'associate') {
                $post_associate = $post['associate'] ?? null;
                if (! is_array($post_associate) || count($post_associate) === 0) {
                    $this->pageState->addError($this->lang->t('Select at least one album'));
                } else {
                    $associate_categories = [];
                    foreach ($post_associate as $raw_category_id) {
                        if (is_numeric($raw_category_id)) {
                            $associate_categories[] = (int) $raw_category_id;
                        }
                    }

                    $imageService->associateImagesToCategories(
                        $collection,
                        $associate_categories
                    );

                    $_SESSION['page_infos'] = [
                        $this->lang->t('Information data registered in database'),
                    ];

                    // let's refresh the page because we the current set might be modified
                    if ($prefilter_value === 'no_album') {
                        $redirect = true;
                    } elseif ($prefilter_value === 'no_virtual_album') {
                        // "no_virtual_album" refresh only makes sense when we know
                        // whether the target album has a physical directory; with
                        // the multi-album selector, use the first selected album
                        // (matches the single-select behavior this branch had
                        // before "associate" became a multi-value field).
                        $first_associate_category = reset($associate_categories);
                        if ($first_associate_category !== false) {
                            $category_info = $this->categoryService->getCategoryInfo($first_associate_category);
                            if (($category_info->dir ?? '') === '') {
                                $redirect = true;
                            }
                        }
                    }
                }
            } elseif ($action === 'move') {
                $move_category = isset($post['move']) && is_numeric($post['move']) ? (int) $post['move'] : null;
                $imageService->moveImagesToCategories($collection, $move_category !== null ? [$move_category] : []);

                $_SESSION['page_infos'] = [
                    $this->lang->t('Information data registered in database'),
                ];

                // let's refresh the page because we the current set might be modified
                if ($prefilter_value === 'no_album') {
                    $redirect = true;
                } elseif ($prefilter_value === 'no_virtual_album') {
                    if ($move_category !== null) {
                        $category_info = $this->categoryService->getCategoryInfo($move_category);
                        if (($category_info->dir ?? '') === '') {
                            $redirect = true;
                        }
                    }
                } elseif (isset($bulk_manager_filter['category'])
                    and $move_category !== (is_numeric($bulk_manager_filter['category']) ? (int) $bulk_manager_filter['category'] : null)) {
                    $redirect = true;
                }
            } elseif ($action === 'dissociate' && isset($post['dissociate']) && is_numeric($post['dissociate'])) {
                $dissociate_category = (int) $post['dissociate'];
                $nb_dissociated = $imageService->dissociateImagesFromCategory($collection, $dissociate_category);

                if ($nb_dissociated > 0) {
                    $_SESSION['page_infos'] = [
                        $this->lang->t('Information data registered in database'),
                    ];

                    // let's refresh the page because the current set might be modified
                    $redirect = true;
                }
            }

            // author
            elseif ($action === 'author') {
                if (isset($post['remove_author'])) {
                    $post['author'] = null;
                }

                $imageService->updateTextFieldForImages($collection, ImageTextField::Author, is_string($post['author'] ?? null) ? $post['author'] : null);
                $this->entityManager->clear();

                $this->activityService
                    ->record('photo', $collection, 'edit', [
                        'action' => 'author',
                    ]);
            }

            // title
            elseif ($action === 'title') {
                if (isset($post['remove_title'])) {
                    $post['title'] = null;
                }

                $imageService->updateTextFieldForImages($collection, ImageTextField::Name, is_string($post['title'] ?? null) ? $post['title'] : null);
                $this->entityManager->clear();

                $this->activityService
                    ->record('photo', $collection, 'edit', [
                        'action' => 'title',
                    ]);
            }

            // date_creation
            elseif ($action === 'date_creation') {
                if (isset($post['remove_date_creation']) || ($post['date_creation'] ?? '') === '') {
                    $date_creation = null;
                } else {
                    $date_creation = is_string($post['date_creation']) ? $post['date_creation'] : null;
                }

                $imageService->updateTextFieldForImages($collection, ImageTextField::DateCreation, $date_creation);
                $this->entityManager->clear();

                $this->activityService
                    ->record('photo', $collection, 'edit', [
                        'action' => 'date_creation',
                    ]);
            }

            // privacy_level
            elseif ($action === 'level') {
                $imageService->updateLevelForImages($collection, is_numeric($post['level']) ? (int) $post['level'] : 0);
                $this->entityManager->clear();

                $this->activityService
                    ->record('photo', $collection, 'edit', [
                        'action' => 'privacy_level',
                    ]);

                if (isset($bulk_manager_filter['level'])) {
                    if ($post['level'] < $bulk_manager_filter['level']) {
                        $redirect = true;
                    }
                }
            }

            // add_to_caddie
            elseif ($action === 'add_to_caddie') {
                CaddieService::fillCurrentUserCaddie($collection, $this->currentUser, $this->entityManager);
            }

            // delete
            elseif ($action === 'delete') {
                if (isset($post['confirm_deletion']) and $post['confirm_deletion'] === '1') {
                    // now done with ajax calls, with blocks
                    // $deleted_count = delete_elements($collection, true);
                    if (count($collection) > 0) {
                        if (! isset($_SESSION['page_infos']) || ! is_array($_SESSION['page_infos'])) {
                            $_SESSION['page_infos'] = [];
                        }
                        $_SESSION['page_infos'][] = $this->translator->plural(
                            '%d photo was deleted',
                            '%d photos were deleted',
                            count($collection)
                        );

                        $redirect_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $get_page;
                        $redirect = true;
                    } else {
                        $this->pageState->addError($this->lang->t('No photo can be deleted'));
                    }
                } else {
                    $this->pageState->addError($this->lang->t('You need to confirm deletion'));
                }
            }

            // synchronize metadata
            elseif ($action === 'metadata') {
                $this->pageState->addInfo($this->lang->t('Metadata synchronized from file') . ' <span class="badge">' . count($collection) . '</span>');
            } elseif ($action === 'delete_derivatives' && isset($post['del_derivatives_type']) && is_array($post['del_derivatives_type']) && count($post['del_derivatives_type']) > 0) {
                foreach ($imageService->getPathsForFileDeletion($collection) as $info) {
                    $derivative_infos = [
                        'path' => $info->path,
                    ];
                    if ($info->representativeExt !== null && $info->representativeExt !== '') {
                        $derivative_infos['representative_ext'] = $info->representativeExt;
                    }
                    foreach ($post['del_derivatives_type'] as $type) {
                        if (is_string($type)) {
                            new DerivativeCacheService($this->currentConfig, $this->paths)
                                ->deleteElementDerivatives($derivative_infos, $type);
                        }
                    }
                }
            } elseif ($action === 'generate_derivatives') {
                if (($post['regenerateSuccess'] ?? '0') !== '0') {
                    $this->pageState->addInfo($this->lang->t('%s photos have been regenerated', $post['regenerateSuccess'] ?? '0'));
                }
                if (($post['regenerateError'] ?? '0') !== '0') {
                    $this->pageState->addWarning($this->lang->t('%s photos can not be regenerated', $post['regenerateError'] ?? '0'));
                }
            }

            if (! in_array($action, ['remove_from_caddie', 'add_to_caddie', 'delete_derivatives', 'generate_derivatives'], true)) {
                PermissionCacheInvalidator::invalidate();
            }

            $this->eventDispatcher->dispatchNotify(new ElementSetGlobalAction($action, $collection));

            if ($redirect) {
                $this->redirectService->redirect($redirect_url);
            }
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php';

        // $catElementsId is a list of scalar image ids; narrowed once here
        // for every use below (including the FilterPanelRenderer call).
        $cat_elements_id = array_map(intval(...), array_filter($catElementsId, is_numeric(...)));
        $page_start = $pageStart;

        new FilterPanelRenderer()
            ->render($this->lang, $template, $base_url, $collection, $cat_elements_id, $page_start, $this->urlService, $this->eventDispatcher, $this->pageState, $this->tagService, $this->htmlRenderer, $this->currentConfig, $this->entityManager);

        $in_caddie = $prefilter_value === 'caddie';

        $associated_tags = null;
        if (count($cat_elements_id) > 0) {
            // remove tags
            $associated_tags = $this->tagService
                ->getCommonTags($cat_elements_id, -1, $this->htmlRenderer);
        }

        // creation date
        $post_date_creation = $post['date_creation'] ?? '';
        $date_creation = $post_date_creation === '' ? Env::now()->format('Y-m-d') . ' 00:00:00' : (is_string($post_date_creation) ? $post_date_creation : '');

        // image level options
        $level_options = PermissionService::getPrivacyLevelOptions($this->currentConfig, $this->lang);

        // metadata
        $site_reader = new LocalSiteReader('./', $this->currentConfig, new MetadataService($this->lang, new MetadataRepository($this->entityManager), $this->currentLogger, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->sessionService, $this->paths));
        $used_metadata = implode(', ', $site_reader->getMetadataAttributes());

        // derivatives
        $del_deriv_map = [];
        foreach ($this->imageStdParams->getDefinedTypeMap() as $params) {
            $del_deriv_map[$params->type] = $this->lang->t($params->type);
        }
        $gen_deriv_map = $del_deriv_map;
        $del_deriv_map[ImageStdParams::CUSTOM] = $this->lang->t(ImageStdParams::CUSTOM);

        // how many items to display on this page
        if ($batchManagerGlobalRequest->displayRequested) {
            if ($batchManagerGlobalRequest->displayRaw === 'all') {
                $nb_images = count($cat_elements_id);
            } else {
                $nb_images = is_numeric($batchManagerGlobalRequest->displayRaw) ? intval($batchManagerGlobalRequest->displayRaw) : 0;
            }
        } elseif (in_array($this->currentConfig->batchManagerImagesPerPageGlobal, [20, 50, 100], true)) {
            $nb_images = $this->currentConfig->batchManagerImagesPerPageGlobal;
        } else {
            $nb_images = 20;
        }

        $nb_thumbs_page = 0;
        $nav_bar = null;
        $thumb_params = null;
        $thumbnails = [];

        if (count($cat_elements_id) > 0) {
            $nav_bar = new PaginationService($this->currentConfig)
                ->createNavigationBar($base_url . $this->urlService->getQueryStringDiff(['start']), count($cat_elements_id), $page_start, $nb_images);

            $is_category = false;
            $filter_category_id = 0;
            if (isset($bulk_manager_filter['category']) && is_numeric($bulk_manager_filter['category'])
                and ! isset($bulk_manager_filter['category_recursive'])) {
                $is_category = true;
                $filter_category_id = (int) $bulk_manager_filter['category'];
            }

            // If using the 'duplicates' filter,
            // order by the fields that are used to find duplicates.
            if (isset($bulk_manager_filter['prefilter'])
                and $bulk_manager_filter['prefilter'] === 'duplicates'
                and $duplicatesOnFields !== null) {
                $order_by_fields = array_merge(array_map(static fn (ImageDuplicateField $field): string => $field->column(), $duplicatesOnFields), ['id']);
                $order_by = ' ORDER BY ' . join(', ', $order_by_fields);
            } else {
                // order_by is a raw "ORDER BY ..." SQL fragment string --
                // see CurrentConfig::orderBy()'s own docblock.
                $order_by = $this->currentConfig->orderBy;
            }

            if ($is_category) {
                $category_info = $this->categoryService->getCategoryInfo($filter_category_id);

                $order_by = $this->currentConfig->orderByInsideCategory;
                $category_image_order = $category_info instanceof CategoryInfo ? $category_info->imageOrder : null;
                if (is_string($category_image_order) && $category_image_order !== '') {
                    $order_by = ' ORDER BY ' . $category_image_order;
                }
            }

            $thumb_params = $this->imageStdParams->getByType(ImageStdParams::SQUARE);
            // template thumbnail initialization
            foreach ($this->imageService->getBatchManagerThumbnails($cat_elements_id, $is_category ? $filter_category_id : null, $order_by, $nb_images, $page_start) as $row) {
                $nb_thumbs_page++;
                $src_image = new SrcImage($row);

                $ttitle = $this->htmlRenderer
                    ->renderElementName($row);
                $row_file = is_string($row['file']) ? $row['file'] : '';
                if ($ttitle !== StringHelper::getNameFromFile($row_file)) {
                    $ttitle .= ' (' . $row_file . ')';
                }

                $row_filesize = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
                $row_width = is_scalar($row['width']) ? (string) $row['width'] : '';
                $row_height = is_scalar($row['height']) ? (string) $row['height'] : '';
                $ttitle .= '<br>' . $row_width . '&times;' . $row_height . ' pixels, ' . sprintf('%.2f', $row_filesize / 1024.0) . 'MB';

                $row_id = is_scalar($row['id']) ? (string) $row['id'] : '';
                $thumbnails[] = array_merge(
                    $row,
                    [
                        'thumb' => new DerivativeImage($thumb_params, $src_image, $this->currentConfig),
                        'TITLE' => $ttitle,
                        'FILE_SRC' => DerivativeImage::url(ImageStdParams::LARGE, $src_image),
                        'U_EDIT' => $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $row_id,
                    ]
                );
            }
        }

        $template->assignContext(new BatchManagerGlobalPageContext(
            inCaddie: $in_caddie,
            associatedTags: $associated_tags,
            dateCreation: $date_creation,
            levelOptions: $level_options,
            levelOptionsSelected: 0,
            usedMetadata: $used_metadata,
            delDerivativesTypes: $del_deriv_map,
            generateDerivativesTypes: $gen_deriv_map,
            navbar: $nav_bar,
            thumbParams: $thumb_params,
            nbThumbsPage: $nb_thumbs_page,
            nbThumbsSet: count($cat_elements_id),
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['tags', 'categories']),
            thumbnails: $thumbnails,
        ));

        $this->eventDispatcher->dispatchNotify(new LocEndElementSetGlobal());

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'batch_manager_global.latte');
    }
}
