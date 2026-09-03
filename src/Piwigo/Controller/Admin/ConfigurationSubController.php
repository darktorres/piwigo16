<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Admin\Tabsheet;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\AdminAccessor;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\FilterViewDefinition;
use Piwigo\Config\FilterViewsSelection;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Controller\Admin\Projection\ConfigurationCommentsData;
use Piwigo\Controller\Admin\Projection\ConfigurationCommentsView;
use Piwigo\Controller\Admin\Projection\ConfigurationDefaultView;
use Piwigo\Controller\Admin\Projection\ConfigurationDisplayData;
use Piwigo\Controller\Admin\Projection\ConfigurationDisplayView;
use Piwigo\Controller\Admin\Projection\ConfigurationMainData;
use Piwigo\Controller\Admin\Projection\ConfigurationMainView;
use Piwigo\Controller\Admin\Projection\ConfigurationSearchTabData;
use Piwigo\Controller\Admin\Projection\ConfigurationSearchView;
use Piwigo\Controller\Admin\Projection\ConfigurationSizesResult;
use Piwigo\Controller\Admin\Projection\ConfigurationSizesTabData;
use Piwigo\Controller\Admin\Projection\ConfigurationSizesView;
use Piwigo\Controller\Admin\Projection\ConfigurationWatermarkResult;
use Piwigo\Controller\Admin\Projection\ConfigurationWatermarkView;
use Piwigo\Controller\Admin\Projection\DerivativeSizeErrors;
use Piwigo\Controller\Admin\Projection\DerivativeSizeRow;
use Piwigo\Controller\Admin\Projection\SizesFormErrors;
use Piwigo\Controller\Admin\Projection\WatermarkFormErrors;
use Piwigo\Controller\Admin\Projection\WatermarkFormValues;
use Piwigo\Controller\Admin\Request\ConfigurationRequest;
use Piwigo\Controller\ProfileFormHandler;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\DateHelper;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\View;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupService;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\Dimensions;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Image\WatermarkParams;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/configuration.php (page slug "configuration") -- a large
 * tabbed page (main/watermark/sizes/comments/default/display/search). Its
 * own `?section=` tab dispatch stays inline rather than being split into
 * per-tab sub-controllers, since the switch is tightly tied to this file's
 * local `$page['section']` handling.
 *
 * admin.php already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so this
 * controller does not repeat that check.
 *
 * The "watermark" and "sizes" tabs' POST handlers (`processWatermark()`/
 * `processSizes()` below) write through typed abstractions
 * (`ImageStdParams::save()`/`setAndSave()`,
 * `UploadService::saveUploadFormConfig()`), not raw SQL. The "default" tab
 * edits the guest user's profile via
 * `Piwigo\Controller\ProfileFormHandler::saveFromPost()`.
 *
 * The generic config-row UPDATE loop, the two `$conf`-reinit calls, and the
 * filters_views default-seed call all go through the injected
 * `ConfigService`, not raw SQL.
 *
 * `$lang->days()` isn't guaranteed to define every index (no locale's
 * `common.lang.php` defines it), so the "main" tab's read of it is
 * `??`-guarded, matching `DateHelper::formatDateLegacy()`'s own guard for
 * the same value.
 *
 * The "sizes" tab's "Reset to default values" action
 * (`?action=restore_settings`, resets `ImageStdParams` to Piwigo's built-in
 * defaults) is gated by both `isWebmaster()` and a CSRF token check, the
 * same as every other write path in this file.
 */
