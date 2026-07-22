<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\ConfigService;
use Piwigo\Controller\ProfileFormHandler;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Image\WatermarkParams;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/configuration.php (page slug "configuration") -- a large
 * tabbed page (main/watermark/sizes/comments/default/display/search),
 * folded directly into this controller -- same shape as every prior P23
 * batch 6 sub-batch's shell folding. Its own `?section=` tab dispatch
 * stays inline (matches plugins.php/themes.php's own tab-dispatch shape,
 * confirmed too deeply tied to this single file's local $page['section']
 * switch to be worth splitting into 7 separate sub-controllers).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original file's own (redundant, same level) check_status() call
 * is dropped here -- same precedent as MaintenanceSubController/
 * IntroSubController.
 *
 * Real write paths verified during this batch: the "watermark"/"sizes"
 * tabs' POST handlers were originally admin/include/configuration_
 * {watermark,sizes}_process.inc.php, folded into processWatermark()/
 * processSizes() below in P23 sub-batch 8b-4 -- both already write
 * through typed abstractions (ImageStdParams::save()/set_and_save(),
 * UploadService::saveUploadFormConfig()) with no raw SQL. The "default" tab's
 * build_user()/ProfileFormHandler::saveFromPost() calls are the same
 * `Piwigo\Controller\ProfileFormHandler` (P23 batch 8c) pair
 * `ProfileController` (the standalone admin/profile.php page once shared
 * before P23 batch 6c deleted it as upstream-dead code -- bug:3122, 2014:
 * upstream folded "edit a user's profile" into this file's own "default"
 * tab years ago, never as a separate admin page).
 *
 * Phase 5 (Legacy Coupling Retirement): the generic config-row UPDATE
 * loop used to splice its value into raw, string-concatenated SQL
 * (manual str_replace("\'", "''", ...) escaping, not a parameterized
 * query) -- now routed through the injected ConfigService instead,
 * along with this file's other two $conf-reinit calls (formerly
 * ConfigDb::loadConfFromDb()) and its one filters_views default-seed
 * call (formerly ConfigDb::confUpdateParam()).
 *
 * This batch also fixed a real, verified bug in this file: $lang['day']
 * is never actually defined by any language/*\/common.lang.php (confirmed
 * across every locale) nor any runtime code, so the direct (unguarded)
 * read on the "main" tab threw "Undefined array key" -- fixed with the
 * same ?? guard already used for this exact key elsewhere (admin/intro.php,
 * \Piwigo\Core\DateHelper::formatDateLegacy()).
 *
 * P23 batch 6j-3 fixed a real, previously-uncaught CSRF gap: the "sizes"
 * tab's "Reset to default values" action (`?action=restore_settings`,
 * resets ImageStdParams to Piwigo's built-in defaults) had zero
 * check_pwg_token() *and* zero is_webmaster() gate, unlike every other
 * write path in this file (the main POST-save loop and both process
 * includes each check is_webmaster()). Fixed by gating the whole block on
 * is_webmaster() (matching the sibling process-includes' own shape) plus
 * check_pwg_token(); the template's own link now carries the token too
 * (see admin/themes/default/template/configuration_sizes.tpl).
 *
 * order_by_is_local() was a top-level function declared inside this
 * file's own 'main' case with zero external callers (confirmed via a
 * direct grep) -- folded into a private static method here. This is a
 * real correctness fix, not just style: once this switch lives inside a
 * reusable class method instead of a raw top-level include, a second
 * same-process call to handle() with section=main would otherwise fatal
 * with "Cannot redeclare function order_by_is_local()" -- the same risk
 * already converted away from by AlbumsPageRenderer/IntroSubController.
 */
