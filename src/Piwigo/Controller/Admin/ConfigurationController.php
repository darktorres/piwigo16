<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Latte\Runtime\Html;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Config\SizesProcessor;
use Piwigo\Admin\Config\WatermarkProcessor;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Tabsheet;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\OrderByService;
use Piwigo\Search\SearchFilterViewRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\ProfileService;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

final readonly class ConfigurationController implements AdminSubControllerInterface
{
    /** @var list<string> */
    public const array PAGES = [
        'configuration',
    ];

    public function __construct(
        private ConfigRepository $configRepository,
        private ConfigService $configService,
        private DateService $dateService,
        private GroupRepository $groupRepository,
        private ImageAdminService $imageAdminService,
        private PermissionService $permissionService,
        private ProfileService $profileService,
        private SizesProcessor $sizesProcessor,
        private UrlGenerator $urlGenerator,
        private UserService $userService,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private WatermarkProcessor $watermarkProcessor,
        private SearchFilterViewRepository $filterViewRepo,
        private Paths $paths,
        private OrderByService $orderByService,
    ) {
    }

    #[\Override]
    public function handle(string $page): void
    {
        if ($page === 'configuration') {
            $this->configuration();
        }
    }

    private function configuration(): void
    {
        $tpl = TemplateRegistry::current();

        if (!$this->permissionService->isWebmaster()) {
            PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        $this->inputValidator->check('section', $_GET, false, '/^[a-z]+$/i');

        $section = isset($_GET['section']) && is_string($_GET['section']) ? $_GET['section'] : 'main';

        $main_checkboxes = [
            'allow_user_registration', 'obligatory_user_mail_address', 'rate', 'rate_anonymous',
            'allow_user_customization', 'log', 'history_admin', 'history_guest',
            'show_mobile_app_banner_in_gallery', 'show_mobile_app_banner_in_admin', 'upload_detect_duplicate',
        ];

        $sizes_checkboxes = ['original_resize'];

        $comments_checkboxes = [
            'activate_comments', 'comments_forall', 'comments_validation',
            'email_admin_on_comment', 'email_admin_on_comment_validation',
            'user_can_delete_comment', 'user_can_edit_comment',
            'email_admin_on_comment_edition', 'email_admin_on_comment_deletion',
            'comments_author_mandatory', 'comments_email_mandatory', 'comments_enable_website',
        ];

        $display_checkboxes = [
            'menubar_filter_icon', 'index_search_in_set_button', 'index_search_in_set_action',
            'index_sort_order_input', 'index_flat_icon', 'index_posted_date_icon', 'index_created_date_icon',
            'index_slideshow_icon', 'index_sizes_icon', 'index_new_icon', 'index_edit_icon', 'index_caddie_icon',
            'display_fromto', 'picture_metadata_icon', 'picture_slideshow_icon', 'picture_favorite_icon',
            'picture_sizes_icon', 'picture_download_icon', 'picture_edit_icon', 'picture_caddie_icon',
            'picture_representative_icon', 'picture_navigation_icons', 'picture_navigation_thumb', 'picture_menu',
        ];

        $display_info_checkboxes = ['author', 'created_on', 'posted_on', 'dimensions', 'file', 'filesize', 'tags', 'categories', 'visits', 'rating_score'];

        if (!$this->filterViewRepo->hasAny()) {
            $this->filterViewRepo->replaceAll(Config::defaultFiltersViews());
        }

        $filters_views_raw        = $this->filterViewRepo->listAll();
        $filters_names_checkboxes = array_values(array_diff(array_keys($filters_views_raw), ['last_filters_conf']));

        $sort_fields = [
            ''                    => '',
            'file ASC'            => Lang::t('File name, A &rarr; Z'),
            'file DESC'           => Lang::t('File name, Z &rarr; A'),
            'name ASC'            => Lang::t('Photo title, A &rarr; Z'),
            'name DESC'           => Lang::t('Photo title, Z &rarr; A'),
            'date_creation DESC'  => Lang::t('Date created, new &rarr; old'),
            'date_creation ASC'   => Lang::t('Date created, old &rarr; new'),
            'date_available DESC' => Lang::t('Date posted, new &rarr; old'),
            'date_available ASC'  => Lang::t('Date posted, old &rarr; new'),
            'rating_score DESC'   => Lang::t('Rating score, high &rarr; low'),
            'rating_score ASC'    => Lang::t('Rating score, low &rarr; high'),
            'hit DESC'            => Lang::t('Visits, high &rarr; low'),
            'hit ASC'             => Lang::t('Visits, low &rarr; high'),
            'id ASC'              => Lang::t('Numeric identifier, 1 &rarr; 9'),
            'id DESC'             => Lang::t('Numeric identifier, 9 &rarr; 1'),
            '`rank` ASC'          => Lang::t('Manual sort order'),
        ];

        $comments_order = ['ASC' => Lang::t('Show oldest comments first'), 'DESC' => Lang::t('Show latest comments first')];
        $mail_themes    = ['light' => 'Light', 'dark' => 'Dark'];

        // ── POST submission ───────────────────────────────────────────────────

        if (isset($_POST['submit'])) {
            $this->csrfService->check();
            $int_pattern = '/^\d+$/';

            switch ($section) {
                case 'main':
                    if (!Config::has('order_by_custom') && !Config::has('order_by_inside_category_custom')) {
                        if (isset($_POST['order_by']) && $_POST['order_by'] !== '') {
                            $this->inputValidator->check('order_by', $_POST, true, '/^(' . implode('|', array_keys($sort_fields)) . ')$/');
                            $post_order_by = is_array($_POST['order_by']) ? $_POST['order_by'] : [];
                            /** @var list<array{field: string, dir: string}> $parsed */
                            $parsed = [];
                            $seen = [];
                            foreach ($post_order_by as $val) {
                                if (!is_string($val) || $val === '') {
                                    continue;
                                }
                                $entry = $this->orderByService->parseFormToken($val);
                                if ($entry === null) {
                                    continue;
                                }
                                $key = $entry['field'] . ' ' . $entry['dir'];
                                if (isset($seen[$key])) {
                                    continue;
                                }
                                $seen[$key] = true;
                                $parsed[]   = $entry;
                            }
                            if ($parsed === []) {
                                PageState::current()->addError(Lang::t('No order field selected'));
                            } else {
                                $sliced = array_slice($parsed, 0, (int) ceil(count($sort_fields) / 2));
                                // `order_by` is the gallery-wide (non-category) ordering — strip `rank`
                                // since manual rank only applies inside a category context.
                                $orderBy = array_values(array_filter($sliced, static fn (array $e): bool => $e['field'] !== 'rank'));
                                if ($orderBy === []) {
                                    $orderBy = [['field' => 'id', 'dir' => 'ASC']];
                                }
                                $_POST['order_by']                  = $orderBy;
                                $_POST['order_by_inside_category']  = $sliced;
                            }
                        } else {
                            PageState::current()->addError(Lang::t('No order field selected'));
                        }
                    }

                    if (!isset($_POST['email_admin_on_new_user']) || $_POST['email_admin_on_new_user'] === '') {
                        $_POST['email_admin_on_new_user'] = 'none';
                    } elseif ('all' == $_POST['email_admin_on_new_user_filter']) {
                        $_POST['email_admin_on_new_user'] = 'all';
                    } else {
                        $filterGroup = $_POST['email_admin_on_new_user_filter_group'] ?? null;
                        $_POST['email_admin_on_new_user'] = (is_string($filterGroup) && $filterGroup !== '') ? 'group:' . $filterGroup : 'all';
                    }

                    foreach ($main_checkboxes as $checkbox) {
                        $_POST[$checkbox] = (!isset($_POST[$checkbox]) || $_POST[$checkbox] === '') ? 'false' : 'true';
                    }
                    break;

                case 'watermark':
                    $this->watermarkProcessor->process();
                    break;

                case 'sizes':
                    $this->sizesProcessor->process();
                    break;

                case 'comments':
                    $nb_comment_page = is_string($rawNbCommentPage = $_POST['nb_comment_page'] ?? null) ? $rawNbCommentPage : '';
                    if (!preg_match($int_pattern, $nb_comment_page)
                        || (is_numeric($nb_comment_page) && $nb_comment_page < 5)
                        || (is_numeric($nb_comment_page) && $nb_comment_page > 50)) {
                        PageState::current()->addError(Lang::t('The number of comments a page must be between 5 and 50 included.'));
                    }
                    foreach ($comments_checkboxes as $checkbox) {
                        $_POST[$checkbox] = (!isset($_POST[$checkbox]) || $_POST[$checkbox] === '') ? 'false' : 'true';
                    }
                    break;

                case 'display':
                    $nb_categories_page = is_string($rawNbCatPage = $_POST['nb_categories_page'] ?? null) ? $rawNbCatPage : '';
                    if (!preg_match($int_pattern, $nb_categories_page)
                        || (is_numeric($nb_categories_page) && $nb_categories_page < 4)) {
                        PageState::current()->addError(Lang::t('The number of albums a page must be above 4.'));
                    }
                    foreach ($display_checkboxes as $checkbox) {
                        $_POST[$checkbox] = (!isset($_POST[$checkbox]) || $_POST[$checkbox] === '') ? 'false' : 'true';
                    }
                    $picInfoRaw = $_POST['picture_informations'] ?? null;
                    $post_picture_informations = is_array($picInfoRaw) ? $picInfoRaw : [];
                    foreach ($display_info_checkboxes as $checkbox) {
                        $picInfoVal = $post_picture_informations[$checkbox] ?? null;
                        $post_picture_informations[$checkbox] = $picInfoVal !== null;
                    }
                    $_POST['picture_informations'] = json_encode($post_picture_informations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    break;

                case 'search':
                    $filtersViewsRaw = $_POST['filters_views'] ?? null;
                    $rawSrc = is_array($filtersViewsRaw) ? $filtersViewsRaw : [];
                    $filtersViewsBoxRaw = $_POST['filters_views_box'] ?? null;
                    $post_filters_views_box = is_array($filtersViewsBoxRaw) ? $filtersViewsBoxRaw : [];
                    /** @var array<string, mixed> $post_filters_views */
                    $post_filters_views = [];
                    foreach ($rawSrc as $key => $val) {
                        if (is_string($key)) {
                            $post_filters_views[$key] = $val;
                        }
                    }
                    foreach ($filters_names_checkboxes as $checkbox) {
                        $fvRaw = $post_filters_views[$checkbox] ?? null;
                        $fv_entry = is_array($fvRaw) ? $fvRaw : [];
                        $fvBoxVal = $post_filters_views_box[$checkbox] ?? null;
                        if ($fvBoxVal === null || $fvBoxVal === '') {
                            $fv_entry['access'] = 'nobody';
                            $fv_entry['default'] = false;
                        } else {
                            $fvDefaultVal = $fv_entry['default'] ?? null;
                            $fv_entry['default'] = $fvDefaultVal !== null && $fvDefaultVal !== '';
                        }
                        $post_filters_views[$checkbox] = $fv_entry;
                    }
                    $lastFiltersConfVal = $post_filters_views['last_filters_conf'] ?? null;
                    $post_filters_views['last_filters_conf'] = $lastFiltersConfVal !== null && $lastFiltersConfVal !== '';
                    $this->filterViewRepo->replaceAll($post_filters_views);
                    // Removed from $_POST so the conf-table loop below
                    // doesn't try to mirror this key back into config.
                    unset($_POST['filters_views']);
                    break;
            }

            if (!in_array($section, ['sizes', 'watermark']) && count(PageState::current()->errors) == 0 && $this->permissionService->isWebmaster()) {
                foreach ($this->configRepository->findAllParams() as $row_param) {
                    if (isset($_POST[$row_param])) {
                        /** @var mixed $raw */
                        $raw = $_POST[$row_param];
                        if (is_array($raw)) {
                            // order_by / order_by_inside_category land here as
                            // structured list<array{field, dir}> — pass through.
                            $this->configService->confUpdateParam($row_param, $raw);
                            continue;
                        }
                        $value = is_string($raw) ? $raw : '';
                        if ('gallery_title' == $row_param && !Config::allowHtmlDescriptions()) {
                            $value = strip_tags($value);
                        }
                        $this->configService->confUpdateParam($row_param, $value);
                    }
                }
                $tpl->assign(['save_success' => Lang::t('Your configuration settings are saved')]);
                $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Core, 'config', ['config_section' => $section]));
            }

            ConfigService::loadConfFromDb();
        }

        // ── Restore default derivatives ───────────────────────────────────────

        if ($section === 'sizes' && isset($_GET['action']) && 'restore_settings' == $_GET['action']) {
            ImageStdParams::restoreDefault();
            $this->imageAdminService->clearDerivativeCache();
            ConfigService::loadConfFromDb();
            $tpl->assign(['save_success' => Lang::t('Your configuration settings are saved')]);
            $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Core, 'config', ['config_section' => $section, 'config_action' => $_GET['action']]));
        }

        // ── Template init ─────────────────────────────────────────────────────

        $tabsheet = new Tabsheet();
        $tabsheet->setId('configuration');
        $tabsheet->select($section);
        $tabsheet->assign();

        $action = $this->urlGenerator->admin('configuration') . '&section=' . $section;

        $tpl->assign([
            'U_HELP'    => $this->urlGenerator->adminPopupHelp('configuration'),
            'PWG_TOKEN' => $this->csrfService->getToken(),
            'F_ACTION'  => $action,
        ]);

        // ── Section display ───────────────────────────────────────────────────

        switch ($section) {
            case 'main':
                if ($this->orderByIsLocal()) {
                    PageState::current()->addWarning(new Html(Lang::t('You have specified <i>' . '$' . 'conf[\'order_by\']</i> in your local configuration file, this parameter in deprecated, please remove it or rename it into <i>' . '$' . 'conf[\'order_by_custom\']</i> !')));
                }

                if (Config::has('order_by_custom') || Config::has('order_by_inside_category_custom')) {
                    $order_by = [''];
                    $tpl->assign('ORDER_BY_IS_CUSTOM', true);
                } else {
                    $order_by = array_map(
                        fn (array $e): string => $this->orderByService->toFormToken($e),
                        Config::orderByInsideCategory()
                    );
                }

                $tpl->assign('main', [
                    'CONF_GALLERY_TITLE'                    => htmlspecialchars(Config::galleryTitle()),
                    'CONF_PAGE_BANNER'                      => htmlspecialchars(Config::pageBanner()),
                    'week_starts_on_options'                => [
                        'sunday' => Lang::day(0) ?: 'Sunday',
                        'monday' => Lang::day(1) ?: 'Monday',
                    ],
                    'week_starts_on_options_selected'       => Config::weekStartsOn(),
                    'mail_theme'                            => Config::mailTheme(),
                    'mail_theme_options'                    => $mail_themes,
                    'order_by'                              => $order_by,
                    'order_by_options'                      => $sort_fields,
                    'email_admin_on_new_user'               => 'none' != Config::emailAdminOnNewUser(),
                    'email_admin_on_new_user_filter'        => in_array(Config::emailAdminOnNewUser(), ['none', 'all']) ? 'all' : 'group',
                    'email_admin_on_new_user_filter_group'  => preg_match('/^group:(\d+)$/', Config::emailAdminOnNewUser(), $matches) ? $matches[1] : -1,
                ]);

                $groups = $this->groupRepository->findAllIdToNameMapOrderedByName();
                natcasesort($groups);
                $tpl->assign(['group_options' => $groups]);

                $tpl->append('main', [
                    'allow_user_registration'           => Config::allowUserRegistration(),
                    'obligatory_user_mail_address'      => Config::obligatoryUserMailAddress(),
                    'rate'                              => Config::rateEnabled(),
                    'rate_anonymous'                    => Config::rateAnonymous(),
                    'allow_user_customization'          => Config::allowUserCustomization(),
                    'log'                               => Config::logConf(),
                    'history_admin'                     => Config::historyAdmin(),
                    'history_guest'                     => Config::historyGuest(),
                    'show_mobile_app_banner_in_gallery' => (bool) $this->configService->confGetParam('show_mobile_app_banner_in_gallery', false),
                    'show_mobile_app_banner_in_admin'   => (bool) $this->configService->confGetParam('show_mobile_app_banner_in_admin', true),
                    'upload_detect_duplicate'           => Config::uploadDetectDuplicate(),
                ], true);

                $tpl->assign('page_data_json', json_encode([
                    'order_by_is_custom'        => Config::has('order_by_custom') || Config::has('order_by_inside_category_custom'),
                    'order_by_options_count'    => count($sort_fields),
                ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
                break;

            case 'comments':
                $tpl->assign('comments', [
                    'NB_COMMENTS_PAGE'    => Config::nbCommentPage(),
                    'comments_order'      => Config::commentsOrder(),
                    'comments_order_options' => $comments_order,
                ]);
                $tpl->append('comments', [
                    'activate_comments'                 => Config::activateComments(),
                    'comments_forall'                   => Config::commentsForall(),
                    'comments_validation'               => Config::commentsValidation(),
                    'email_admin_on_comment'            => Config::emailAdminOnComment(),
                    'email_admin_on_comment_validation' => Config::emailAdminOnCommentValidation(),
                    'user_can_delete_comment'           => Config::userCanDeleteComment(),
                    'user_can_edit_comment'             => Config::userCanEditComment(),
                    'email_admin_on_comment_edition'    => Config::emailAdminOnCommentEdition(),
                    'email_admin_on_comment_deletion'   => Config::emailAdminOnCommentDeletion(),
                    'comments_author_mandatory'         => Config::commentsAuthorMandatory(),
                    'comments_email_mandatory'          => Config::commentsEmailMandatory(),
                    'comments_enable_website'           => Config::commentsEnableWebsite(),
                ], true);
                break;

            case 'default':
                $edit_user = $this->userService->buildUser(Config::guestId(), false);
                $errors = [];
                if ($this->profileService->saveProfileFromPost($edit_user, $errors)) {
                    $edit_user = $this->userService->buildUser(Config::guestId(), false);
                    PageState::current()->addInfo(Lang::t('Information data registered in database'));
                }
                foreach ($errors as $err) {
                    PageState::current()->addError($err);
                }

                $this->profileService->loadProfileInTemplate($action, '', $edit_user, 'GUEST_');
                $tpl->assign('default', []);
                break;

            case 'display':
                $tpl->append('display', [
                    'menubar_filter_icon'         => Config::menubarFilterIcon(),
                    'index_search_in_set_button'  => Config::indexSearchInSetButton(),
                    'index_search_in_set_action'  => Config::indexSearchInSetAction(),
                    'index_sort_order_input'      => Config::indexSortOrderInput(),
                    'index_flat_icon'             => Config::indexFlatIcon(),
                    'index_posted_date_icon'      => Config::indexPostedDateIcon(),
                    'index_created_date_icon'     => Config::indexCreatedDateIcon(),
                    'index_slideshow_icon'        => Config::indexSlideShowIcon(),
                    'index_sizes_icon'            => Config::indexSizesIcon(),
                    'index_new_icon'              => Config::indexNewIcon(),
                    'index_edit_icon'             => Config::indexEditIcon(),
                    'index_caddie_icon'           => Config::indexCaddieIcon(),
                    'display_fromto'              => Config::displayFromto(),
                    'picture_metadata_icon'       => Config::pictureMetadataIcon(),
                    'picture_slideshow_icon'      => Config::pictureSlideShowIcon(),
                    'picture_favorite_icon'       => Config::pictureFavoriteIcon(),
                    'picture_sizes_icon'          => Config::pictureSizesIcon(),
                    'picture_download_icon'       => Config::pictureDownloadIcon(),
                    'picture_edit_icon'           => Config::pictureEditIcon(),
                    'picture_caddie_icon'         => Config::pictureCaddieIcon(),
                    'picture_representative_icon' => Config::pictureRepresentativeIcon(),
                    'picture_navigation_icons'    => Config::pictureNavigationIcons(),
                    'picture_navigation_thumb'    => Config::pictureNavigationThumb(),
                    'picture_menu'                => Config::pictureMenu(),
                ], true);
                $tpl->append('display', [
                    'picture_informations' => Config::pictureInformations(),
                    'NB_CATEGORIES_PAGE'   => Config::nbCategoriesPage(),
                ], true);
                break;

            case 'sizes':
                if (!$this->sizesProcessor->isSizesLoadedInTpl()) {
                    $is_gd = (PwgImage::getLibrary() == 'gd');
                    $tpl->assign('is_gd', $is_gd);
                    $tpl->assign('sizes', [
                        'original_resize_maxwidth'  => Config::originalResizeMaxwidth(),
                        'original_resize_maxheight' => Config::originalResizeMaxheight(),
                        'original_resize_quality'   => Config::originalResizeQuality(),
                    ]);
                    $tpl->append('sizes', [
                        'original_resize' => Config::originalResize(),
                    ], true);

                    $enabled  = ImageStdParams::getDefinedTypeMap();
                    $disabled = ImageStdParams::getDisabledTypeMap();

                    $tpl_vars = [];
                    foreach (ImageStdParams::getAllTypes() as $type) {
                        $tpl_var = [];
                        $tpl_var['must_square']  = ($type == DerivativeSize::Square->value);
                        $tpl_var['must_enable']  = ($type == DerivativeSize::Square->value || $type == DerivativeSize::Thumb->value || $type == Config::derivativeDefaultSize());
                        $params = $enabled[$type] ?? null;
                        if ($params !== null) {
                            $tpl_var['enabled'] = true;
                        } else {
                            $tpl_var['enabled'] = false;
                            $params_raw = $disabled[$type] ?? null;
                            $params = ($params_raw instanceof DerivativeParams) ? $params_raw : null;
                        }
                        if ($params instanceof DerivativeParams) {
                            $idealSize = $params->sizing->ideal_size;
                            [$tpl_var['w'], $tpl_var['h']] = [$idealSize[0] ?? 0, $idealSize[1] ?? 0];
                            if (($tpl_var['crop'] = round(100.0 * $params->sizing->max_crop)) > 0) {
                                $minSize = $params->sizing->min_size;
                                [$tpl_var['minw'], $tpl_var['minh']] = [$minSize[0] ?? 0, $minSize[1] ?? 0];
                            } else {
                                $tpl_var['minw'] = $tpl_var['minh'] = '';
                            }
                            $tpl_var['sharpen'] = $params->sharpen;
                        }
                        $tpl_vars[$type] = $tpl_var;
                    }
                    $tpl->assign('derivatives', $tpl_vars);
                    $tpl->assign('resize_quality', ImageStdParams::$quality);

                    $tpl_vars = [];
                    $now = time();
                    foreach (ImageStdParams::$custom as $custom => $time) {
                        $tpl_vars[$custom] = ($now - $time <= 24 * 3600) ? Lang::t('today') : $this->dateService->timeSince($time, 'day');
                    }
                    $tpl->assign('custom_derivatives', $tpl_vars);
                }

                $tpl->assign('page_data_json', json_encode([
                    'str_restore_confirm' => Lang::t('Are you sure you want to restore to default settings?'),
                    'str_max_width'       => Lang::t('Maximum width'),
                    'str_width'           => Lang::t('Width'),
                    'str_max_height'      => Lang::t('Maximum height'),
                    'str_height'          => Lang::t('Height'),
                ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
                break;

            case 'watermark':
                $watermark_files = [];
                $watermarkGlob = glob($this->paths->root . 'themes/_base/watermarks/*.png');
                foreach ($watermarkGlob !== false ? $watermarkGlob : [] as $file) {
                    $watermark_files[] = substr($file, strlen($this->paths->root));
                }
                if (($glob = glob($this->paths->local . 'watermarks/*.png')) !== false) {
                    foreach ($glob as $file) {
                        $watermark_files[] = substr($file, strlen($this->paths->root));
                    }
                }
                $watermark_filemap = ['' => '---'];
                foreach ($watermark_files as $file) {
                    $watermark_filemap[$file] = basename($file);
                }
                $tpl->assign('watermark_files', $watermark_filemap);

                if ($tpl->getTemplateVars('watermark') === null) {
                    $wm = ImageStdParams::getWatermark();
                    $position = 'custom';
                    if ($wm->xpos == 0   && $wm->ypos == 0) {
                        $position = 'topleft';
                    }
                    if ($wm->xpos == 100 && $wm->ypos == 0) {
                        $position = 'topright';
                    }
                    if ($wm->xpos == 50  && $wm->ypos == 50) {
                        $position = 'middle';
                    }
                    if ($wm->xpos == 0   && $wm->ypos == 100) {
                        $position = 'bottomleft';
                    }
                    if ($wm->xpos == 100 && $wm->ypos == 100) {
                        $position = 'bottomright';
                    }
                    if ($wm->xrepeat != 0 || $wm->yrepeat != 0) {
                        $position = 'custom';
                    }

                    $tpl->assign('watermark', [
                        'file'     => $wm->file,
                        'minw'     => $wm->min_size[0],
                        'minh'     => $wm->min_size[1],
                        'xpos'     => $wm->xpos,
                        'ypos'     => $wm->ypos,
                        'xrepeat'  => $wm->xrepeat,
                        'yrepeat'  => $wm->yrepeat,
                        'opacity'  => $wm->opacity,
                        'position' => $position,
                    ]);
                }

                $tpl->assign('page_data_json', json_encode(['root_url' => UrlService::getRootUrl()], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
                break;

            case 'search':
                $tpl->assign('search', [
                    'filters_views' => $this->filterViewRepo->listAll(),
                    'filters_names' => $filters_names_checkboxes,
                ]);
                $tpl->assign('SHOW_FILTER_RATINGS', Config::rateEnabled());
                $tpl->assign('page_data_json', json_encode(['filters_names' => $filters_names_checkboxes], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
                break;
        }

        $tpl->assign('isWebmaster', $this->permissionService->isWebmaster() ? 1 : 0);
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Configuration'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'configuration_' . $section . '.latte');
    }

    private function orderByIsLocal(): bool
    {
        $candidates = [
            $this->paths->root . 'local/config/config.inc.php',
            $this->paths->local . 'config/config.inc.php',
        ];
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            $content = is_readable($real) ? file_get_contents($real) : false;
            if ($content !== false && preg_match('/\$conf\s*\[\s*[\'"](order_by|order_by_inside_category)[\'"]\s*\]\s*=/', $content) === 1) {
                return true;
            }
        }
        return false;
    }
}
