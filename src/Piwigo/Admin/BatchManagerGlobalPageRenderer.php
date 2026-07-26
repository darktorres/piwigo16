<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\BatchManager\FilterPanelRenderer;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;

/**
 * Ported from admin/batch_manager_global.php (the "global" mode tab of the
 * "batch_manager" page slug, dispatched by BatchManagerSubController) --
 * bulk actions (tags, associate/move/dissociate album, author/title/date/
 * level, caddie, delete, derivatives) applied to the whole current photo
 * selection at once.
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the
 * original file's own (redundant) check_status() call is dropped here --
 * same precedent as every prior P23 batch 6 sub-batch. Every mutation
 * branch below is already covered by a single blanket `if (! empty($_POST))
 * { check_pwg_token(); }` guard (confirmed by reading every branch) -- no
 * CSRF gap here, unlike this same sub-batch's admin/batch_manager.php shell
 * (see BatchManagerSubController's own docblock for that real fix).
 *
 * $duplicatesOnFields is only computed by BatchManagerSubController for the
 * 'duplicates' prefilter, and is passed in here as an explicit parameter
 * (for this file's own duplicates-mode thumbnail ordering) -- Legacy
 * Coupling Retirement Track A batch A5.2i retargeted this off the
 * `$page['duplicates_on_fields']` global it was ported onto in P23 batch
 * 6g, which itself replaced the original file's PHP-include-scope variable
 * sharing (the legacy admin/batch_manager.php included this file directly,
 * so a bare local variable it set was still in scope here).
 */
