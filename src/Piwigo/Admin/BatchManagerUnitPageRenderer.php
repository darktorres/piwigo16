<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\ParameterType;
use Piwigo\Admin\BatchManager\FilterPanelRenderer;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Event\Location\LocBeginElementSetUnit;
use Piwigo\Event\Location\LocEndElementSetUnit;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;

/**
 * Ported from admin/batch_manager_unit.php (the "unit" mode tab of the
 * "batch_manager" page slug, dispatched by BatchManagerSubController) --
 * per-photo inline edit grid (name/author/level/description/date/tags for
 * each photo individually, in the current filtered selection).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the
 * original file's own (redundant) check_status() call is dropped here --
 * same precedent as every prior P23 batch 6 sub-batch. The one real
 * mutation path (isset($_POST['submit'])) already has its own
 * check_pwg_token() call -- no CSRF gap here.
 *
 * Preserves 1 pre-existing quirk unchanged (a mechanical port doesn't fold
 * in unrelated fixes, same discipline as every prior sub-batch). $base_url
 * used to be built from PHPWG_ROOT_PATH (a filesystem path constant), not
 * UrlServiceInterface::getRootUrl() like every sibling renderer -- fixed
 * as part of Legacy Coupling Retirement gap-closure (entry-shell
 * define()/include round), since PHPWG_ROOT_PATH no longer exists at all;
 * this specific site's own pre-existing bug (U_ELEMENTS_PAGE/F_ACTION
 * rendering a filesystem path instead of a URL) is a real, if minor, side
 * effect of that constant's removal being a strict improvement, not scope
 * creep -- it had no other fix available once the constant was gone.
 *
 * Real bug fixed during gap-closure Stage 1b (the original file documented
 * it as a known, not-fixed-here issue, carried forward verbatim until now):
 * `$storage_category_id` used to be computed once, before the per-image
 * `foreach ($images as $row)` loop, from whatever `$row` the earlier "unit
 * mode form submission" loop above happened to leave behind (or undefined,
 * if that block didn't run) -- never the current image's own row, so the
 * STORAGE_CATEGORY highlighting below never triggered for the correct
 * album. It's now computed fresh inside the per-image loop, from that
 * image's own `storage_category_id` column, and compared as a real `int`
 * against `$item_category_id` (also `int|string` depending on the driver)
 * instead of the original's own `is_string()`-only check, which would
 * have failed the `===` comparison outright under Doctrine DBAL's native
 * int return for this column even with the correct row.
 */