final class ConfigurationSubController implements AdminSubControllerInterface
{
    /**
     * The range each watermark form field accepts, in the notation
     * configuration_watermark.latte puts in the error marker's `title`.
     * Every one of the seven is numeric; the four without an upper bound
     * in their label are bounded by what the renderer can use, not by a
     * round number -- see processWatermark()'s own comments.
     *
     * @var array<string, string>
     */
    private const array WATERMARK_FIELD_RANGES = [
        'xpos' => '[0..100]',
        'ypos' => '[0..100]',
        'opacity' => '(0..100]',
        'minw' => '>= 0',
        'minh' => '>= 0',
        'xrepeat' => '[0..100]',
        'yrepeat' => '[0..100]',
    ];

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }

    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly StorageRegistry $storageRegistry,
        private readonly AdminContext $adminContext,
        private readonly CoreTabs $coreTabs,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ImageStdParams $imageStdParams,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityService $activityService,
        private readonly UserService $userService,
        private readonly GroupService $groupService,
        private readonly PasswordService $passwordService,
        private readonly AuthService $authService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly MailService $mailService,
        private readonly CurrentConfig $currentConfig,
        private readonly CsrfService $csrfService,
        private readonly InputValidator $inputValidator,
        private readonly Paths $paths,
        private readonly Renderer $renderer,
    ) {}

    /**
     * Set by processSizes() when a submitted "sizes" tab form fails
     * validation, so handle()'s own tab-render branch below knows the
     * ConfigurationSizesView it should build already has its
     * (invalid) submitted values from $sizesResult and skips overwriting
     * them with fresh defaults -- shared across two call sites of this
     * same request's instance, hence an instance property rather than a
     * local variable.
     */
    private bool $sizesLoadedInTpl = false;

    /**
     * Same shape as $sizesLoadedInTpl above, for the "watermark" tab --
     * replaces the original's own `Template::getTemplateVars('watermark')
     * === null` check, which read back whatever processWatermark() had
     * already assigned onto the shared Template::$vars bag; that ambient
     * read-back has no equivalent once processWatermark() stops calling
     * assignContext() and returns its own result instead.
     */
    private bool $watermarkLoadedInTpl = false;

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        /** @var array<string, mixed> $page */
        $page = [];

        $conn = DbConnection::build();

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $configurationRequest = ConfigurationRequest::fromGlobals($this->inputValidator);

        $page_section = $configurationRequest->section;
        $page['section'] = $page_section;

        // Only used by the POST-handling save path below (normalizing
        // submitted checkbox values) -- the render-time display reads
        // this tab's checkboxes' CurrentConfig properties directly by
        // name instead, see ConfigurationMainData's own construction
        // below.
        $main_checkboxes = [
            'allow_user_registration',
            'obligatory_user_mail_address',
            'rate',
            'rate_anonymous',
            'allow_user_customization',
            'log',
            'history_admin',
            'history_guest',
            'show_mobile_app_banner_in_gallery',
            'show_mobile_app_banner_in_admin',
            'upload_detect_duplicate',
        ];

        // Only used by the POST-handling save path below (normalizing
        // submitted checkbox values) -- the render-time display reads
        // this tab's checkboxes' CurrentConfig properties directly by
        // name instead, see ConfigurationCommentsData's own construction
        // below.
        $comments_checkboxes = [
            'activate_comments',
            'comments_forall',
            'comments_validation',
            'email_admin_on_comment',
            'email_admin_on_comment_validation',
            'user_can_delete_comment',
            'user_can_edit_comment',
            'email_admin_on_comment_edition',
            'email_admin_on_comment_deletion',
            'comments_author_mandatory',
            'comments_email_mandatory',
            'comments_enable_website',
        ];

        $display_checkboxes = [
            'menubar_filter_icon',
            'index_search_in_set_button',
            'index_search_in_set_action',
            'index_sort_order_input',
            'index_flat_icon',
            'index_posted_date_icon',
            'index_created_date_icon',
            'index_slideshow_icon',
            'index_sizes_icon',
            'index_new_icon',
            'index_edit_icon',
            'index_caddie_icon',
            'display_fromto',
            'picture_metadata_icon',
            'picture_slideshow_icon',
            'picture_favorite_icon',
            'picture_sizes_icon',
            'picture_download_icon',
            'picture_edit_icon',
            'picture_caddie_icon',
            'picture_representative_icon',
            'picture_navigation_icons',
            'picture_navigation_thumb',
            'picture_menu',
        ];

        $display_info_checkboxes = [
            'author',
            'created_on',
            'posted_on',
            'dimensions',
            'file',
            'filesize',
            'tags',
            'categories',
            'visits',
            'rating_score',
        ];

        if (! $this->currentConfig->filtersViews instanceof FilterViewsSelection) {
            $this->configService->confUpdateParam(
                'filters_views',
                array_map(static fn (FilterViewDefinition $d): array => $d->toArray(), $this->currentConfig->defaultFiltersViews),
                true,
            );
        }

        // The checkbox *iteration order* always comes from the canonical
        // declared order (defaultFiltersViews, built from
        // DEFAULT_FILTERS_VIEWS's own fixed declaration order), never
        // from the possibly-already-saved filtersViews's own key order:
        // `config.value` is a native MySQL `json` column, which does NOT
        // preserve object key insertion order on write -- MySQL
        // canonicalizes JSON object keys by length then lexicographically
        // when storing/retrieving. Using the saved value's own key order
        // here would silently reorder these checkboxes away from their
        // intended, designed order the first time this page's own
        // first-visit seed (above) round-trips through the DB. Real per-
        // filter access/default *values* still come from whichever of
        // filtersViews/defaultFiltersViews is active (looked up by name,
        // not iterated) wherever this page reads them.
        $filters_names_checkboxes = array_keys($this->currentConfig->defaultFiltersViews);

        // image order management
        $sort_fields = [
            '' => '',
            'file ASC' => $this->lang->t('File name, A &rarr; Z'),
            'file DESC' => $this->lang->t('File name, Z &rarr; A'),
            'name ASC' => $this->lang->t('Photo title, A &rarr; Z'),
            'name DESC' => $this->lang->t('Photo title, Z &rarr; A'),
            'date_creation DESC' => $this->lang->t('Date created, new &rarr; old'),
            'date_creation ASC' => $this->lang->t('Date created, old &rarr; new'),
            'date_available DESC' => $this->lang->t('Date posted, new &rarr; old'),
            'date_available ASC' => $this->lang->t('Date posted, old &rarr; new'),
            'rating_score DESC' => $this->lang->t('Rating score, high &rarr; low'),
            'rating_score ASC' => $this->lang->t('Rating score, low &rarr; high'),
            'hit DESC' => $this->lang->t('Visits, high &rarr; low'),
            'hit ASC' => $this->lang->t('Visits, low &rarr; high'),
            'id ASC' => $this->lang->t('Numeric identifier, 1 &rarr; 9'),
            'id DESC' => $this->lang->t('Numeric identifier, 9 &rarr; 1'),
            '`rank` ASC' => $this->lang->t('Manual sort order'),
        ];

        $comments_order = [
            'ASC' => $this->lang->t('Show oldest comments first'),
            'DESC' => $this->lang->t('Show latest comments first'),
        ];

        $mail_themes = [
            'clear' => 'Clear',
            'dark' => 'Dark',
        ];

        // A local working copy of post -- the per-tab submit handling below
        // used to mutate several $_POST fields in place so the generic
        // config-row UPDATE loop further down (which reads back whatever
        // is in $_POST for each known config param name) saw the
        // overridden values; both stay within this one handle() call.
        $post = $configurationRequest->post;

        $save_success = null;

        // Populated only by the matching case below when this request
        // submitted the "sizes"/"watermark" tab's own form -- read back by
        // the render-time switch further down to merge processSizes()'s/
        // processWatermark()'s own result into that tab's final View.
        $sizesResult = null;
        $watermarkResult = null;

        if ($configurationRequest->isSubmitted) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
            $int_pattern = '/^\d+$/';

            switch ($page['section']) {
                case 'main':

                    if (! self::emptyValue($post['order_by'] ?? null)) {
                        $this->inputValidator
                            ->validate('order_by', $post, true, '/^(' . implode('|', array_keys($sort_fields)) . ')$/');

                        // check_input_parameter() above fatal_error()s unless
                        // $_POST['order_by'] is an array of scalars matching
                        // $pattern, but that guarantee isn't visible to static
                        // analysis; re-derive it into a local, string-only copy
                        // (values from an HTTP request are always strings here).
                        $post_order_by = $post['order_by'] ?? null;
                        $order_by_input = [];
                        if (is_array($post_order_by)) {
                            foreach ($post_order_by as $raw_order_by_key => $raw_order_by_value) {
                                if (is_string($raw_order_by_value)) {
                                    $order_by_input[$raw_order_by_key] = $raw_order_by_value;
                                }
                            }
                        }

                        $used = [];
                        foreach ($order_by_input as $i => $val) {
                            if (self::emptyValue($val) or isset($used[$val])) {
                                unset($order_by_input[$i]);
                            } else {
                                $used[$val] = true;
                            }
                        }
                        if (! (bool) count($order_by_input)) {
                            $this->pageState->addError($this->lang->t('No order field selected'));
                        } else {
                            // limit to the number of available parameters
                            $order_by = $order_by_inside_category = array_slice($order_by_input, 0, (int) ceil(count($sort_fields) / 2));

                            // there is no rank outside categories
                            if (($i = array_search('`rank` ASC', $order_by, true)) !== false) {
                                unset($order_by[$i]);
                            }

                            // must define a default order_by if user want to order by rank only
                            if (count($order_by) === 0) {
                                $order_by = ['id ASC'];
                            }

                            $post['order_by'] = 'ORDER BY ' . implode(', ', $order_by);
                            $post['order_by_inside_category'] = 'ORDER BY ' . implode(', ', $order_by_inside_category);
                        }
                    } else {
                        $this->pageState->addError($this->lang->t('No order field selected'));
                    }

                    if (self::emptyValue($post['email_admin_on_new_user'] ?? null)) {
                        $post['email_admin_on_new_user'] = 'none';
                    } elseif ($post['email_admin_on_new_user_filter'] === 'all') {
                        $post['email_admin_on_new_user'] = 'all';
                    } else {
                        if (self::emptyValue($post['email_admin_on_new_user_filter_group'] ?? null)) {
                            $post['email_admin_on_new_user'] = 'all';
                        } else {
                            $filter_group = $post['email_admin_on_new_user_filter_group'] ?? null;
                            $post['email_admin_on_new_user'] = 'group:' . (is_string($filter_group) ? $filter_group : '');
                        }
                    }

                    foreach ($main_checkboxes as $checkbox) {
                        $post[$checkbox] = ! self::emptyValue($post[$checkbox] ?? null);
                    }
                    break;

                case 'watermark':

                    $watermarkResult = $this->processWatermark($post, $configurationRequest->files);
                    break;

                case 'sizes':

                    $sizesResult = $this->processSizes($post);
                    break;

                case 'comments':

                    // the number of comments per page must be an integer between 5 and 50
                    // included
                    $nb_comment_page = $post['nb_comment_page'] ?? null;
                    if (! (bool) preg_match($int_pattern, is_string($nb_comment_page) ? $nb_comment_page : '')
                         or $nb_comment_page < 5
                         or $nb_comment_page > 50) {
                        $this->pageState->addError($this->lang->t('The number of comments a page must be between 5 and 50 included.'));
                    }
                    foreach ($comments_checkboxes as $checkbox) {
                        $post[$checkbox] = ! self::emptyValue($post[$checkbox] ?? null);
                    }
                    break;

                case 'default':

                    // Never go here
                    break;

                case 'display':

                    $nb_categories_page = $post['nb_categories_page'] ?? null;
                    if (! (bool) preg_match($int_pattern, is_string($nb_categories_page) ? $nb_categories_page : '')
                          or $nb_categories_page < 4) {
                        $this->pageState->addError($this->lang->t('The number of albums a page must be above 4.'));
                    }
                    foreach ($display_checkboxes as $checkbox) {
                        $post[$checkbox] = ! self::emptyValue($post[$checkbox] ?? null);
                    }
                    $picture_informations_raw = is_array($post['picture_informations'] ?? null) ? $post['picture_informations'] : [];
                    $picture_informations = array_fill_keys($display_info_checkboxes, false);
                    foreach ($display_info_checkboxes as $checkbox) {
                        $picture_informations[$checkbox] =
                          ! self::emptyValue($picture_informations_raw[$checkbox] ?? null);
                    }
                    // The generic save loop below accepts a real array here --
                    // ConfigService::confUpdateParam() json_encode()s it,
                    // matching CurrentConfig::pictureInformations()'s own
                    // already-decoded-array expectation.
                    $post['picture_informations'] = $picture_informations;
                    break;

                case 'search':

                    $filters_views_box = is_array($post['filters_views_box'] ?? null) ? $post['filters_views_box'] : [];
                    $filters_views_raw = is_array($post['filters_views'] ?? null) ? $post['filters_views'] : [];

                    $filters_views_post = [];
                    foreach ($filters_names_checkboxes as $checkbox) {
                        $checkbox_raw = $filters_views_raw[$checkbox] ?? null;
                        $filter_conf = is_array($checkbox_raw) ? $checkbox_raw : [];

                        if (self::emptyValue($filters_views_box[$checkbox] ?? null)) {
                            $filter_conf['access'] = 'nobody';
                            $filter_conf['default'] = false;
                        } else {
                            $filter_conf['default'] =
                              self::emptyValue($filter_conf['default'] ?? null) ? false : true;
                        }

                        $filters_views_post[$checkbox] = $filter_conf;
                    }
                    $filters_views_post['last_filters_conf'] =
                      self::emptyValue($filters_views_raw['last_filters_conf'] ?? null) ? false : true;
                    // Same reasoning as picture_informations above: a real
                    // array, not a manually serialized string.
                    $post['filters_views'] = $filters_views_post;

            }

            // updating configuration if no error found
            if (! in_array($page_section, ['sizes', 'watermark'], true) and ! $this->pageState->hasErrors() and $this->accessControl->isWebmaster()) {
                foreach ($this->configService->getAllParamNames() as $param_name) {
                    if (isset($post[$param_name])) {
                        $post_value = $post[$param_name];
                        // `bool` alongside string/array: the per-tab
                        // checkbox loops above normalize to a real bool, and
                        // ConfigService::hydrate() reads a bool-typed
                        // CurrentConfig property back through
                        // `is_bool($decoded) ? $decoded : false` -- so a
                        // string here does not merely read oddly, it reads
                        // as false.
                        $value = is_string($post_value) || is_array($post_value) || is_bool($post_value) ? $post_value : '';

                        if ($param_name === 'gallery_title' && is_string($value)) {
                            if (! $this->currentConfig->allowHtmlDescriptions) {
                                $value = strip_tags($value);
                            }
                        }

                        $this->configService->confUpdateParam($param_name, $value);
                    }
                }
                $save_success = $this->lang->t('Your configuration settings are saved');

                $this->activityService->record('system', ActivitySystem::Core, 'config', [
                    'config_section' => $page['section'],
                ]);
            }

            $this->configService->loadConfFromDb();
        }

        // restore default derivatives settings
        if ($page['section'] === 'sizes' and $configurationRequest->restoreSettingsRequested and $this->accessControl->isWebmaster()) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $this->imageStdParams->restoreDefault();
            new DerivativeCacheService($this->currentConfig, $this->paths)
                ->clearDerivativeCache();

            // reset conf
            $this->configService->loadConfFromDb();

            $save_success = $this->lang->t('Your configuration settings are saved');

            $this->activityService->record('system', ActivitySystem::Core, 'config', [
                'config_section' => $page['section'],
                'config_action' => 'restore_settings',
            ]);
        }

        // CoreTabsContext's confLink must be set here (nothing else sets it
        // for this page) so CoreTabs::addCoreTabs() renders this page's
        // "General/Photo sizes/Watermark/Display/Comments/Search" tab strip
        // hrefs as admin.php?page=configuration&section=X instead of bare
        // relative paths.
        $this->coreTabs->setContext(new CoreTabsContext(confLink: $this->urlService->getRootUrl() . 'admin.php?page=configuration&section='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('configuration');
        $tabsheet->select($page_section, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate, $this->renderer);

        // Plain '&', not '&amp;': $action reaches every configuration_*.latte
        // as a bare {$fAction|noescape} print (P59 Batch 5 -- same idiom the
        // 5 named UrlService/PaginationService builders already fixed).
        $action = $this->urlService->getRootUrl() . 'admin.php?page=configuration';
        $action .= '&section=' . $page_section;

        $u_help = $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=configuration';
        $pwg_token = $this->csrfService
            ->getToken();
        $is_webmaster = $this->accessControl->isWebmaster() ? 1 : 0;

        $view = null;

        switch ($page['section']) {
            case 'main':

                // The form pre-selects from the same "<field> <dir>"
                // vocabulary $sort_fields is keyed by, which the order is
                // already structured as -- no need to take the rendered
                // SQL apart again.
                $order_by = $this->currentConfig->orderByInsideCategory->toSortFieldTokens();

                $conf_gallery_title = $this->currentConfig->galleryTitle;
                $conf_page_banner = $this->currentConfig->pageBanner;
                $conf_email_admin_on_new_user = $this->currentConfig->emailAdminOnNewUser;
                $lang_day = $this->lang->days();

                // list of groups
                $groups = [];
                foreach ($this->groupService->getAllBasic() as $group) {
                    $groups[$group->id->value] = $group->name;
                }
                natcasesort($groups);

                $main = new ConfigurationMainData(
                    confGalleryTitle: $conf_gallery_title,
                    confPageBanner: $conf_page_banner,
                    weekStartsOnOptions: [
                        'sunday' => $lang_day[0] ?? '',
                        'monday' => $lang_day[1] ?? '',
                    ],
                    weekStartsOnOptionsSelected: $this->currentConfig->weekStartsOn,
                    mailTheme: $this->currentConfig->mailTheme,
                    mailThemeOptions: $mail_themes,
                    orderBy: $order_by,
                    orderByOptions: $sort_fields,
                    emailAdminOnNewUser: $conf_email_admin_on_new_user !== 'none',
                    emailAdminOnNewUserFilter: in_array($conf_email_admin_on_new_user, ['none', 'all'], true) ? 'all' : 'group',
                    emailAdminOnNewUserFilterGroup: ((bool) preg_match('/^group:(\d+)$/', $conf_email_admin_on_new_user, $matches)) ? $matches[1] : -1,
                    allowUserRegistration: $this->currentConfig->allowUserRegistration,
                    obligatoryUserMailAddress: $this->currentConfig->obligatoryUserMailAddress,
                    rate: $this->currentConfig->rateEnabled,
                    rateAnonymous: $this->currentConfig->rateAnonymous,
                    allowUserCustomization: $this->currentConfig->allowUserCustomization,
                    log: $this->currentConfig->logConf,
                    historyAdmin: $this->currentConfig->historyAdmin,
                    historyGuest: $this->currentConfig->historyGuest,
                    showMobileAppBannerInGallery: $this->currentConfig->showMobileAppBannerInGallery,
                    showMobileAppBannerInAdmin: $this->currentConfig->showMobileAppBannerInAdmin,
                    uploadDetectDuplicate: $this->currentConfig->uploadDetectDuplicate,
                );

                $view = new ConfigurationMainView(
                    main: $main,
                    groupOptions: $groups,
                    fAction: $action,
                    saveSuccess: $save_success,
                    isWebmaster: $is_webmaster,
                    csrfToken: $pwg_token,
                );
                break;

            case 'comments':

                $comments = new ConfigurationCommentsData(
                    nbCommentsPage: $this->currentConfig->nbCommentPage,
                    commentsOrder: $this->currentConfig->commentsOrder,
                    commentsOrderOptions: $comments_order,
                    activateComments: $this->currentConfig->activateComments,
                    commentsForall: $this->currentConfig->commentsForall,
                    commentsValidation: $this->currentConfig->commentsValidation,
                    emailAdminOnComment: $this->currentConfig->emailAdminOnComment,
                    emailAdminOnCommentValidation: $this->currentConfig->emailAdminOnCommentValidation,
                    userCanDeleteComment: $this->currentConfig->userCanDeleteComment,
                    userCanEditComment: $this->currentConfig->userCanEditComment,
                    emailAdminOnCommentEdition: $this->currentConfig->emailAdminOnCommentEdition,
                    emailAdminOnCommentDeletion: $this->currentConfig->emailAdminOnCommentDeletion,
                    commentsAuthorMandatory: $this->currentConfig->commentsAuthorMandatory,
                    commentsEmailMandatory: $this->currentConfig->commentsEmailMandatory,
                    commentsEnableWebsite: $this->currentConfig->commentsEnableWebsite,
                );

                $view = new ConfigurationCommentsView(
                    comments: $comments,
                    fAction: $action,
                    saveSuccess: $save_success,
                    isWebmaster: $is_webmaster,
                    csrfToken: $pwg_token,
                );
                break;

            case 'default':

                $guest_id = $this->currentConfig->guestId;

                $edit_user = User::fromUserArray($this->userService->buildUser(UserId::from($guest_id)));
                $profileFormHandler = new ProfileFormHandler($this->lang, $this->redirectService, $this->adminContext, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->entityManager, $this->activityService, $this->userService, $this->passwordService, $this->authService, $this->htmlRenderer, $this->mailService, $this->currentConfig, $this->csrfService, $this->paths, new ConnectedWithSession());

                $errors = [];
                if ($profileFormHandler->saveFromPost($edit_user, $errors)) {
                    // Reload user
                    $edit_user = User::fromUserArray($this->userService->buildUser(UserId::from($guest_id)));
                    $this->pageState->addInfo($this->lang->t('Information data registered in database'));
                }
                $this->pageState->errors = array_merge($this->pageState->errors, array_values(array_filter($errors, is_string(...))));

                $guestFormData = $profileFormHandler->loadIntoTemplate($action, '', $edit_user);
                $view = new ConfigurationDefaultView(
                    username: $guestFormData->username,
                    activateComments: $guestFormData->activateComments,
                    nbImagePage: $guestFormData->nbImagePage,
                    recentPeriod: $guestFormData->recentPeriod,
                    expand: $guestFormData->expand,
                    nbComments: $guestFormData->nbComments,
                    nbHits: $guestFormData->nbHits,
                    redirect: $guestFormData->redirect,
                    guestFAction: $guestFormData->fAction,
                    radioOptions: $guestFormData->radioOptions,
                    csrfToken: $guestFormData->pwgToken,
                    isWebmaster: $is_webmaster,
                );
                break;

            case 'display':

                $display = new ConfigurationDisplayData(
                    menubarFilterIcon: $this->currentConfig->menubarFilterIcon,
                    indexSearchInSetButton: $this->currentConfig->indexSearchInSetButton,
                    indexSearchInSetAction: $this->currentConfig->indexSearchInSetAction,
                    indexSortOrderInput: $this->currentConfig->indexSortOrderInput,
                    indexFlatIcon: $this->currentConfig->indexFlatIcon,
                    indexPostedDateIcon: $this->currentConfig->indexPostedDateIcon,
                    indexCreatedDateIcon: $this->currentConfig->indexCreatedDateIcon,
                    indexSlideshowIcon: $this->currentConfig->indexSlideshowIcon,
                    indexSizesIcon: $this->currentConfig->indexSizesIcon,
                    indexNewIcon: $this->currentConfig->indexNewIcon,
                    indexEditIcon: $this->currentConfig->indexEditIcon,
                    indexCaddieIcon: $this->currentConfig->indexCaddieIcon,
                    displayFromto: $this->currentConfig->displayFromto,
                    pictureMetadataIcon: $this->currentConfig->pictureMetadataIcon,
                    pictureSlideshowIcon: $this->currentConfig->pictureSlideshowIcon,
                    pictureFavoriteIcon: $this->currentConfig->pictureFavoriteIcon,
                    pictureSizesIcon: $this->currentConfig->pictureSizesIcon,
                    pictureDownloadIcon: $this->currentConfig->pictureDownloadIcon,
                    pictureEditIcon: $this->currentConfig->pictureEditIcon,
                    pictureCaddieIcon: $this->currentConfig->pictureCaddieIcon,
                    pictureRepresentativeIcon: $this->currentConfig->pictureRepresentativeIcon,
                    pictureNavigationIcons: $this->currentConfig->pictureNavigationIcons,
                    pictureNavigationThumb: $this->currentConfig->pictureNavigationThumb,
                    pictureMenu: $this->currentConfig->pictureMenu,
                    pictureInformations: $this->currentConfig->pictureInformations,
                    nbCategoriesPage: $this->currentConfig->nbCategoriesPage,
                );

                $view = new ConfigurationDisplayView(
                    display: $display,
                    fAction: $action,
                    saveSuccess: $save_success,
                    isWebmaster: $is_webmaster,
                    csrfToken: $pwg_token,
                );
                break;

            case 'sizes':

                // we only load fresh derivatives if they weren't already
                // loaded: it occurs when submitting the form and an error
                // remains, in which case $sizesResult already carries the
                // (invalid) submitted values to redisplay.
                if (! $this->sizesLoadedInTpl) {
                    $is_gd = (ImageBackend::getLibrary() === 'gd') ? true : false;

                    $sizes = new ConfigurationSizesTabData(
                        originalResizeMaxwidth: $this->currentConfig->originalResizeMaxwidth,
                        originalResizeMaxheight: $this->currentConfig->originalResizeMaxheight,
                        originalResizeQuality: $this->currentConfig->originalResizeQuality,
                        originalResize: $this->currentConfig->originalResize,
                    );

                    // derivatives = multiple size
                    $enabled = $this->imageStdParams->getDefinedTypeMap();
                    $disabled = $this->imageStdParams->getDisabledTypeMap();

                    $tpl_vars = [];
                    foreach (ImageStdParams::getAllTypes() as $type) {
                        $tpl_var = [];

                        $tpl_var['must_square'] = ($type === ImageStdParams::SQUARE ? true : false);
                        $tpl_var['must_enable'] = ($type === ImageStdParams::SQUARE || $type === ImageStdParams::THUMB || $type === $this->currentConfig->derivativeDefaultSize) ? true : false;

                        if ((bool) ($params = $enabled[$type] ?? null)) {
                            $tpl_var['enabled'] = true;
                        } else {
                            $tpl_var['enabled'] = false;
                            $disabled_candidate = $disabled[$type] ?? null;
                            $params = $disabled_candidate instanceof DerivativeParams ? $disabled_candidate : null;
                        }

                        if ((bool) $params) {
                            $tpl_var['w'] = $params->sizing->ideal_size->width;
                            $tpl_var['h'] = $params->sizing->ideal_size->height;
                            $minSize = $params->sizing->min_size;
                            if (($tpl_var['crop'] = round(100.0 * (float) $params->sizing->max_crop)) > 0 && $minSize instanceof Dimensions) {
                                $tpl_var['minw'] = $minSize->width;
                                $tpl_var['minh'] = $minSize->height;
                            } else {
                                $tpl_var['minw'] = $tpl_var['minh'] = '';
                            }
                            $tpl_var['sharpen'] = $params->sharpen;
                        }
                        $tpl_vars[$type] = $tpl_var;
                    }

                    $derivatives = [];
                    foreach ($tpl_vars as $type => $tpl_var) {
                        // 'w'/'h'/'crop'/'sharpen' are only set on the
                        // branch above where $params resolved; a type in
                        // neither the enabled nor the disabled map leaves
                        // them absent, which the template used to read
                        // straight through.
                        $derivatives[$type] = new DerivativeSizeRow(
                            mustSquare: $tpl_var['must_square'],
                            mustEnable: $tpl_var['must_enable'],
                            enabled: $tpl_var['enabled'],
                            cropped: ($tpl_var['crop'] ?? 0) > 0,
                            width: $tpl_var['w'] ?? null,
                            height: $tpl_var['h'] ?? null,
                            sharpen: $tpl_var['sharpen'] ?? null,
                        );
                    }
                    $resize_quality = $this->imageStdParams->getQuality();

                    $view = new ConfigurationSizesView(
                        isGd: $is_gd,
                        sizes: $sizes,
                        derivatives: $derivatives,
                        resizeQuality: $resize_quality,
                        ferrors: null,
                        fAction: $action,
                        saveSuccess: ($sizesResult !== null ? $sizesResult->saveSuccess : null) ?? $save_success,
                        isWebmaster: $is_webmaster,
                        csrfToken: $pwg_token,
                    );
                } else {
                    // $sizesLoadedInTpl is only ever flipped true inside
                    // processSizes()'s own validation-failure branch, which
                    // is the same branch that populates all three of these
                    // -- so none can be null here. Checked rather than
                    // asserted: this build runs with zend.assertions=-1, so
                    // the assert() that used to stand here was a no-op and
                    // proved nothing to anyone, reader or analyser.
                    if ($sizesResult === null || $sizesResult->sizes === null || $sizesResult->derivatives === null) {
                        throw new LogicException('processSizes() set sizesLoadedInTpl without populating its result');
                    }

                    $view = new ConfigurationSizesView(
                        isGd: null,
                        sizes: $sizesResult->sizes,
                        derivatives: $sizesResult->derivatives,
                        resizeQuality: $sizesResult->resizeQuality,
                        ferrors: $sizesResult->ferrors,
                        fAction: $action,
                        saveSuccess: $sizesResult->saveSuccess ?? $save_success,
                        isWebmaster: $is_webmaster,
                        csrfToken: $pwg_token,
                    );
                }

                break;

            case 'watermark':

                $paths = $this->paths;
                $watermark_files = [];
                if (($glob = glob($paths->root . 'themes/default/watermarks/*.png')) !== false) {
                    foreach ($glob as $file) {
                        $watermark_files[] = substr($file, strlen($paths->root));
                    }
                }
                if (($glob = glob($paths->siteLocal . 'watermarks/*.png')) !== false) {
                    foreach ($glob as $file) {
                        $watermark_files[] = substr($file, strlen($paths->root));
                    }
                }
                $watermark_filemap = [
                    '' => '---',
                ];
                foreach ($watermark_files as $file) {
                    $display = basename($file);
                    $watermark_filemap[$file] = $display;
                }
                $watermark = null;
                if (! $this->watermarkLoadedInTpl) {
                    $wm = $this->imageStdParams->getWatermark();

                    $position = 'custom';
                    if ($wm->xpos === 0 and $wm->ypos === 0) {
                        $position = 'topleft';
                    }
                    if ($wm->xpos === 100 and $wm->ypos === 0) {
                        $position = 'topright';
                    }
                    if ($wm->xpos === 50 and $wm->ypos === 50) {
                        $position = 'middle';
                    }
                    if ($wm->xpos === 0 and $wm->ypos === 100) {
                        $position = 'bottomleft';
                    }
                    if ($wm->xpos === 100 and $wm->ypos === 100) {
                        $position = 'bottomright';
                    }

                    if ($wm->xrepeat !== 0 || $wm->yrepeat !== 0) {
                        $position = 'custom';
                    }

                    $watermark = new WatermarkFormValues(
                        file: $wm->file,
                        minw: $wm->min_size[0],
                        minh: $wm->min_size[1],
                        xpos: $wm->xpos,
                        ypos: $wm->ypos,
                        xrepeat: $wm->xrepeat,
                        yrepeat: $wm->yrepeat,
                        opacity: $wm->opacity,
                        position: $position,
                    )->toArray();
                }

                // $watermarkResult and its own ->watermark field are both
                // guaranteed non-null when $watermark stayed null here:
                // $watermarkLoadedInTpl is only ever flipped true inside
                // processWatermark()'s own validation-failure branch, which
                // is the same branch that populates $watermarkResult->watermark.
                if ($watermark === null) {
                    assert($watermarkResult !== null && $watermarkResult->watermark !== null);
                    $watermark = $watermarkResult->watermark;
                }

                $view = new ConfigurationWatermarkView(
                    watermarkFiles: $watermark_filemap,
                    watermark: $watermark,
                    ferrors: $watermarkResult?->ferrors,
                    fAction: $action,
                    saveSuccess: ($watermarkResult !== null ? $watermarkResult->saveSuccess : null) ?? $save_success,
                    isWebmaster: $is_webmaster,
                    csrfToken: $pwg_token,
                    rootUrl: $this->urlService->getRootUrl(),
                );

                break;

            case 'search':

                $search = new ConfigurationSearchTabData(
                    filtersViews: $this->currentConfig->filtersViews->filters ?? $this->currentConfig->defaultFiltersViews,
                    lastFiltersConf: $this->currentConfig->filtersViews->lastFiltersConf ?? false,
                    filtersNames: $filters_names_checkboxes,
                );

                $view = new ConfigurationSearchView(
                    search: $search,
                    showFilterRatings: $this->currentConfig->rateEnabled,
                    fAction: $action,
                    saveSuccess: $save_success,
                    isWebmaster: $is_webmaster,
                    csrfToken: $pwg_token,
                );

        }

        assert($view instanceof View);
        $adminContent = $this->renderer->render($view);

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Configuration'),
            helpUrl: $u_help,
        );
    }

    /**
     * The "sizes" tab's POST handler. This method's own is_webmaster()
     * check is the *only* thing gating this tab's write: the generic
     * config-row UPDATE loop in handle() itself explicitly excludes
     * 'sizes'/'watermark' from its own is_webmaster() check.
     *
     * @param array<int|string, mixed> $post handle()'s own local post
     *   working copy (see Request\ConfigurationRequest) -- read-only here,
     *   this tab persists through its own ImageStdParams::setAndSave()/
     *   setAndSaveDisabled() calls rather than the generic config-row
     *   UPDATE loop, so the only thing that flows back out is the
     *   save-success/validation-failure result handle() needs to build the
     *   final ConfigurationSizesView.
     */
    private function processSizes(array $post): ?ConfigurationSizesResult
    {
        if (! $this->accessControl->isWebmaster()) {
            return null;
        }

        $errors = [];

        // original resize
        $original_fields = [
            'original_resize',
            'original_resize_maxwidth',
            'original_resize_maxheight',
            'original_resize_quality',
        ];

        $updates = [];

        foreach ($original_fields as $field) {
            $value = ! self::emptyValue($post[$field] ?? null) ? $post[$field] : null;
            $updates[$field] = $value;
        }

        // saveUploadFormConfig()'s $errors shares PageState::$errors' own
        // element type (P59 Batch 6) -- round-trip through a local var and
        // re-index on the way back in (same pattern as
        // PluginLoader::autoupdatePlugin()).
        $page_errors = $this->pageState->errors;

        AdminAccessor::uploadService()
            ->saveUploadFormConfig($updates, $page_errors, $errors);

        $this->pageState->errors = array_values($page_errors);

        if ($post['resize_quality'] < 50 or $post['resize_quality'] > 98) {
            $errors['resize_quality'] = '[50..98]';
        }

        $pderivatives_post = $post['d'] ?? null;

        // The form posts a nested array keyed by derivative type, e.g.
        // d[square][w], d[square][enabled] — every leaf value therefore arrives as
        // a plain string. Anything else (missing key, or a tampered field name
        // producing a nested array where a scalar is expected) is dropped here so
        // the rest of this method can rely on a real, non-mixed shape instead of
        // bare-casting raw superglobal data at each point of use.
        /** @var array<string, array<string, string|int|bool|null>> $pderivatives */
        $pderivatives = [];
        if (is_array($pderivatives_post)) {
            foreach ($pderivatives_post as $ptype => $pfields) {
                if (! is_string($ptype) || ! is_array($pfields)) {
                    continue;
                }
                $normalized = [];
                foreach ($pfields as $pkey => $pvalue) {
                    if (is_string($pkey) && is_string($pvalue)) {
                        $normalized[$pkey] = $pvalue;
                    }
                }
                $pderivatives[$ptype] = $normalized;
            }
        }

        // step 1 - sanitize HTML input
        foreach ($pderivatives as $type => &$pderivative) {
            if ($pderivative['must_square'] = ($type === ImageStdParams::SQUARE ? true : false)) {
                $pderivative['h'] = $pderivative['w'];
                $pderivative['minh'] = $pderivative['minw'] = $pderivative['w'];
                $pderivative['crop'] = 100;
            }
            $pderivative['must_enable'] = ($type === ImageStdParams::SQUARE || $type === ImageStdParams::THUMB || $type === $this->currentConfig->derivativeDefaultSize) ? true : false;
            $pderivative['enabled'] = isset($pderivative['enabled']) || $pderivative['must_enable'] ? true : false;

            if (isset($pderivative['crop'])) {
                $pderivative['crop'] = 100;
                $pderivative['minw'] = $pderivative['w'];
                $pderivative['minh'] = $pderivative['h'] ?? null;
            } else {
                $pderivative['crop'] = 0;
                $pderivative['minw'] = null;
                $pderivative['minh'] = null;
            }
        }
        unset($pderivative);

        // step 2 - check validity
        //
        // $derivative_errors is kept separate from $errors (a flat field =>
        // message map) because it's a nested type => ['w' => message, ...] map;
        // the two are merged back together below for the 'ferrors' template
        // assignment
        /** @var array<string, array<string, string>> $derivative_errors */
        $derivative_errors = [];
        $prev_w = $prev_h = 0;
        foreach (ImageStdParams::getAllTypes() as $type) {
            $pderivative = $pderivatives[$type];
            if (! $pderivative['enabled']) {
                continue;
            }

            if ($type === ImageStdParams::THUMB) {
                $w = intval($pderivative['w']);
                if ($w <= 0) {
                    $derivative_errors[$type]['w'] = '>0';
                }

                $h = intval($pderivative['h']);
                if ($h <= 0) {
                    $derivative_errors[$type]['h'] = '>0';
                }

                if (max($w, $h) <= $prev_w) {
                    $derivative_errors[$type]['w'] = $derivative_errors[$type]['h'] = '>' . $prev_w;
                }
            } else {
                $v = intval($pderivative['w']);
                if ($v <= 0 or $v <= $prev_w) {
                    $derivative_errors[$type]['w'] = '>' . $prev_w;
                }

                $v = intval($pderivative['h']);
                if ($v <= 0 or $v <= $prev_h) {
                    $derivative_errors[$type]['h'] = '>' . $prev_h;
                }
            }

            if (count($errors) === 0 && count($derivative_errors) === 0) {
                $prev_w = intval($pderivative['w']);
                $prev_h = intval($pderivative['h']);
            }

            $v = intval($pderivative['sharpen']);
            if ($v < 0 || $v > 100) {
                $derivative_errors[$type]['sharpen'] = '[0..100]';
            }
        }

        // step 3 - save data
        if (count($errors) === 0 && count($derivative_errors) === 0) {
            $resize_quality_post = $post['resize_quality'];
            $resize_quality = is_numeric($resize_quality_post) ? intval($resize_quality_post) : 0;
            $quality_changed = $this->imageStdParams->getQuality() !== $resize_quality;
            $this->imageStdParams->setQuality($resize_quality);

            $enabled = $this->imageStdParams->getDefinedTypeMap();
            $disabled = $this->imageStdParams->getDisabledTypeMap();
            $changed_types = [];

            foreach (ImageStdParams::getAllTypes() as $type) {
                $pderivative = $pderivatives[$type];

                if ($pderivative['enabled']) {
                    $new_params = new DerivativeParams(
                        new SizingParams(
                            new Dimensions(intval($pderivative['w']), intval($pderivative['h'])),
                            round(intval($pderivative['crop']) / 100, 2),
                            new Dimensions(intval($pderivative['minw']), intval($pderivative['minh']))
                        )
                    );
                    $new_params->sharpen = (float) intval($pderivative['sharpen']);

                    $this->imageStdParams->applyGlobal($new_params);

                    if (isset($enabled[$type])) {
                        $old_params = $enabled[$type];
                        $same = true;
                        if (! DerivativeUrlCodec::sizeEquals($old_params->sizing->ideal_size, $new_params->sizing->ideal_size)
                            or $old_params->sizing->max_crop !== $new_params->sizing->max_crop) {
                            $same = false;
                        }

                        if ($same
                            and $new_params->sizing->max_crop !== 0.0
                            and ($old_params->sizing->min_size === null
                                || $new_params->sizing->min_size === null
                                || ! DerivativeUrlCodec::sizeEquals($old_params->sizing->min_size, $new_params->sizing->min_size))) {
                            $same = false;
                        }

                        if ($quality_changed
                            || $new_params->sharpen !== $old_params->sharpen) {
                            $same = false;
                        }

                        if (! $same) {
                            $new_params->last_mod_time = time();
                            $changed_types[] = $type;
                        } else {
                            $new_params->last_mod_time = $old_params->last_mod_time;
                        }
                        $enabled[$type] = $new_params;
                    } else {// now enabled, before was disabled
                        $enabled[$type] = $new_params;
                        unset($disabled[$type]);
                    }
                } else {// disabled
                    if (isset($enabled[$type])) {// now disabled, before was enabled
                        $changed_types[] = $type;
                        $disabled[$type] = $enabled[$type];
                        unset($enabled[$type]);
                    }
                }
            }

            $enabled_by = []; // keys ordered by all types
            foreach (ImageStdParams::getAllTypes() as $type) {
                if (isset($enabled[$type])) {
                    $enabled_by[$type] = $enabled[$type];
                }
            }

            foreach (array_keys($this->imageStdParams->getCustomTimestamps()) as $custom) {
                if (isset($post['delete_custom_derivative_' . $custom])) {
                    $changed_types[] = $custom;
                    $this->imageStdParams->unsetCustomTimestamp($custom);
                }
            }

            $this->imageStdParams->setAndSave($enabled_by);
            $this->imageStdParams->setAndSaveDisabled($disabled);

            if ((bool) count($changed_types)) {
                new DerivativeCacheService($this->currentConfig, $this->paths)
                    ->clearDerivativeCache($changed_types);
            }

            $this->activityService->record('system', ActivitySystem::Core, 'config', [
                'config_section' => 'sizes',
            ]);

            return new ConfigurationSizesResult(
                saveSuccess: $this->lang->t('Your configuration settings are saved'),
                derivatives: null,
                ferrors: null,
                resizeQuality: null,
                sizes: null,
            );
        }

        // Echoed back exactly as typed, invalid values included -- that is
        // the point of a validation-failure redisplay. strip_tags() prevents
        // an XSS attempt from reaching the value= attribute.
        $raw = [];
        foreach ($original_fields as $field) {
            if (isset($post[$field]) && is_string($post[$field])) {
                $raw[$field] = strip_tags($post[$field]);
            }
        }

        // Always constructed, never null. A validation failure whose POST
        // carried none of the four original_* fields -- which is every
        // failure raised by the derivative table alone -- used to leave
        // this null while $sizesLoadedInTpl said the tab was populated, and
        // configuration_sizes.latte reads $sizes->originalResize* unguarded
        // in six places. PHP 8 makes a property read on null a warning
        // yielding null rather than a fatal, so the page rendered with four
        // empty original-resize inputs and four warnings instead. Every
        // field is independently nullable already, so an all-null instance
        // renders exactly what the null did, minus the warnings (P58-A's
        // §3).
        $sizes = new ConfigurationSizesTabData(
            originalResizeMaxwidth: $raw['original_resize_maxwidth'] ?? null,
            originalResizeMaxheight: $raw['original_resize_maxheight'] ?? null,
            originalResizeQuality: $raw['original_resize_quality'] ?? null,
            // Presence is the whole value of a checkbox POST; $raw only
            // ever holds strings, so the key is there iff it was ticked.
            originalResize: isset($raw['original_resize']),
        );

        $this->sizesLoadedInTpl = true;

        // The same conversion the fresh-render path does, at the other
        // place a derivative set reaches configuration_sizes.latte. Values
        // here are the submitted strings, echoed back verbatim -- the rows
        // $pderivatives holds are what validation ran against, and they
        // stay arrays for it.
        $redisplay_derivatives = [];
        foreach ($pderivatives as $ptype => $prow) {
            $pwidth = $prow['w'] ?? null;
            $pheight = $prow['h'] ?? null;
            $psharpen = $prow['sharpen'] ?? null;

            $redisplay_derivatives[$ptype] = new DerivativeSizeRow(
                mustSquare: $prow['must_square'],
                mustEnable: $prow['must_enable'],
                enabled: $prow['enabled'],
                cropped: $prow['crop'] > 0,
                width: is_bool($pwidth) ? null : $pwidth,
                height: is_bool($pheight) ? null : $pheight,
                sharpen: is_bool($psharpen) ? null : $psharpen,
            );
        }

        // Two differently-shaped maps that used to be added together and
        // told apart by which key the template reached for (P58-A's §3).
        $byType = [];
        foreach ($derivative_errors as $errorType => $typeErrors) {
            $byType[$errorType] = new DerivativeSizeErrors(
                width: $typeErrors['w'] ?? null,
                height: $typeErrors['h'] ?? null,
                sharpen: $typeErrors['sharpen'] ?? null,
            );
        }

        return new ConfigurationSizesResult(
            saveSuccess: null,
            derivatives: $redisplay_derivatives,
            ferrors: new SizesFormErrors(
                originalResizeMaxwidth: $errors['original_resize_maxwidth'] ?? null,
                originalResizeMaxheight: $errors['original_resize_maxheight'] ?? null,
                originalResizeQuality: $errors['original_resize_quality'] ?? null,
                resizeQuality: $errors['resize_quality'] ?? null,
                byType: $byType,
            ),
            resizeQuality: is_string($post['resize_quality']) ? $post['resize_quality'] : null,
            sizes: $sizes,
        );
    }

    /**
     * The "watermark" tab's POST handler. Same is_webmaster()-is-the-only-
     * gate shape as processSizes() above.
     *
     * @param array<int|string, mixed> $post handle()'s own local post
     *   working copy -- see processSizes()'s own docblock.
     * @param array<int|string, mixed> $files raw $_FILES bag (see
     *   Request\ConfigurationRequest), for the watermarkImage upload below.
     */
    private function processWatermark(array $post, array $files): ?ConfigurationWatermarkResult
    {
        if (! $this->accessControl->isWebmaster()) {
            return null;
        }

        $errors = [];
        $pwatermark_post = $post['w'] ?? null;

        // The form posts a flat array w[key]=value (see configuration_watermark.latte)
        // where every leaf arrives as a plain string; normalize into a concrete
        // shape so the rest of this method can rely on real types instead of
        // bare-casting raw superglobal data at each point of use.
        /** @var array<string, string> $pwatermark */
        $pwatermark = [];
        if (is_array($pwatermark_post)) {
            foreach ($pwatermark_post as $pkey => $pvalue) {
                if (is_string($pkey) && is_string($pvalue)) {
                    $pwatermark[$pkey] = $pvalue;
                }
            }
        }

        // step 0 - manage upload if any
        $watermark_upload = $files['watermarkImage'] ?? null;
        $watermark_tmp_name = null;
        $watermark_upload_name = null;
        if (is_array($watermark_upload)) {
            if (isset($watermark_upload['tmp_name']) && is_string($watermark_upload['tmp_name'])) {
                $watermark_tmp_name = $watermark_upload['tmp_name'];
            }
            if (isset($watermark_upload['name']) && is_string($watermark_upload['name'])) {
                $watermark_upload_name = $watermark_upload['name'];
            }
        }

        if (! in_array($watermark_tmp_name, [null, ''], true)) {
            $image_size = getimagesize($watermark_tmp_name);
            $type = $image_size === false ? false : $image_size[2];
            if ($type !== IMAGETYPE_PNG) {
                $errors['watermarkImage'] = sprintf(
                    $this->lang->t('Allowed file types: %s.'),
                    'PNG'
                );
            } else {
                $paths = $this->paths;
                $upload_dir = $paths->siteLocal . 'watermarks';
                if (FilesystemHelper::mkgetdir($upload_dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
                    // file name may include exotic chars like single quote, we need a safe name
                    $new_name = StringHelper::str2url(StringHelper::getFilenameWoExtension($watermark_upload_name ?? ''));

                    // we need existing watermarks to avoid overwritting one
                    $watermark_files = [];
                    if (($glob = glob($paths->siteLocal . 'watermarks/*.png')) !== false) {
                        foreach ($glob as $file) {
                            $watermark_files[] = StringHelper::getFilenameWoExtension(substr($file, strlen($paths->siteLocal . 'watermarks/')));
                        }
                    }

                    $file_path = $upload_dir . '/' . self::getWatermarkFilename($watermark_files, $new_name);

                    // $upload_dir is exactly the 'watermarks' disk's own root, so
                    // the disk-relative path is just the filename.
                    $watermark_stream = fopen($watermark_tmp_name, 'rb');
                    if ($watermark_stream !== false) {
                        $this->storageRegistry->get('watermarks')
                            ->writeStream(basename($file_path), $watermark_stream);
                        fclose($watermark_stream);
                        $pwatermark['file'] = substr($file_path, strlen($paths->root));
                    } else {
                        // Left without a dedicated Browser test: by this point
                        // getimagesize($watermark_tmp_name) has already
                        // successfully read this exact file (the IMAGETYPE_PNG
                        // check above), which requires the same read access
                        // fopen('rb') needs here -- reaching this branch means
                        // the file vanished, or its permissions changed, in the
                        // narrow window between the two calls (e.g. an external
                        // tmp-file-cleanup process, a filesystem quota/race).
                        // That's a real defensive branch, not dead code, but a
                        // genuine race a black-box HTTP test can't deterministically
                        // force without mocking fopen() itself -- something this
                        // project's Browser suite doesn't do for the class under
                        // test (see this file's own "no mocks of the class under
                        // test" convention).
                        $this->pageState->addError($errors['watermarkImage'] = "{$file_path} " . $this->lang->t('no write access'));
                    }
                } else {
                    $this->pageState->addError($errors['watermarkImage'] = sprintf($this->lang->t('Add write access to the "%s" directory'), $upload_dir));
                }
            }
        }

        // step 1 - sanitize HTML input
        // $pwatermark is declared array<string, string> above; assign string
        // literals here (not int) so that promise holds for every key, not just
        // the ones read via intval() below -- an int write here would otherwise
        // widen PHPStan's inferred value type for the whole array to int|string.
        switch ($pwatermark['position']) {
            case 'topleft':

                $pwatermark['xpos'] = '0';
                $pwatermark['ypos'] = '0';
                break;

            case 'topright':

                $pwatermark['xpos'] = '100';
                $pwatermark['ypos'] = '0';
                break;

            case 'middle':

                $pwatermark['xpos'] = '50';
                $pwatermark['ypos'] = '50';
                break;

            case 'bottomleft':

                $pwatermark['xpos'] = '0';
                $pwatermark['ypos'] = '100';
                break;

            case 'bottomright':

                $pwatermark['xpos'] = '100';
                $pwatermark['ypos'] = '100';
                break;

        }

        // step 2 - check validity
        // Accumulate into a local array and only assign it into $errors['watermark']
        // if non-empty, matching this method's original auto-vivification behavior --
        // pre-creating $errors['watermark'] unconditionally would make count($errors)
        // never 0, permanently skipping "step 3 - save data" below. (xpos/ypos come
        // from raw user input when position=custom -- see configuration_watermark.latte
        // -- so out-of-range values are a real, reachable case, not dead code.)
        $watermark_errors = [];

        // Every field here is numeric, so a non-numeric submission is
        // invalid input rather than something to coerce: intval('abc') is
        // 0, which silently passed the xpos/ypos range checks and stored a
        // top-left position the user never asked for. Only `opacity`
        // caught it, and only because 0 is out of its range anyway.
        foreach (['xpos', 'ypos', 'opacity', 'minw', 'minh', 'xrepeat', 'yrepeat'] as $numericField) {
            if (! is_numeric($pwatermark[$numericField])) {
                $watermark_errors[$numericField] = self::WATERMARK_FIELD_RANGES[$numericField];
            }
        }

        $v = intval($pwatermark['xpos']);
        if ($v < 0 or $v > 100) {
            $watermark_errors['xpos'] = '[0..100]';
        }

        $v = intval($pwatermark['ypos']);
        if ($v < 0 or $v > 100) {
            $watermark_errors['ypos'] = '[0..100]';
        }

        $v = intval($pwatermark['opacity']);
        if ($v <= 0 or $v > 100) {
            $watermark_errors['opacity'] = '(0..100]';
        }

        // minw/minh are the pixel threshold DerivativeParams::willWatermark()
        // compares a derivative against (`$min_size[0] <= $out_size->width`),
        // so a negative one means the same as 0 -- watermark everything --
        // while reading as a deliberate setting. No upper bound is
        // derivable: a value above every configured size legitimately means
        // "never watermark", and those sizes are the admin's own.
        foreach (['minw', 'minh'] as $sizeField) {
            if (intval($pwatermark[$sizeField]) < 0) {
                $watermark_errors[$sizeField] = self::WATERMARK_FIELD_RANGES[$sizeField];
            }
        }

        // xrepeat/yrepeat drive `for ($i = -$r; $i <= $r; $i++)` in
        // ImageDerivativeController, nested one inside the other:
        //
        //  - a negative never enters the loop body, yet the enclosing
        //    `if ((bool) $wm->xrepeat || (bool) $wm->yrepeat)` is true for
        //    it, so the page promises repeats and draws none;
        //  - the pair costs (2x+1)(2y+1) iterations whatever the canvas can
        //    hold, so a large one hangs every derivative that is generated.
        //
        // The ceiling is 100 rather than a guess: a repeat only composes
        // when its stamp lands inside the canvas, at `x + $i * $xpad` with
        // $xpad at least 30px, which caps the useful |$i| near 66 on a
        // 2000px derivative. 100 clears that with room to spare and keeps
        // the worst case at 201*201 = 40,401 iterations, where 1000 would be
        // four million.
        foreach (['xrepeat', 'yrepeat'] as $repeatField) {
            $v = intval($pwatermark[$repeatField]);
            if ($v < 0 or $v > 100) {
                $watermark_errors[$repeatField] = self::WATERMARK_FIELD_RANGES[$repeatField];
            }
        }

        if ($watermark_errors !== []) {
            $errors['watermark'] = $watermark_errors;
        }

        // step 3 - save data
        if (count($errors) === 0) {
            $watermark = new WatermarkParams();
            $watermark->file = $pwatermark['file'];
            $watermark->xpos = intval($pwatermark['xpos']);
            $watermark->ypos = intval($pwatermark['ypos']);
            $watermark->xrepeat = intval($pwatermark['xrepeat']);
            $watermark->yrepeat = intval($pwatermark['yrepeat']);
            $watermark->opacity = intval($pwatermark['opacity']);
            $watermark->min_size = [intval($pwatermark['minw']), intval($pwatermark['minh'])];

            $old_watermark = $this->imageStdParams->getWatermark();
            $watermark_changed =
              $watermark->file !== $old_watermark->file
              || $watermark->xpos !== $old_watermark->xpos
              || $watermark->ypos !== $old_watermark->ypos
              || $watermark->xrepeat !== $old_watermark->xrepeat
              || $watermark->yrepeat !== $old_watermark->yrepeat
              || $watermark->opacity !== $old_watermark->opacity;

            // save the new watermark configuration
            $this->imageStdParams->setWatermark($watermark);

            // do we have to regenerate the derivatives (and which types)?
            $changed_types = [];

            foreach ($this->imageStdParams->getDefinedTypeMap() as $type => $params) {
                $old_use_watermark = $params->use_watermark;
                $this->imageStdParams->applyGlobal($params);

                $changed = $params->use_watermark !== $old_use_watermark;
                if (! $changed and $params->use_watermark) {
                    $changed = $watermark_changed;
                }
                if (! $changed and $params->use_watermark) {
                    // if thresholds change and before/after the threshold is lower than the corresponding derivative side -> some derivatives might switch the watermark
                    $changed = $watermark->min_size[0] !== $old_watermark->min_size[0]
                        || $watermark->min_size[1] !== $old_watermark->min_size[1];
                }

                if ($changed) {
                    $params->last_mod_time = time();
                    $changed_types[] = $type;
                }
            }

            $this->imageStdParams->save();

            if ((bool) count($changed_types)) {
                new DerivativeCacheService($this->currentConfig, $this->paths)
                    ->clearDerivativeCache($changed_types);
            }

            $this->activityService->record('system', ActivitySystem::Core, 'config', [
                'config_section' => 'watermark',
            ]);

            return new ConfigurationWatermarkResult(
                saveSuccess: $this->lang->t('Your configuration settings are saved'),
                watermark: null,
                ferrors: null,
            );
        }

        $this->watermarkLoadedInTpl = true;

        // Flattened for the template (P58-A's §3). $errors is a two-level
        // bag here -- a top-level 'watermarkImage' string beside a
        // 'watermark' sub-array -- and the template only ever read leaves,
        // so the nesting carried nothing.
        $watermarkImageError = $errors['watermarkImage'] ?? null;
        $watermarkFieldErrors = $errors['watermark'] ?? [];

        return new ConfigurationWatermarkResult(
            saveSuccess: null,
            watermark: $pwatermark,
            ferrors: new WatermarkFormErrors(
                watermarkImage: is_string($watermarkImageError) ? $watermarkImageError : null,
                xpos: $watermarkFieldErrors['xpos'] ?? null,
                ypos: $watermarkFieldErrors['ypos'] ?? null,
                opacity: $watermarkFieldErrors['opacity'] ?? null,
                minw: $watermarkFieldErrors['minw'] ?? null,
                minh: $watermarkFieldErrors['minh'] ?? null,
                xrepeat: $watermarkFieldErrors['xrepeat'] ?? null,
                yrepeat: $watermarkFieldErrors['yrepeat'] ?? null,
            ),
        );
    }

    /**
     * @param array<int, string> $list
     */
    private static function getWatermarkFilename(array $list, string $candidate, int $step = 0): string
    {
        $change_name = $candidate;
        if ($step !== 0) {
            $change_name .= '-' . $step;
        }
        if (in_array($change_name, $list, true)) {
            return self::getWatermarkFilename($list, $candidate, $step + 1);
        }
        return $change_name . '.png';
    }
}