final class BatchManagerGlobalPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    private static function tagService(): TagService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::tagService();
    }

    private static function categoryService(): CategoryService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();
    }

    /**
     * @param array<mixed> $catElementsId
     * @param ?list<string> $duplicatesOnFields
     */
    public function render(array $catElementsId, int $pageStart, ?array $duplicatesOnFields = null): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();
        $conn = DbConnection::build();

        if (count($_POST) > 0) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_element_set_global');

        new \Piwigo\Validation\InputValidator()
            ->validate('del_tags', $_POST, true, ValidationPattern::ID);
        new \Piwigo\Validation\InputValidator()
            ->validate('associate', $_POST, true, ValidationPattern::ID);
        new \Piwigo\Validation\InputValidator()
            ->validate('move', $_POST, false, ValidationPattern::ID);
        new \Piwigo\Validation\InputValidator()
            ->validate('dissociate', $_POST, false, ValidationPattern::ID);

        // +-------------------------------------------------------------------+
        // |                            current selection                          |
        // +-------------------------------------------------------------------+

        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            new \Piwigo\Validation\InputValidator()
                ->validate('nb_photos_deleted', $_POST, false, '/^\d+$/');

            // let's fake a collection (we don't know the image_ids so we use 0 as a
            // placeholder, we only care about the number of items here): this
            // branch is reached only after an ajax-driven "delete" action (see
            // batchManagerGlobal.js), whose photos are already gone, so no
            // downstream code in the 'delete' action below needs real ids
            $nb_photos_deleted = is_numeric($_POST['nb_photos_deleted']) ? (int) $_POST['nb_photos_deleted'] : 0;
            $collection = array_fill(0, $nb_photos_deleted, 0);
        } elseif (isset($_POST['setSelected'])) {
            // Here we don't use check_input_parameter because preg_match has a limit in
            // the repetitive pattern. Found a limit to 3276 but may depend on memory.
            //
            // check_input_parameter('whole_set', $_POST, false, '/^\d+(,\d+)*$/');
            //
            // Instead, let's break the input parameter into pieces and check pieces one by one.
            $whole_set = isset($_POST['whole_set']) && is_string($_POST['whole_set']) ? $_POST['whole_set'] : '';

            foreach (explode(',', $whole_set) as $id) {
                if (! (bool) preg_match('/^\d+$/', $id)) {
                    \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                        ->fatalError('[Hacking attempt] the input parameter "whole_set" is not valid');
                }
                $collection[] = (int) $id;
            }
        } elseif (isset($_POST['selection']) && is_array($_POST['selection'])) {
            foreach ($_POST['selection'] as $selected_id) {
                if (is_numeric($selected_id)) {
                    $collection[] = (int) $selected_id;
                }
            }
        }

        // +-------------------------------------------------------------------+
        // |                       global mode form submission                     |
        // +-------------------------------------------------------------------+

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

        $get_page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
        $redirect_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $get_page;

        // $prefilter never changes after the assignment above; narrowed once
        // here and reused for every `== 'xxx'` comparison below ($bulk_manager_filter
        // is only known as array<string, mixed>, so a bare offset read stays
        // `mixed` even though it's provably a string at this point).
        $prefilter_value = is_string($prefilter) ? $prefilter : 'none';

        if (isset($_POST['submit'])) {
            // if the user tries to apply an action, it means that there is at least 1
            // photo in the selection
            if (count($collection) === 0) {
                \Piwigo\Core\PageState::current()->addError(Lang::t('Select at least one photo'));
            }

            // $_POST values are always strings for a plain <select>/hidden
            // input like selectAction; narrowed once here and reused for
            // every `== 'xxx'` comparison below.
            $action = is_string($_POST['selectAction'] ?? null) ? $_POST['selectAction'] : '';
            $redirect = false;

            $tagService = self::tagService();
            $imageService = new ImageService(new ImageRepository($conn), \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService());

            if ($action === 'remove_from_caddie') {
                $current_user_id = \Piwigo\Users\CurrentUser::get()->id;
                $query = '
DELETE
  FROM ' . Tables::caddie() . '
  WHERE element_id IN (' . implode(',', $collection) . ')
    AND user_id = ' . $current_user_id . '
;';
                $conn->executeStatement($query);

                // remove from caddie action available only in caddie so reload content
                $redirect = true;
            } elseif ($action === 'add_tags') {
                $post_add_tags = $_POST['add_tags'] ?? null;
                if (! is_array($post_add_tags) || count($post_add_tags) === 0) {
                    \Piwigo\Core\PageState::current()->addError(Lang::t('Select at least one tag'));
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
                $post_del_tags = $_POST['del_tags'] ?? null;
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

                    $query = '
DELETE
  FROM ' . Tables::imageTag() . '
  WHERE image_id IN (' . implode(',', $collection) . ')
    AND tag_id IN (' . implode(',', $del_tags) . ')
;';
                    $conn->executeStatement($query);

                    $taglist_after = $tagService->getImageTagIds($collection);
                    $images_to_update = $tagService->compareImageTagLists($taglist_before, $taglist_after);
                    $imageService->updateImagesLastmodified($images_to_update);

                    if (isset($bulk_manager_filter['tags']) && is_array($bulk_manager_filter['tags']) &&
                      (bool) count(array_intersect(array_filter($bulk_manager_filter['tags'], is_scalar(...)), $del_tags))) {
                        $redirect = true;
                    }
                } else {
                    \Piwigo\Core\PageState::current()->addError(Lang::t('Select at least one tag'));
                }
            }

            if ($action === 'associate') {
                $post_associate = $_POST['associate'] ?? null;
                if (! is_array($post_associate) || count($post_associate) === 0) {
                    \Piwigo\Core\PageState::current()->addError(Lang::t('Select at least one album'));
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
                        Lang::t('Information data registered in database'),
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
                            $category_info = self::categoryService()->getCategoryInfo($first_associate_category);
                            if (($category_info['dir'] ?? '') === '') {
                                $redirect = true;
                            }
                        }
                    }
                }
            } elseif ($action === 'move') {
                $move_category = isset($_POST['move']) && is_numeric($_POST['move']) ? (int) $_POST['move'] : null;
                $imageService->moveImagesToCategories($collection, $move_category !== null ? [$move_category] : []);

                $_SESSION['page_infos'] = [
                    Lang::t('Information data registered in database'),
                ];

                // let's refresh the page because we the current set might be modified
                if ($prefilter_value === 'no_album') {
                    $redirect = true;
                } elseif ($prefilter_value === 'no_virtual_album') {
                    if ($move_category !== null) {
                        $category_info = self::categoryService()->getCategoryInfo($move_category);
                        if (($category_info['dir'] ?? '') === '') {
                            $redirect = true;
                        }
                    }
                } elseif (isset($bulk_manager_filter['category'])
                    and $move_category !== (is_numeric($bulk_manager_filter['category']) ? (int) $bulk_manager_filter['category'] : null)) {
                    $redirect = true;
                }
            } elseif ($action === 'dissociate') {
                $dissociate_category = isset($_POST['dissociate']) && is_numeric($_POST['dissociate']) ? (int) $_POST['dissociate'] : 0;
                $nb_dissociated = $imageService->dissociateImagesFromCategory($collection, $dissociate_category);

                if ($nb_dissociated > 0) {
                    $_SESSION['page_infos'] = [
                        Lang::t('Information data registered in database'),
                    ];

                    // let's refresh the page because the current set might be modified
                    $redirect = true;
                }
            }

            // author
            elseif ($action === 'author') {
                if (isset($_POST['remove_author'])) {
                    $_POST['author'] = null;
                }

                $datas = [];
                foreach ($collection as $image_id) {
                    $datas[] = [
                        'id' => $image_id,
                        'author' => $_POST['author'] ?? null,
                    ];
                }

                new BatchWriter($conn)
                    ->massUpdate(
                        Tables::images(),
                        [
                            'primary' => ['id'],
                            'update' => ['author'],
                        ],
                        $datas
                    );

                \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService()
                    ->record('photo', $collection, 'edit', [
                        'action' => 'author',
                    ]);
            }

            // title
            elseif ($action === 'title') {
                if (isset($_POST['remove_title'])) {
                    $_POST['title'] = null;
                }

                $datas = [];
                foreach ($collection as $image_id) {
                    $datas[] = [
                        'id' => $image_id,
                        'name' => $_POST['title'] ?? null,
                    ];
                }

                new BatchWriter($conn)
                    ->massUpdate(
                        Tables::images(),
                        [
                            'primary' => ['id'],
                            'update' => ['name'],
                        ],
                        $datas
                    );

                \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService()
                    ->record('photo', $collection, 'edit', [
                        'action' => 'title',
                    ]);
            }

            // date_creation
            elseif ($action === 'date_creation') {
                if (isset($_POST['remove_date_creation']) || ($_POST['date_creation'] ?? '') === '') {
                    $date_creation = null;
                } else {
                    $date_creation = $_POST['date_creation'] ?? null;
                }

                $datas = [];
                foreach ($collection as $image_id) {
                    $datas[] = [
                        'id' => $image_id,
                        'date_creation' => $date_creation,
                    ];
                }

                new BatchWriter($conn)
                    ->massUpdate(
                        Tables::images(),
                        [
                            'primary' => ['id'],
                            'update' => ['date_creation'],
                        ],
                        $datas
                    );

                \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService()
                    ->record('photo', $collection, 'edit', [
                        'action' => 'date_creation',
                    ]);
            }

            // privacy_level
            elseif ($action === 'level') {
                $datas = [];
                foreach ($collection as $image_id) {
                    $datas[] = [
                        'id' => $image_id,
                        'level' => $_POST['level'],
                    ];
                }

                new BatchWriter($conn)
                    ->massUpdate(
                        Tables::images(),
                        [
                            'primary' => ['id'],
                            'update' => ['level'],
                        ],
                        $datas
                    );

                \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService()
                    ->record('photo', $collection, 'edit', [
                        'action' => 'privacy_level',
                    ]);

                if (isset($bulk_manager_filter['level'])) {
                    if ($_POST['level'] < $bulk_manager_filter['level']) {
                        $redirect = true;
                    }
                }
            }

            // add_to_caddie
            elseif ($action === 'add_to_caddie') {
                \Piwigo\Caddie\CaddieService::fillCurrentUserCaddie($collection);
            }

            // delete
            elseif ($action === 'delete') {
                if (isset($_POST['confirm_deletion']) and $_POST['confirm_deletion'] === '1') {
                    // now done with ajax calls, with blocks
                    // $deleted_count = delete_elements($collection, true);
                    if (count($collection) > 0) {
                        if (! isset($_SESSION['page_infos']) || ! is_array($_SESSION['page_infos'])) {
                            $_SESSION['page_infos'] = [];
                        }
                        $_SESSION['page_infos'][] = Translator::get()->plural(
                            '%d photo was deleted',
                            '%d photos were deleted',
                            count($collection)
                        );

                        $redirect_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $get_page;
                        $redirect = true;
                    } else {
                        \Piwigo\Core\PageState::current()->addError(Lang::t('No photo can be deleted'));
                    }
                } else {
                    \Piwigo\Core\PageState::current()->addError(Lang::t('You need to confirm deletion'));
                }
            }

            // synchronize metadata
            elseif ($action === 'metadata') {
                \Piwigo\Core\PageState::current()->addInfo(Lang::t('Metadata synchronized from file') . ' <span class="badge">' . count($collection) . '</span>');
            } elseif ($action === 'delete_derivatives' && isset($_POST['del_derivatives_type']) && is_array($_POST['del_derivatives_type']) && count($_POST['del_derivatives_type']) > 0) {
                $query = 'SELECT path,representative_ext FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $collection) . ')';
                foreach ($conn->fetchAllAssociative($query) as $info) {
                    $info_path = $info['path'];
                    $derivative_infos = [
                        'path' => is_scalar($info_path) ? (string) $info_path : '',
                    ];
                    if (is_string($info['representative_ext'] ?? null) && $info['representative_ext'] !== '') {
                        $derivative_infos['representative_ext'] = $info['representative_ext'];
                    }
                    foreach ($_POST['del_derivatives_type'] as $type) {
                        if (is_string($type)) {
                            new DerivativeCacheService()
                                ->deleteElementDerivatives($derivative_infos, $type);
                        }
                    }
                }
            } elseif ($action === 'generate_derivatives') {
                if (($_POST['regenerateSuccess'] ?? '0') !== '0') {
                    \Piwigo\Core\PageState::current()->addInfo(Lang::t('%s photos have been regenerated', $_POST['regenerateSuccess'] ?? '0'));
                }
                if (($_POST['regenerateError'] ?? '0') !== '0') {
                    \Piwigo\Core\PageState::current()->addWarning(Lang::t('%s photos can not be regenerated', $_POST['regenerateError'] ?? '0'));
                }
            }

            if (! in_array($action, ['remove_from_caddie', 'add_to_caddie', 'delete_derivatives', 'generate_derivatives'], true)) {
                PermissionCacheInvalidator::invalidate();
            }

            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('element_set_global_action', $action, $collection);

            if ($redirect) {
                $this->redirectService->redirect($redirect_url);
            }
        }

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+
        $template->set_filenames([
            'batch_manager_global' => 'batch_manager_global.tpl',
        ]);

        $base_url = $this->urlService->getRootUrl() . 'admin.php';

        // $catElementsId is a list of scalar image ids; narrowed once here
        // for every use below (including the FilterPanelRenderer call).
        $cat_elements_id = array_map(intval(...), array_filter($catElementsId, is_numeric(...)));
        $page_start = $pageStart;

        new FilterPanelRenderer()
            ->render($template, $base_url, $collection, $cat_elements_id, $page_start, $this->urlService);

        // +-------------------------------------------------------------------+
        // |                            caddie options                             |
        // +-------------------------------------------------------------------+
        $template->assign('IN_CADDIE', $prefilter_value === 'caddie');

        // +-------------------------------------------------------------------+
        // |                           global mode form                            |
        // +-------------------------------------------------------------------+

        if (count($cat_elements_id) > 0) {
            // remove tags
            $template->assign('associated_tags', self::tagService()
                ->getCommonTags($cat_elements_id, -1, \Piwigo\Bootstrap\PresentationAccessor::htmlService()));
        }

        // creation date
        $template->assign(
            'DATE_CREATION',
            ($_POST['date_creation'] ?? '') === '' ? date('Y-m-d') . ' 00:00:00' : $_POST['date_creation']
        );

        // image level options
        $template->assign(
            [
                'level_options' => \Piwigo\Permission\PermissionService::getPrivacyLevelOptions(),
                'level_options_selected' => 0,
            ]
        );

        // metadata
        $site_reader = new LocalSiteReader('./');
        $used_metadata = implode(', ', $site_reader->get_metadata_attributes());

        $template->assign(
            [
                'used_metadata' => $used_metadata,
            ]
        );

        // derivatives
        $del_deriv_map = [];
        foreach (ImageStdParams::get_defined_type_map() as $params) {
            $del_deriv_map[$params->type] = Lang::t($params->type);
        }
        $gen_deriv_map = $del_deriv_map;
        $del_deriv_map[ImageStdParams::CUSTOM] = Lang::t(ImageStdParams::CUSTOM);
        $template->assign(
            [
                'del_derivatives_types' => $del_deriv_map,
                'generate_derivatives_types' => $gen_deriv_map,
            ]
        );

        // +-------------------------------------------------------------------+
        // |                        global mode thumbnails                         |
        // +-------------------------------------------------------------------+

        // how many items to display on this page
        if (isset($_GET['display']) && $_GET['display'] !== '' && $_GET['display'] !== '0') {
            if ($_GET['display'] === 'all') {
                $nb_images = count($cat_elements_id);
            } else {
                $nb_images = is_numeric($_GET['display']) ? intval($_GET['display']) : 0;
            }
        } elseif (in_array(\Piwigo\Config\CurrentConfig::batchManagerImagesPerPageGlobal(), [20, 50, 100], true)) {
            $nb_images = \Piwigo\Config\CurrentConfig::batchManagerImagesPerPageGlobal();
        } else {
            $nb_images = 20;
        }

        $nb_thumbs_page = 0;

        if (count($cat_elements_id) > 0) {
            $nav_bar = new \Piwigo\Core\PaginationService()
                ->createNavigationBar($base_url . $this->urlService->getQueryStringDiff(['start']), count($cat_elements_id), $page_start, $nb_images);
            $template->assign('navbar', $nav_bar);

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
                $order_by_fields = array_merge($duplicatesOnFields, ['id']);
                $order_by = ' ORDER BY ' . join(', ', $order_by_fields);
            } else {
                // order_by is a raw "ORDER BY ..." SQL fragment string --
                // see CurrentConfig::orderBy()'s own docblock.
                $order_by = \Piwigo\Config\CurrentConfig::orderBy();
            }

            $query = '