final class BatchManagerUnitPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\ProcessCache $processCache,
        private readonly LoadedPlugins $loadedPlugins,
        private readonly SessionService $sessionService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Core\PageState $pageState,
    ) {}

    private static function tagService(): TagService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::tagService();
    }

    private static function permissionService(): PermissionService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::permissionService();
    }

    private static function categoryService(): CategoryService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();
    }

    private static function imageService(): ImageService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::imageService();
    }

    private static function userService(): \Piwigo\Users\UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    /**
     * @param array<array-key, int|string|float|bool> $catElementsId a
     *   scalar-filtered image id set -- see
     *   {@see \Piwigo\Controller\Admin\BatchManagerSubController::computeCurrentSet()}
     */
    public function render(array $catElementsId, int $pageStart): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();
        $conn = DbConnection::build();

        $this->eventDispatcher->dispatchNotify(new LocBeginElementSetUnit());

        // +-------------------------------------------------------------------+
        // |                        unit mode form submission                      |
        // +-------------------------------------------------------------------+

        $batchManagerUnitRequest = Request\BatchManagerUnitRequest::fromGlobals();

        if ($batchManagerUnitRequest->isSubmitted) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($htmlRenderer, $this->redirectService);
            $collection = explode(',', $batchManagerUnitRequest->elementIds);

            $datas = [];

            $tagService = self::tagService();

            foreach (self::imageService()->getIdsAndDatesForBatchUnitSave($collection) as $row) {
                // Tables::images().id is a NOT NULL auto_increment primary key; this
                // guard only defends against the generic mixed element type a
                // fetched row carries for every column.
                if ($row['id'] === null || ! is_scalar($row['id'])) {
                    continue;
                }
                $row_id_str = (string) $row['id'];
                $image_id = (int) $row['id'];

                $data = [];

                $data['id'] = $row['id'];
                $data['name'] = $batchManagerUnitRequest->post['name-' . $row_id_str] ?? null;
                $data['author'] = $batchManagerUnitRequest->post['author-' . $row_id_str] ?? null;
                $data['level'] = $batchManagerUnitRequest->post['level-' . $row_id_str] ?? null;

                if (\Piwigo\Config\CurrentConfig::allowHtmlDescriptions()) {
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

            self::imageService()->massUpdateFields(
                [
                    'primary' => ['id'],
                    'update' => ['name', 'author', 'level', 'comment', 'date_creation'],
                ],
                $datas
            );
            \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();

            $this->pageState->addInfo(Lang::t('Photo informations updated'));
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
                    $htmlRenderer->fatalError('[Hacking attempt] the input parameter "whole_set" is not valid');
                }
            }
        } elseif ($batchManagerUnitRequest->selectionPresent) {
            $collection = $batchManagerUnitRequest->selection;
        }

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->set_filenames(
            [
                'batch_manager_unit' => 'batch_manager_unit.tpl',
            ]
        );

        $base_url = $this->urlService->getRootUrl() . 'admin.php';

        $template->assign(
            [

                'U_ELEMENTS_PAGE' => $base_url . $this->urlService->getQueryStringDiff(['display', 'start']),
                'level_options' => \Piwigo\Permission\PermissionService::getPrivacyLevelOptions(),
                'ADMIN_PAGE_TITLE' => Lang::t('Batch Manager'),
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        // $catElementsId is a list of scalar image ids; narrowed once here
        // for every use below (including the FilterPanelRenderer call).
        $cat_elements_id = array_filter($catElementsId, is_scalar(...));
        $page_start = $pageStart;

        new FilterPanelRenderer()
            ->render($template, $base_url, $collection, $cat_elements_id, $page_start, $this->urlService, $this->eventDispatcher, $this->pageState);
        // +-------------------------------------------------------------------+
        // |                        global mode thumbnails                         |
        // +-------------------------------------------------------------------+

        $template->assign('ACTIVE_PLUGINS', array_keys($this->loadedPlugins->get()));

        // how many items to display on this page
        if ($batchManagerUnitRequest->displayRequested) {
            // \Piwigo\Config\ConfigDb::confUpdateParam('batch_manager_images_per_page_unit' , intval($_GET['display']));
            // $nb_images = \Piwigo\Config\CurrentConfig::batchManagerImagesPerPageUnit();
            $nb_images = $batchManagerUnitRequest->display;
        } elseif (in_array(\Piwigo\Config\CurrentConfig::batchManagerImagesPerPageUnit(), [5, 10, 50], true)) {
            $nb_images = \Piwigo\Config\CurrentConfig::batchManagerImagesPerPageUnit();
        } else {
            $nb_images = 5;
        }
        $template->assign('per_page', $nb_images);

        if (count($cat_elements_id) > 0) {
            $page_nb_images = $nb_images;

            $nav_bar = new \Piwigo\Core\PaginationService()
                ->createNavigationBar($base_url . $this->urlService->getQueryStringDiff(['start']), count($cat_elements_id), $page_start, $page_nb_images);
            $template->assign([
                'navbar' => $nav_bar,
            ]);

            $element_ids = [];

            // Locally-typed snapshot of $_SESSION['bulk_manager_filter']. It is
            // always written as an array by BatchManagerSubController (which runs
            // before dispatching to this renderer); this guards against
            // corrupted/foreign session state and lets PHPStan track a real array
            // shape for the reads below (this file never writes to
            // $_SESSION['bulk_manager_filter']).
            /** @var array<string, mixed> $bulk_manager_filter */
            $bulk_manager_filter = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

            $is_category = false;
            $filter_category_id = 0;
            if (isset($bulk_manager_filter['category']) && is_numeric($bulk_manager_filter['category'])
                and ! isset($bulk_manager_filter['category_recursive'])) {
                $is_category = true;
                $filter_category_id = (int) $bulk_manager_filter['category'];
            }

            if (isset($bulk_manager_filter['prefilter'])
                and $bulk_manager_filter['prefilter'] === 'duplicates') {
                $order_by = ' ORDER BY file, id';
            } else {
                // order_by is a raw "ORDER BY ..." SQL fragment string --
                // see CurrentConfig::orderBy()'s own docblock.
                $order_by = \Piwigo\Config\CurrentConfig::orderBy();
            }

            if ($is_category) {
                $category_info = self::categoryService()->getCategoryInfo($filter_category_id);

                $order_by = \Piwigo\Config\CurrentConfig::orderByInsideCategory();
                $category_image_order = $category_info !== null ? ($category_info['image_order'] ?? null) : null;
                if (is_string($category_image_order) && $category_image_order !== '') {
                    $order_by = ' ORDER BY ' . $category_image_order;
                }
            }

            $images = self::imageService()->getBatchManagerUnitRows($cat_elements_id, $is_category ? $filter_category_id : null, $order_by, $page_nb_images, $page_start);
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
                $added_by_username_of = self::userService()->getUsernamesByIds($added_by_ids);
            }

            $tagService = self::tagService();
            $imageService = new ImageService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class), \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(), $this->sessionService, $this->eventDispatcher);

            foreach ($images as $row) {
                // Tables::images().id is a NOT NULL auto_increment primary key; this
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

                $imageTagTable = Tables::imageTag();
                $tagsTable = Tables::tags();
                $query = <<<SQL
                    SELECT
                        id,
                        name
                    FROM {$imageTagTable} AS it
                        JOIN {$tagsTable} AS t ON t.id = it.tag_id
                    WHERE image_id = :imageId
                    SQL;

                $tag_selection = $tagService->getTagList($query, $htmlRenderer, params: [
                    'imageId' => (int) $row_id_str,
                ], types: [
                    'imageId' => ParameterType::INTEGER,
                ]);

                $row_file = is_string($row['file']) ? $row['file'] : '';
                $legend = $htmlRenderer->renderElementName($row);
                if ($legend !== \Piwigo\Core\StringHelper::getNameFromFile($row_file)) {
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

                foreach ($imageService->getCategoryLinksForImage((int) $row_id) as $item) {
                    // Tables::imageCategory()/Tables::categories().category_id/uppercats are
                    // NOT NULL; this guard only defends against the generic mixed
                    // element type a fetched row carries for every column.
                    if ($item['category_id'] === null || $item['uppercats'] === null
                        || (! is_int($item['category_id']) && ! is_string($item['category_id']))
                        || ! is_string($item['uppercats'])) {
                        continue;
                    }
                    $item_category_id = $item['category_id'];
                    // Real category ids are always numeric, whether the driver
                    // handed back this int|string column as a native int or a
                    // numeric string -- normalized once here so the comparison
                    // against $storage_category_id (a real int) below is a
                    // reliable ===, not a driver-dependent type mismatch.
                    $item_category_id_int = (int) $item_category_id;

                    $name =
                      $htmlRenderer->getCatDisplayNameCache(
                          $item['uppercats'],
                          $this->urlService->getRootUrl() . 'admin.php?page=album-'
                      );

                    if ($item_category_id_int === $storage_category_id) {
                        $template->assign('STORAGE_CATEGORY', $name);
                    }

                    $related_categories[$item_category_id] = [
                        'name' => $name,
                        'unlinkable' => $item_category_id_int !== $storage_category_id,
                    ];
                    $related_category_ids[] = $item_category_id;
                }

                // jump to link
                $image_file = $row['file'];

                $currentUser = \Piwigo\Users\CurrentUser::get();
                $authorizeds = array_diff(
                    array_map(
                        strval(...),
                        $imageService->getCategoryIdsForImage((int) $row_id)
                    ),
                    explode(
                        ',',
                        new \Piwigo\Permission\ForbiddenCategoriesCache(self::permissionService(), \Piwigo\Cache\CachePools::permissions())
                            ->getForUser($currentUser->id->value, $currentUser->status->value)
                    )
                );

                // ProcessCache::get('cat_names') is populated as
                // array<int|string, array<string, mixed>> by
                // get_cat_display_name_cache() (already called above, for
                // every $item in the while loop, before this point) --
                // matches the established narrowing pattern in
                // Piwigo\Admin\PictureModifyPageRenderer.
                $cat_names_raw = $this->processCache->get('cat_names');
                $cat_names = is_array($cat_names_raw) ? $cat_names_raw : [];

                $row_cat_id_raw = $row['cat_id'] ?? null;
                $row_cat_id = (is_int($row_cat_id_raw) || is_string($row_cat_id_raw)) ? (string) $row_cat_id_raw : null;

                if ($row_cat_id !== null
                and in_array($row_cat_id, $authorizeds, true)) {
                    $url_img = $this->urlService->makePictureUrl(
                        [
                            'image_id' => $row_id,
                            'image_file' => $image_file,
                            'category' => $cat_names[$row_cat_id],
                        ]
                    );
                } else {
                    foreach ($authorizeds as $category) {
                        $url_img = $this->urlService->makePictureUrl(
                            [
                                'image_id' => $row_id, // utile ?
                                'image_file' => $image_file,
                                'category' => $cat_names[$category],
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

                $template->append(
                    'elements',
                    array_merge(
                        $row,
                        [
                            'ID' => $row_id,
                            'TN_SRC' => DerivativeImage::url(ImageStdParams::MEDIUM, $src_image),
                            'FILE_SRC' => DerivativeImage::url(ImageStdParams::LARGE, $src_image),
                            'LEGEND' => $legend,
                            'U_EDIT' => $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $row_id_str,
                            'NAME' => htmlspecialchars($row_name),
                            'AUTHOR' => htmlspecialchars($row_author),
                            'LEVEL' => ($row['level'] ?? '') !== '' && ($row['level'] ?? null) !== '0' ? ($row['level'] ?? '0') : '0',
                            'DESCRIPTION' => htmlspecialchars($row_comment),
                            'DATE_CREATION' => $row['date_creation'],
                            'TAGS' => $tag_selection,
                            'is_svg' => (strtoupper(end($extTab)) === 'SVG'),
                            'TITLE' => $htmlRenderer->renderElementName($row),
                            'DIMENSIONS' => $row_width . 'x' . $row_height . ' px',
                            'FORMAT' => ($row_width >= $row_height) ? 1 : 0, // 0:horizontal, 1:vertical
                            'FILESIZE' => Lang::t('%.2f MB', $row_filesize / 1024.0),
                            'REGISTRATION_DATE' => \Piwigo\Core\DateHelper::formatDate($row_date_available),
                            'EXT' => Lang::t('%s file type', end($extTab)),
                            'POST_DATE' => Lang::t('Added on %s', \Piwigo\Core\DateHelper::formatDate($row_date_available, ['day', 'month', 'year'])),
                            'AGE' => Lang::t(ucfirst(\Piwigo\Core\DateHelper::timeSince($row_date_available, 'year'))),
                            'ADDED_BY' => Lang::t('Added by %s', $row_added_by !== null ? ($added_by_username_of[$row_added_by] ?? Lang::t('N/A')) : Lang::t('N/A')),
                            'STATS' => Lang::t('Visited %d times', $row['hit']),
                            'FILE' => Lang::t('%s', $row['file']),
                            'related_categories' => $related_categories,
                            'related_category_ids' => json_encode($related_category_ids),
                            'U_JUMPTO' => (isset($url_img) and $currentUser->level >= $media['image']['level']) ? $url_img : null,
                            'tag_selection' => $tag_selection,
                            'U_DOWNLOAD' => 'action.php?id=' . $row_id_str . '&amp;part=e&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken() . '&amp;download',
                            'U_HISTORY' => $this->urlService->getRootUrl() . 'admin.php?page=history&amp;filter_image_id=' . $row_id_str,
                            'U_ACTIVITY' => $this->urlService->getRootUrl() . 'admin.php?page=user_activity&photo=' . $row_id_str,
                            'U_DELETE' => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                            'U_SYNC' => $admin_url_start . '&amp;sync_metadata=1',
                            'PATH' => $row['path'],
                            'level_options_selected' => [$selected_level],

                        ]
                    )
                );
            }

            $template->assign([
                'ELEMENT_IDS' => implode(',', $element_ids),
            ]);
        }

        $template->assign([
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['tags', 'categories']),
        ]);

        $this->eventDispatcher->dispatchNotify(new LocEndElementSetUnit());

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_unit');
    }
}