final class ConfigurationSubController implements AdminSubControllerInterface
{
    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }

    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
    ) {}

    /**
     * Set by processSizes() (a static method) when a submitted "sizes" tab
     * form fails validation, so handle()'s own tab-render branch below
     * knows the template vars are already populated with the (invalid)
     * submitted values and skips overwriting them with fresh defaults --
     * shared across two static call sites of this same class, hence a
     * static property rather than a local variable.
     */
    private static bool $sizesLoadedInTpl = false;

    private static function activityService(Connection $conn): \Piwigo\Activity\ActivityService
    {
        return new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn));
    }

    private static function userService(Connection $conn): \Piwigo\Users\UserService
    {
        return new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository($conn), new \Piwigo\Group\GroupRepository($conn), new \Piwigo\Mail\MailService(), self::activityService($conn), new HtmlService(), $conn);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        // Phase 2 global-residual sweep: $page is a local scratch array
        // for this method's own body only (no longer `global $page;`),
        // same shape as Section\SectionPopulator::populate()'s own
        // equivalent fix (Track A5.2e).
        /** @var array<string, mixed> $page */
        $page = [];

        $conn = DbConnection::build();

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        // -------------------------------------------------------- sections definitions

        new \Piwigo\Validation\InputValidator()
            ->validate('section', $_GET, false, '/^[a-z]+$/i');

        // check_input_parameter() above fatal_error()s unless $_GET['section'] is a
        // scalar matching /^[a-z]+$/i, but that guarantee isn't visible to static
        // analysis; re-derive it into a definite string (query string values from
        // $_GET are always strings in practice, never int/float/bool).
        if (! isset($_GET['section']) or ! is_string($_GET['section'])) {
            $page_section = 'main';
        } else {
            $page_section = $_GET['section'];
        }
        $page['section'] = $page_section;

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

        $sizes_checkboxes = [
            'original_resize',
        ];

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

        if (! \Piwigo\Config\Config::has('filters_views')) {
            $this->configService->confUpdateParam('filters_views', \Piwigo\Config\Config::defaultFiltersViews(), true);
        }

        $filters_views_default = \Piwigo\Config\Config::filtersViews() ?? \Piwigo\Config\Config::defaultFiltersViews();
        $filters_names_checkboxes = array_values(array_diff(array_keys($filters_views_default), ['last_filters_conf']));

        // image order management
        $sort_fields = [
            '' => '',
            'file ASC' => Lang::t('File name, A &rarr; Z'),
            'file DESC' => Lang::t('File name, Z &rarr; A'),
            'name ASC' => Lang::t('Photo title, A &rarr; Z'),
            'name DESC' => Lang::t('Photo title, Z &rarr; A'),
            'date_creation DESC' => Lang::t('Date created, new &rarr; old'),
            'date_creation ASC' => Lang::t('Date created, old &rarr; new'),
            'date_available DESC' => Lang::t('Date posted, new &rarr; old'),
            'date_available ASC' => Lang::t('Date posted, old &rarr; new'),
            'rating_score DESC' => Lang::t('Rating score, high &rarr; low'),
            'rating_score ASC' => Lang::t('Rating score, low &rarr; high'),
            'hit DESC' => Lang::t('Visits, high &rarr; low'),
            'hit ASC' => Lang::t('Visits, low &rarr; high'),
            'id ASC' => Lang::t('Numeric identifier, 1 &rarr; 9'),
            'id DESC' => Lang::t('Numeric identifier, 9 &rarr; 1'),
            '`rank` ASC' => Lang::t('Manual sort order'),
        ];

        $comments_order = [
            'ASC' => Lang::t('Show oldest comments first'),
            'DESC' => Lang::t('Show latest comments first'),
        ];

        $mail_themes = [
            'clear' => 'Clear',
            'dark' => 'Dark',
        ];

        // ------------------------------ verification and registration of modifications
        if (isset($_POST['submit'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService(), $this->redirectService);
            $int_pattern = '/^\d+$/';

            switch ($page['section']) {
                case 'main':

                    if (! \Piwigo\Config\Config::has('order_by_custom') and ! \Piwigo\Config\Config::has('order_by_inside_category_custom')) {
                        if (! self::emptyValue($_POST['order_by'] ?? null)) {
                            new \Piwigo\Validation\InputValidator()
                                ->validate('order_by', $_POST, true, '/^(' . implode('|', array_keys($sort_fields)) . ')$/');

                            // check_input_parameter() above fatal_error()s unless
                            // $_POST['order_by'] is an array of scalars matching
                            // $pattern, but that guarantee isn't visible to static
                            // analysis; re-derive it into a local, string-only copy
                            // (values from an HTTP request are always strings here).
                            $order_by_input = is_array($_POST['order_by']) ? array_filter($_POST['order_by'], is_string(...)) : [];

                            $used = [];
                            foreach ($order_by_input as $i => $val) {
                                if (self::emptyValue($val) or isset($used[$val])) {
                                    unset($order_by_input[$i]);
                                } else {
                                    $used[$val] = true;
                                }
                            }
                            if (! (bool) count($order_by_input)) {
                                \Piwigo\Core\PageState::current()->addError(Lang::t('No order field selected'));
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

                                $_POST['order_by'] = 'ORDER BY ' . implode(', ', $order_by);
                                $_POST['order_by_inside_category'] = 'ORDER BY ' . implode(', ', $order_by_inside_category);
                            }
                        } else {
                            \Piwigo\Core\PageState::current()->addError(Lang::t('No order field selected'));
                        }
                    }

                    if (self::emptyValue($_POST['email_admin_on_new_user'] ?? null)) {
                        $_POST['email_admin_on_new_user'] = 'none';
                    } elseif ($_POST['email_admin_on_new_user_filter'] === 'all') {
                        $_POST['email_admin_on_new_user'] = 'all';
                    } else {
                        if (self::emptyValue($_POST['email_admin_on_new_user_filter_group'] ?? null)) {
                            $_POST['email_admin_on_new_user'] = 'all';
                        } else {
                            $filter_group = $_POST['email_admin_on_new_user_filter_group'];
                            $_POST['email_admin_on_new_user'] = 'group:' . (is_string($filter_group) ? $filter_group : '');
                        }
                    }

                    foreach ($main_checkboxes as $checkbox) {
                        $_POST[$checkbox] = self::emptyValue($_POST[$checkbox] ?? null) ? 'false' : 'true';
                    }
                    break;

                case 'watermark':

                    self::processWatermark();
                    break;

                case 'sizes':

                    self::processSizes();
                    break;

                case 'comments':

                    // the number of comments per page must be an integer between 5 and 50
                    // included
                    $nb_comment_page = $_POST['nb_comment_page'] ?? null;
                    if (! (bool) preg_match($int_pattern, is_scalar($nb_comment_page) ? (string) $nb_comment_page : '')
                         or $_POST['nb_comment_page'] < 5
                         or $_POST['nb_comment_page'] > 50) {
                        \Piwigo\Core\PageState::current()->addError(Lang::t('The number of comments a page must be between 5 and 50 included.'));
                    }
                    foreach ($comments_checkboxes as $checkbox) {
                        $_POST[$checkbox] = self::emptyValue($_POST[$checkbox] ?? null) ? 'false' : 'true';
                    }
                    break;

                case 'default':

                    // Never go here
                    break;

                case 'display':

                    $nb_categories_page = $_POST['nb_categories_page'] ?? null;
                    if (! (bool) preg_match($int_pattern, is_scalar($nb_categories_page) ? (string) $nb_categories_page : '')
                          or $_POST['nb_categories_page'] < 4) {
                        \Piwigo\Core\PageState::current()->addError(Lang::t('The number of albums a page must be above 4.'));
                    }
                    foreach ($display_checkboxes as $checkbox) {
                        $_POST[$checkbox] = self::emptyValue($_POST[$checkbox] ?? null) ? 'false' : 'true';
                    }
                    $picture_informations = is_array($_POST['picture_informations'] ?? null) ? $_POST['picture_informations'] : [];
                    foreach ($display_info_checkboxes as $checkbox) {
                        $picture_informations[$checkbox] =
                          self::emptyValue($picture_informations[$checkbox] ?? null) ? false : true;
                    }
                    // Phase 5: no addslashes() -- that was only ever needed
                    // to survive the old raw-SQL write's quote-doubling
                    // round trip (see the generic save loop below), which
                    // incidentally stripped it back out on the way into the
                    // DB. The parameterized ConfigService write stores
                    // these bytes verbatim, so a plain serialize() is the
                    // correct byte-for-byte match to what
                    // Config::pictureInformations() unserializes back.
                    $_POST['picture_informations'] = serialize($picture_informations);
                    break;

                case 'search':

                    $filters_views_box = is_array($_POST['filters_views_box'] ?? null) ? $_POST['filters_views_box'] : [];
                    $filters_views_post = is_array($_POST['filters_views'] ?? null) ? $_POST['filters_views'] : [];

                    foreach ($filters_names_checkboxes as $checkbox) {
                        $filter_conf = is_array($filters_views_post[$checkbox] ?? null) ? $filters_views_post[$checkbox] : [];

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
                      self::emptyValue($filters_views_post['last_filters_conf'] ?? null) ? false : true;
                    // Phase 5: no addslashes() -- same reasoning as
                    // picture_informations above.
                    $_POST['filters_views'] = serialize($filters_views_post);

            }

            // updating configuration if no error found
            if (! in_array($page_section, ['sizes', 'watermark'], true) and ! \Piwigo\Core\PageState::current()->hasErrors() and \Piwigo\Auth\AccessControl::isWebmaster()) {
                // echo '<pre>'; print_r($_POST); echo '</pre>';
                foreach ($conn->fetchAllAssociative('SELECT param FROM ' . Tables::config()) as $row) {
                    if (! is_string($row['param'])) {
                        // `param` is the config table's NOT NULL primary key; a
                        // non-string row here would mean the query result changed
                        // shape, not a real config param to update.
                        continue;
                    }

                    if (isset($_POST[$row['param']])) {
                        $post_value = $_POST[$row['param']];
                        $value = is_scalar($post_value) ? (string) $post_value : '';

                        if ($row['param'] === 'gallery_title') {
                            if (! \Piwigo\Config\Config::allowHtmlDescriptions()) {
                                $value = strip_tags($value);
                            }
                        }

                        $this->configService->confUpdateParam($row['param'], $value);
                    }
                }
                $template->assign(
                    [
                        'save_success' => Lang::t('Your configuration settings are saved'),
                    ]
                );

                self::activityService($conn)->record('system', ActivitySystem::Core, 'config', [
                    'config_section' => $page['section'],
                ]);
            }

            // ------------------------------------------------------ $conf reinitialization
            $this->configService->loadConfFromDb();
        }

        // restore default derivatives settings
        if ($page['section'] === 'sizes' and isset($_GET['action']) and $_GET['action'] === 'restore_settings' and \Piwigo\Auth\AccessControl::isWebmaster()) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService(), $this->redirectService);

            ImageStdParams::restore_default();
            new DerivativeCacheService()
                ->clearDerivativeCache();

            // reset conf
            $this->configService->loadConfFromDb();

            $template->assign(
                [
                    'save_success' => Lang::t('Your configuration settings are saved'),
                ]
            );

            self::activityService($conn)->record('system', ActivitySystem::Core, 'config', [
                'config_section' => $page['section'],
                'config_action' => $_GET['action'],
            ]);
        }

        // ----------------------------------------------------- template initialization
        $template->set_filename('config', 'configuration_' . $page_section . '.tpl');

        // TabSheet
        //
        // Legacy Coupling Retirement Phase 8, 8g: real, previously-unfixed
        // bug found via a live Browser-suite failure once CoreTabs::
        // addCoreTabs()'s formerly-silent `global $conf_link;` null read
        // became a real, throwing CoreTabsContext field access -- nothing
        // had EVER called CoreTabs::setContext() with confLink for this
        // page (AdminShell's own same-named $conf_link is a genuinely
        // local variable in a different call frame, same class of bug as
        // IntroSubController's own $link_start fix above). This page's
        // own "General/Photo sizes/Watermark/Display/Comments/Search" tab
        // strip hrefs have always rendered as bare relative paths instead
        // of `admin.php?page=configuration&section=X`. Fixed here.
        CoreTabs::setContext(new CoreTabsContext(confLink: $this->urlService->getRootUrl() . 'admin.php?page=configuration&amp;section='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('configuration');
        $tabsheet->select($page_section);
        $tabsheet->assign();

        $action = $this->urlService->getRootUrl() . 'admin.php?page=configuration';
        $action .= '&amp;section=' . $page_section;

        $template->assign(
            [
                'U_HELP' => $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=configuration',
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
                'F_ACTION' => $action,
            ]
        );

        switch ($page['section']) {
            case 'main':

                if (self::orderByIsLocal()) {
                    \Piwigo\Core\PageState::current()->addWarning(Lang::t('You have specified <i>$conf[\'order_by\']</i> in your local configuration file, this parameter in deprecated, please remove it or rename it into <i>$conf[\'order_by_custom\']</i> !'));
                }

                if (\Piwigo\Config\Config::has('order_by_custom') or \Piwigo\Config\Config::has('order_by_inside_category_custom')) {
                    $order_by = [''];
                    $template->assign('ORDER_BY_IS_CUSTOM', true);
                } else {
                    $out = [];
                    $conf_order_by_inside_category = \Piwigo\Config\Config::all()['order_by_inside_category'] ?? null;
                    $order_by = trim(is_string($conf_order_by_inside_category) ? $conf_order_by_inside_category : '');
                    $order_by = str_replace('ORDER BY ', '', $order_by);
                    $order_by = explode(', ', $order_by);
                }

                $conf_gallery_title = \Piwigo\Config\Config::galleryTitle();
                $conf_page_banner = \Piwigo\Config\Config::pageBanner();
                $conf_email_admin_on_new_user = \Piwigo\Config\Config::emailAdminOnNewUser();
                $lang_day = \Piwigo\Core\Lang::days();

                $template->assign(
                    'main',
                    [
                        'CONF_GALLERY_TITLE' => htmlspecialchars($conf_gallery_title),
                        'CONF_PAGE_BANNER' => htmlspecialchars($conf_page_banner),
                        'week_starts_on_options' => [
                            'sunday' => $lang_day[0] ?? '',
                            'monday' => $lang_day[1] ?? '',
                        ],
                        'week_starts_on_options_selected' => \Piwigo\Config\Config::weekStartsOn(),
                        'mail_theme' => \Piwigo\Config\Config::mailTheme(),
                        'mail_theme_options' => $mail_themes,
                        'order_by' => $order_by,
                        'order_by_options' => $sort_fields,
                        'email_admin_on_new_user' => $conf_email_admin_on_new_user !== 'none',
                        'email_admin_on_new_user_filter' => in_array($conf_email_admin_on_new_user, ['none', 'all'], true) ? 'all' : 'group',
                        'email_admin_on_new_user_filter_group' => ((bool) preg_match('/^group:(\d+)$/', $conf_email_admin_on_new_user, $matches)) ? $matches[1] : -1,
                    ]
                );

                // list of groups
                $query = '
    SELECT
        id,
        name
      FROM `' . Tables::groups() . '`
    ;';
                // groups.name is a NOT NULL varchar column, so this is always
                // a real string; filter defensively rather than trust the
                // generic array_column() mixed value type.
                $groups = array_filter(array_column($conn->fetchAllAssociative($query), 'name', 'id'), is_string(...));
                natcasesort($groups);

                $template->assign(
                    [
                        'group_options' => $groups,
                    ]
                );

                foreach ($main_checkboxes as $checkbox) {
                    $template->append(
                        'main',
                        [
                            $checkbox => \Piwigo\Config\Config::all()[$checkbox] ?? null,
                        ],
                        true
                    );
                }
                break;

            case 'comments':

                $template->assign(
                    'comments',
                    [
                        'NB_COMMENTS_PAGE' => \Piwigo\Config\Config::nbCommentPage(),
                        'comments_order' => \Piwigo\Config\Config::commentsOrder(),
                        'comments_order_options' => $comments_order,
                    ]
                );

                foreach ($comments_checkboxes as $checkbox) {
                    $template->append(
                        'comments',
                        [
                            $checkbox => \Piwigo\Config\Config::all()[$checkbox] ?? null,
                        ],
                        true
                    );
                }
                break;

            case 'default':

                $guest_id = \Piwigo\Config\Config::guestId();

                $edit_user = self::userService($conn)->buildUser($guest_id, false);
                // P22: profile.php's own save_profile_from_post()/
                // load_profile_in_template() ported to Piwigo\Controller\
                // ProfileFormHandler in P23 batch 8c.
                $profileFormHandler = new ProfileFormHandler($this->redirectService);

                $errors = [];
                if ($profileFormHandler->saveFromPost($edit_user, $errors)) {
                    // Reload user
                    $edit_user = self::userService($conn)->buildUser($guest_id, false);
                    \Piwigo\Core\PageState::current()->addInfo(Lang::t('Information data registered in database'));
                }
                \Piwigo\Core\PageState::current()->errors = array_merge(\Piwigo\Core\PageState::current()->errors, array_values(array_filter($errors, is_string(...))));

                $profileFormHandler->loadIntoTemplate(
                    $action,
                    '',
                    $edit_user,
                    'GUEST_'
                );
                $template->assign('default', []);
                break;

            case 'display':

                foreach ($display_checkboxes as $checkbox) {
                    $template->append(
                        'display',
                        [
                            $checkbox => \Piwigo\Config\Config::all()[$checkbox] ?? null,
                        ],
                        true
                    );
                }
                $template->append(
                    'display',
                    [
                        'picture_informations' => \Piwigo\Config\Config::pictureInformations(),
                        'NB_CATEGORIES_PAGE' => \Piwigo\Config\Config::nbCategoriesPage(),
                    ],
                    true
                );
                break;

            case 'sizes':

                // we only load the derivatives if it was not already loaded: it occurs
                // when submitting the form and an error remains
                if (! self::$sizesLoadedInTpl) {
                    $is_gd = (PwgImage::get_library() === 'gd') ? true : false;
                    $template->assign('is_gd', $is_gd);
                    $template->assign(
                        'sizes',
                        [
                            'original_resize_maxwidth' => \Piwigo\Config\Config::originalResizeMaxwidth(),
                            'original_resize_maxheight' => \Piwigo\Config\Config::originalResizeMaxheight(),
                            'original_resize_quality' => \Piwigo\Config\Config::originalResizeQuality(),
                        ]
                    );

                    foreach ($sizes_checkboxes as $checkbox) {
                        $template->append(
                            'sizes',
                            [
                                $checkbox => \Piwigo\Config\Config::all()[$checkbox] ?? null,
                            ],
                            true
                        );
                    }

                    // derivatives = multiple size
                    $enabled = ImageStdParams::get_defined_type_map();
                    $disabled_unserialized = \Piwigo\Core\ArrayHelper::safeUnserialize(ImageStdParams::get_disabled_type_map());
                    $disabled = is_array($disabled_unserialized) ? $disabled_unserialized : [];

                    $tpl_vars = [];
                    foreach (ImageStdParams::get_all_types() as $type) {
                        $tpl_var = [];

                        $tpl_var['must_square'] = ($type === ImageStdParams::SQUARE ? true : false);
                        $tpl_var['must_enable'] = ($type === ImageStdParams::SQUARE || $type === ImageStdParams::THUMB || $type === \Piwigo\Config\Config::derivativeDefaultSize()) ? true : false;

                        if ((bool) ($params = $enabled[$type] ?? null)) {
                            $tpl_var['enabled'] = true;
                        } else {
                            $tpl_var['enabled'] = false;
                            $disabled_candidate = $disabled[$type] ?? null;
                            $params = $disabled_candidate instanceof DerivativeParams ? $disabled_candidate : null;
                        }

                        if ((bool) $params) {
                            [$tpl_var['w'], $tpl_var['h']] = $params->sizing->ideal_size;
                            if (($tpl_var['crop'] = round(100 * $params->sizing->max_crop)) > 0) {
                                [$tpl_var['minw'], $tpl_var['minh']] = $params->sizing->min_size;
                            } else {
                                $tpl_var['minw'] = $tpl_var['minh'] = '';
                            }
                            $tpl_var['sharpen'] = $params->sharpen;
                        }
                        $tpl_vars[$type] = $tpl_var;
                    }
                    $template->assign('derivatives', $tpl_vars);
                    $template->assign('resize_quality', ImageStdParams::$quality);

                    $tpl_vars = [];
                    $now = time();
                    foreach (ImageStdParams::$custom as $custom => $time) {
                        $tpl_vars[$custom] = ($now - $time <= 24 * 3600) ? Lang::t('today') : \Piwigo\Core\DateHelper::timeSince($time, 'day');
                    }
                    $template->assign('custom_derivatives', $tpl_vars);
                }

                break;

            case 'watermark':

                $paths = CurrentPaths::get();
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
                $template->assign('watermark_files', $watermark_filemap);

                if ($template->get_template_vars('watermark') === null) {
                    $wm = ImageStdParams::get_watermark();

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

                    $template->assign(
                        'watermark',
                        [
                            'file' => $wm->file,
                            'minw' => $wm->min_size[0],
                            'minh' => $wm->min_size[1],
                            'xpos' => $wm->xpos,
                            'ypos' => $wm->ypos,
                            'xrepeat' => $wm->xrepeat,
                            'yrepeat' => $wm->yrepeat,
                            'opacity' => $wm->opacity,
                            'position' => $position,
                        ]
                    );
                }

                break;

            case 'search':

                $template->assign(
                    'search',
                    [
                        'filters_views' => \Piwigo\Config\Config::filtersViews() ?? [],
                        'filters_names' => $filters_names_checkboxes,
                    ],
                );
                $template->assign('SHOW_FILTER_RATINGS', \Piwigo\Config\Config::rateEnabled());

        }

        $template->assign('isWebmaster', (\Piwigo\Auth\AccessControl::isWebmaster()) ? 1 : 0);
        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Configuration'));

        // ----------------------------------------------------------- sending html code
        $template->assign_var_from_handle('ADMIN_CONTENT', 'config');
    }

    /**
     * Whether $conf['order_by']/$conf['order_by_inside_category'] were set
     * by a site-owner-authored local config file (a deprecated pattern --
     * see the warning message this feeds).
     *
     * PHPStan can't see local/config/config.inc.php's content, so it can't
     * rule out either isset() genuinely being true -- no ignores needed
     * here (unlike before "nothing is frozen" gap-closure, 2026-07-22,
     * retired config_default.inc.php: PHPStan previously treated the whole
     * $conf array as unverifiable mixed from the raw include, which
     * produced different, now-stale error identifiers; Config::
     * defaultsArray() gives $conf a real, checkable type instead).
     */
    private static function orderByIsLocal(): bool
    {
        // Config::defaultsArray() never sets local_dir_site/order_by/
        // order_by_inside_category -- local_dir_site has no SCHEMA entry
        // at all, and order_by/order_by_inside_category are both
        // 'custom' => true (computed accessors, no plain literal default),
        // deliberately excluded from defaultsArray() (see its own
        // docblock). They only ever come from an optional, site-owner-
        // authored local/config/config.inc.php loaded at runtime, whose
        // content isn't knowable statically. Whether $conf ends up with
        // these keys genuinely depends on a file that may not exist and
        // isn't part of this codebase.
        $paths = CurrentPaths::get();
        $conf = \Piwigo\Config\Config::defaultsArray();
        @include $paths->local . 'config/config.inc.php';
        if (isset($conf['local_dir_site'])) {
            @include $paths->siteLocal . 'config/config.inc.php';
        }

        return isset($conf['order_by']) or isset($conf['order_by_inside_category']);
    }

    /**
     * Ported from admin/include/configuration_sizes_process.inc.php
     * (P23 sub-batch 8b-4) -- the "sizes" tab's POST handler. This
     * method's own is_webmaster() check is the *only* thing gating this
     * tab's write: the generic config-row UPDATE loop in handle() itself
     * explicitly excludes 'sizes'/'watermark' from its own is_webmaster()
     * check.
     */
    private static function processSizes(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            return;
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
            $value = ! self::emptyValue($_POST[$field] ?? null) ? $_POST[$field] : null;
            $updates[$field] = $value;
        }

        // saveUploadFormConfig()'s $errors is only known as array<int, string>,
        // narrower than PageState::$errors' list<string> -- round-trip through
        // a local var and re-index on the way back in (same pattern as
        // PluginLoader::autoupdatePlugin()).
        $page_errors = \Piwigo\Core\PageState::current()->errors;

        new UploadService()
            ->saveUploadFormConfig($updates, $page_errors, $errors);

        \Piwigo\Core\PageState::current()->errors = array_values($page_errors);

        if ($_POST['resize_quality'] < 50 or $_POST['resize_quality'] > 98) {
            $errors['resize_quality'] = '[50..98]';
        }

        $pderivatives_post = $_POST['d'] ?? null;

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
            $pderivative['must_enable'] = ($type === ImageStdParams::SQUARE || $type === ImageStdParams::THUMB || $type === \Piwigo\Config\Config::derivativeDefaultSize()) ? true : false;
            $pderivative['enabled'] = isset($pderivative['enabled']) || $pderivative['must_enable'] ? true : false;

            if (isset($pderivative['crop'])) {
                $pderivative['crop'] = 100;
                $pderivative['minw'] = $pderivative['w'];
                $pderivative['minh'] = $pderivative['h'];
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
        foreach (ImageStdParams::get_all_types() as $type) {
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
            $resize_quality_post = $_POST['resize_quality'] ?? null;
            $resize_quality = is_numeric($resize_quality_post) ? intval($resize_quality_post) : 0;
            $quality_changed = ImageStdParams::$quality !== $resize_quality;
            ImageStdParams::$quality = $resize_quality;

            $enabled = ImageStdParams::get_defined_type_map();
            $disabled_raw = \Piwigo\Core\ArrayHelper::safeUnserialize(ImageStdParams::get_disabled_type_map());
            // ImageStdParams persists this map as serialize()d DerivativeParams[]
            // (see ImageStdParams::save_disabled()); unserialize() is only typed
            // mixed by PHP itself, so filter out anything that isn't actually a
            // DerivativeParams instance rather than trusting the blob blindly.
            /** @var array<string, DerivativeParams> $disabled */
            $disabled = [];
            if (is_array($disabled_raw)) {
                foreach ($disabled_raw as $disabled_type => $disabled_params) {
                    if (is_string($disabled_type) && $disabled_params instanceof DerivativeParams) {
                        $disabled[$disabled_type] = $disabled_params;
                    }
                }
            }
            $changed_types = [];

            foreach (ImageStdParams::get_all_types() as $type) {
                $pderivative = $pderivatives[$type];

                if ($pderivative['enabled']) {
                    $new_params = new DerivativeParams(
                        new SizingParams(
                            [intval($pderivative['w']), intval($pderivative['h'])],
                            round(intval($pderivative['crop']) / 100, 2),
                            [intval($pderivative['minw']), intval($pderivative['minh'])]
                        )
                    );
                    $new_params->sharpen = intval($pderivative['sharpen']);

                    ImageStdParams::apply_global($new_params);

                    if (isset($enabled[$type])) {
                        $old_params = $enabled[$type];
                        $same = true;
                        if (! DerivativeUrlCodec::sizeEquals($old_params->sizing->ideal_size, $new_params->sizing->ideal_size)
                            or $old_params->sizing->max_crop !== $new_params->sizing->max_crop) {
                            $same = false;
                        }

                        if ($same
                            and $new_params->sizing->max_crop !== 0.0
                            and ! DerivativeUrlCodec::sizeEquals($old_params->sizing->min_size, $new_params->sizing->min_size)) {
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
            foreach (ImageStdParams::get_all_types() as $type) {
                if (isset($enabled[$type])) {
                    $enabled_by[$type] = $enabled[$type];
                }
            }

            foreach (array_keys(ImageStdParams::$custom) as $custom) {
                if (isset($_POST['delete_custom_derivative_' . $custom])) {
                    $changed_types[] = $custom;
                    unset(ImageStdParams::$custom[$custom]);
                }
            }

            ImageStdParams::set_and_save($enabled_by);
            ImageStdParams::set_and_save_disabled($disabled);

            if ((bool) count($changed_types)) {
                new DerivativeCacheService()
                    ->clearDerivativeCache($changed_types);
            }

            $template->assign(
                [
                    'save_success' => Lang::t('Your configuration settings are saved'),
                ]
            );

            self::activityService(DbConnection::build())->record('system', ActivitySystem::Core, 'config', [
                'config_section' => 'sizes',
            ]);
        } else {
            foreach ($original_fields as $field) {
                if (isset($_POST[$field]) && is_string($_POST[$field])) {
                    $template->append(
                        'sizes',
                        [
                            $field => strip_tags($_POST[$field]), // strip_tags prevents from XSS attempt
                        ],
                        true
                    );
                }
            }

            $template->assign('derivatives', $pderivatives);
            $template->assign('ferrors', $errors + $derivative_errors);
            $template->assign('resize_quality', $_POST['resize_quality']);
            self::$sizesLoadedInTpl = true;
        }
    }

    /**
     * Ported from admin/include/configuration_watermark_process.inc.php
     * (P23 sub-batch 8b-4) -- the "watermark" tab's POST handler. Same
     * is_webmaster()-is-the-only-gate shape as processSizes() above.
     */
    private static function processWatermark(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            return;
        }

        $errors = [];
        $pwatermark_post = $_POST['w'] ?? null;

        // The form posts a flat array w[key]=value (see configuration_watermark.tpl)
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
        $watermark_upload = $_FILES['watermarkImage'] ?? null;
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
                    Lang::t('Allowed file types: %s.'),
                    'PNG'
                );
            } else {
                $paths = CurrentPaths::get();
                $upload_dir = $paths->siteLocal . 'watermarks';
                if (\Piwigo\Core\FilesystemHelper::mkgetdir($upload_dir, \Piwigo\Core\FilesystemHelper::MKGETDIR_DEFAULT & ~\Piwigo\Core\FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
                    // file name may include exotic chars like single quote, we need a safe name
                    $new_name = \Piwigo\Core\StringHelper::str2url(\Piwigo\Core\StringHelper::getFilenameWoExtension($watermark_upload_name ?? ''));

                    // we need existing watermarks to avoid overwritting one
                    $watermark_files = [];
                    if (($glob = glob($paths->siteLocal . 'watermarks/*.png')) !== false) {
                        foreach ($glob as $file) {
                            $watermark_files[] = \Piwigo\Core\StringHelper::getFilenameWoExtension(substr($file, strlen($paths->siteLocal . 'watermarks/')));
                        }
                    }

                    $file_path = $upload_dir . '/' . self::getWatermarkFilename($watermark_files, $new_name);

                    // $upload_dir is exactly the 'watermarks' disk's own root, so
                    // the disk-relative path is just the filename.
                    $watermark_stream = fopen($watermark_tmp_name, 'rb');
                    if ($watermark_stream !== false) {
                        StorageRegistry::disk('watermarks')->writeStream(basename($file_path), $watermark_stream);
                        fclose($watermark_stream);
                        $pwatermark['file'] = substr($file_path, strlen($paths->root));
                    } else {
                        \Piwigo\Core\PageState::current()->addError($errors['watermarkImage'] = "{$file_path} " . Lang::t('no write access'));
                    }
                } else {
                    \Piwigo\Core\PageState::current()->addError($errors['watermarkImage'] = sprintf(Lang::t('Add write access to the "%s" directory'), $upload_dir));
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
        // from raw user input when position=custom -- see configuration_watermark.tpl
        // -- so out-of-range values are a real, reachable case, not dead code.)
        $watermark_errors = [];
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

            $old_watermark = ImageStdParams::get_watermark();
            $watermark_changed =
              $watermark->file !== $old_watermark->file
              || $watermark->xpos !== $old_watermark->xpos
              || $watermark->ypos !== $old_watermark->ypos
              || $watermark->xrepeat !== $old_watermark->xrepeat
              || $watermark->yrepeat !== $old_watermark->yrepeat
              || $watermark->opacity !== $old_watermark->opacity;

            // save the new watermark configuration
            ImageStdParams::set_watermark($watermark);

            // do we have to regenerate the derivatives (and which types)?
            $changed_types = [];

            foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
                $old_use_watermark = $params->use_watermark;
                ImageStdParams::apply_global($params);

                $changed = $params->use_watermark !== $old_use_watermark;
                if (! $changed and $params->use_watermark) {
                    $changed = $watermark_changed;
                }
                if (! $changed and $params->use_watermark) {
                    // if thresholds change and before/after the threshold is lower than the corresponding derivative side -> some derivatives might switch the watermark
                    (bool) ($changed |= $watermark->min_size[0] !== $old_watermark->min_size[0]) and ($watermark->min_size[0] < $params->max_width() or $old_watermark->min_size[0] < $params->max_width());
                    (bool) ($changed |= $watermark->min_size[1] !== $old_watermark->min_size[1]) and ($watermark->min_size[1] < $params->max_height() or $old_watermark->min_size[1] < $params->max_height());
                }

                if ((bool) $changed) {
                    $params->last_mod_time = time();
                    $changed_types[] = $type;
                }
            }

            ImageStdParams::save();

            if ((bool) count($changed_types)) {
                new DerivativeCacheService()
                    ->clearDerivativeCache($changed_types);
            }

            $template->assign(
                [
                    'save_success' => Lang::t('Your configuration settings are saved'),
                ]
            );

            self::activityService(DbConnection::build())->record('system', ActivitySystem::Core, 'config', [
                'config_section' => 'watermark',
            ]);
        } else {
            $template->assign('watermark', $pwatermark);
            $template->assign('ferrors', $errors);
        }
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