SELECT id,path,representative_ext,file,filesize,level,name,width,height,rotation
  FROM ' . Tables::images();

            if ($is_category) {
                $category_info = self::categoryService()->getCategoryInfo($filter_category_id);

                $order_by = \Piwigo\Config\CurrentConfig::orderByInsideCategory();
                $category_image_order = $category_info !== null ? ($category_info['image_order'] ?? null) : null;
                if (is_string($category_image_order) && $category_image_order !== '') {
                    $order_by = ' ORDER BY ' . $category_image_order;
                }

                $query .= '
    JOIN ' . Tables::imageCategory() . ' ON id = image_id';
            }

            $query .= '
  WHERE id IN (' . implode(',', $cat_elements_id) . ')';

            if ($is_category) {
                $query .= '
    AND category_id = ' . $filter_category_id;
            }

            $query .= '
  ' . $order_by . '
  LIMIT ' . $nb_images . ' OFFSET ' . $page_start . '
;';
            $thumb_params = ImageStdParams::get_by_type(ImageStdParams::SQUARE);
            // template thumbnail initialization
            foreach ($conn->fetchAllAssociative($query) as $row) {
                $nb_thumbs_page++;
                $src_image = new SrcImage($row);

                $ttitle = \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->renderElementName($row);
                $row_file = is_string($row['file']) ? $row['file'] : '';
                if ($ttitle !== \Piwigo\Core\StringHelper::getNameFromFile($row_file)) {
                    $ttitle .= ' (' . $row_file . ')';
                }

                $row_filesize = is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0;
                $row_width = is_scalar($row['width']) ? (string) $row['width'] : '';
                $row_height = is_scalar($row['height']) ? (string) $row['height'] : '';
                $ttitle .= '<br>' . $row_width . '&times;' . $row_height . ' pixels, ' . sprintf('%.2f', $row_filesize / 1024.0) . 'MB';

                $row_id = is_scalar($row['id']) ? (string) $row['id'] : '';
                $template->append(
                    'thumbnails',
                    array_merge(
                        $row,
                        [
                            'thumb' => new DerivativeImage($thumb_params, $src_image),
                            'TITLE' => $ttitle,
                            'FILE_SRC' => DerivativeImage::url(ImageStdParams::LARGE, $src_image),
                            'U_EDIT' => $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $row_id,
                        ]
                    )
                );
            }
            $template->assign('thumb_params', $thumb_params);
        }

        $template->assign([
            'nb_thumbs_page' => $nb_thumbs_page,
            'nb_thumbs_set' => count($cat_elements_id),
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['tags', 'categories']),
        ]);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_element_set_global');

        // ----------------------------------------------------------- sending html code
        $template->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_global');
    }
}
