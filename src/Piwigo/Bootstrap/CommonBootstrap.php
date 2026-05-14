<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Admin\UpgradeService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\PersistentCacheRegistry;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\LangService;
use Piwigo\Menu\BlockManager;
use Piwigo\Migrations\MigrationRunner;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Plugin\PluginService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserBootstrap;
use Piwigo\Users\UserService;

final class CommonBootstrap
{
    /** Returns a global by key as mixed, preventing PHPStan type narrowing from earlier assignments. */
    private static function readGlobal(string $key): mixed
    {
        return $GLOBALS[$key] ?? null;
    }

    private static function sanitizeMysqlKv(mixed &$v, string $k): void
    {
        $v = addslashes(is_scalar($v) ? (string) $v : '');
    }

    public static function run(): void
    {
        ExceptionHandler::register();

        // Determine the initial instant for generation time tracking.
        $GLOBALS['t2'] = microtime(true);

        array_walk_recursive($_GET, self::sanitizeMysqlKv(...));
        array_walk_recursive($_POST, self::sanitizeMysqlKv(...));
        array_walk_recursive($_COOKIE, self::sanitizeMysqlKv(...));
        if (isset($_SERVER['PATH_INFO'])) {
            /** @var mixed $path_info */
            $path_info            = $_SERVER['PATH_INFO'];
            $_SERVER['PATH_INFO'] = addslashes(is_string($path_info) ? $path_info : '');
        }

        if (Kernel::isBooted()) {
            return;
        }

        $GLOBALS['lang']         = [];
        $GLOBALS['header_msgs']  = [];
        $GLOBALS['header_notes'] = [];
        $GLOBALS['filter']       = [];

        ConfigLoader::applyDefaults();

        defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

        ConfigLoader::loadEnv(PHPWG_ROOT_PATH);
        ConfigLoader::applyEnvOverrides();

        $GLOBALS['prefixeTable'] = Config::dbPrefix();

        if (!InstallSentinel::isInstalled()) {
            header('Location: index.php?/install');
            exit;
        }

        ErrorCollector::install();
        if (Config::has('show_php_errors') && !empty(Config::showPhpErrors()) && function_exists('ini_set')) {
            ini_set('error_reporting', (string) Config::showPhpErrors());
        }

        if (Config::sessionGcProbability() > 0 && function_exists('ini_set')) {
            ini_set('session.gc_divisor', '100');
            ini_set('session.gc_probability', (string) min(Config::sessionGcProbability(), 100));
        }

        PageState::current()->executionUuid = StringUtil::generateKey(10);

        $pool             = CacheFactory::create();
        $persistent_cache = new PersistentFileCache($pool);
        PersistentCacheRegistry::set($persistent_cache);

        // Boot the DI container now — env credentials are loaded. The container is
        // lazy (PHP-DI instantiates nothing until first get() call), so booting here
        // does NOT require Config::$data to be populated.
        Kernel::boot();

        try {
            Kernel::service(Connection::class);
        } catch (\Exception $e) {
            HtmlService::fatalError(Lang::t($e->getMessage()));
        }

        if (!Config::has('webmaster_id')) {
            Config::override('webmaster_id', 1);
        }

        // Load application config from the DB. Container is available, so this can
        // go through the typed service path.
        Kernel::service(ConfigService::class)->loadConfFromDb();

        // Run DB migrations only after Config is loaded from the DB, because
        // migrations may inspect Config values (e.g. Config::autoMigrate()).
        if (Config::autoMigrate()) {
            MigrationRunner::migrate();
        }

        $GLOBALS['logger'] = new Logger([
            'directory'   => PHPWG_ROOT_PATH . Config::dataLocation() . Config::logDir(),
            'severity'    => Config::logLevel(),
            'filename'    => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => Config::logArchiveDays(),
        ]);
        LoggerRegistry::set($GLOBALS['logger']);

        if (!Config::checkUpgradeFeed()) {
            if (!Config::has('piwigo_db_version') or Config::piwigoDbVersion() != AppInfo::branchFromVersion(AppInfo::VERSION)) {
                Kernel::service(Util::class)->redirect(UrlService::getRootUrl() . 'index.php?/upgrade');
            }
        }

        ImageStdParams::loadFromDb();

        SessionBootstrap::bootstrap();
        session_start();
        UserBootstrap::bootstrap();
        EventDispatcher::init();
        EventDispatcher::addListener('try_log_user', Kernel::service(AuthService::class)->pwgLogin(...));
        Kernel::service(PluginService::class)->loadPlugins();

        if (!Config::has('piwigo_installed_version')) {
            Kernel::service(ConfigService::class)->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        } elseif (Config::piwigoInstalledVersion() != AppInfo::VERSION) {
            Kernel::service(Util::class)->pwgActivity('system', ActivitySystem::Core, 'autoupdate', ['from_version' => Config::piwigoInstalledVersion(), 'to_version' => AppInfo::VERSION]);
            Kernel::service(ConfigService::class)->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        }

        if (!Config::has('last_major_update')) {
            Kernel::service(ConfigService::class)->confUpdateParam('last_major_update', new \DateTimeImmutable()->format('Y-m-d H:i:s'), true);
        }

        if (Config::has('order_by_custom')) {
            Config::override('order_by', Config::orderByCustom());
        }
        if (Config::has('order_by_inside_category_custom')) {
            Config::override('order_by_inside_category', Config::orderByInsideCategoryCustom());
        }

        Kernel::service(Util::class)->checkLounge();

        $user_globals  = self::readGlobal('user');
        $user_language = is_array($user_globals) && is_scalar($user_globals['language'] ?? null)
            ? (string) $user_globals['language']
            : '';
        if (in_array(substr($user_language, 0, 2), ['fr', 'it', 'de', 'es', 'pl', 'ru', 'nl', 'tr', 'da'])) {
            define('PHPWG_DOMAIN', substr($user_language, 0, 2) . '.piwigo.org');
        } elseif ('zh_CN' == $user_language) {
            define('PHPWG_DOMAIN', 'cn.piwigo.org');
        } elseif ('pt_BR' == $user_language) {
            define('PHPWG_DOMAIN', 'br.piwigo.org');
        } else {
            define('PHPWG_DOMAIN', 'piwigo.org');
        }
        // Fork policy: blanked so this install never sends telemetry to
        // upstream piwigo.org via porg.installs.update, and never pulls
        // version/news/download metadata from there either. PHPWG_DOMAIN is
        // left intact above to ease upstream merges.
        define('PHPWG_URL', '');

        if (Config::has('alternative_pem_url') and Config::alternativePemUrl() != '') {
            define('PEM_URL', Config::alternativePemUrl());
        } else {
            $pem_scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            /** @var mixed $pem_host_raw */
            $pem_host_raw = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $pem_host     = is_string($pem_host_raw) ? $pem_host_raw : 'localhost';
            define('PEM_URL', $pem_scheme . '://' . $pem_host . '/piwigo16-ext');
        }

        Kernel::service(LangService::class)->loadLanguage('common.lang');
        if (Kernel::service(PermissionService::class)->isAdmin() || defined('IN_ADMIN')) {
            Kernel::service(LangService::class)->loadLanguage('admin.lang');
            Kernel::service(LangService::class)->loadLanguage('whats_new_' . AppInfo::branchFromVersion(AppInfo::VERSION) . '.lang');
        }
        EventDispatcher::notify('loading_lang');
        Kernel::service(LangService::class)->loadLanguage('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);

        if (Kernel::service(PermissionService::class)->isAGuest()) {
            $guestName = Lang::t('guest');
            CurrentUser::get()->username = $guestName;
            CurrentUser::get()->rawAttributes['username'] = $guestName;
        }

        if (PageState::current()->authKeyInvalid) {
            PageState::current()->addError(
                Lang::t('Your authentication key is no longer valid.')
                . sprintf(' <a href="%s">%s</a>', Kernel::service(UrlGenerator::class)->identification(), Lang::t('Login'))
            );
        }

        $user_arr  = self::readGlobal('user');
        $notify_exp = PageState::current()->notifyApiKeyExpiration;
        if (is_array($notify_exp)) {
            $notify_username_raw = is_array($user_arr) ? ($user_arr['username'] ?? '') : '';
            $notify_email_raw    = is_array($user_arr) ? ($user_arr['email'] ?? '') : '';
            $notify_days_left    = $notify_exp['days_left'];
            $is_mail_send = Kernel::service(UserService::class)->notificationApiKeyExpiration(
                is_string($notify_username_raw) ? $notify_username_raw : '',
                is_string($notify_email_raw) ? $notify_email_raw : '',
                is_numeric($notify_days_left) ? (int) $notify_days_left : 0
            );

            if ($is_mail_send) {
                $notify_user_id_raw = is_array($user_arr) ? ($user_arr['id'] ?? 0) : 0;
                Dml::singleUpdate(
                    Tables::userAuthKeys(),
                    ['last_notified_on' => $notify_exp['dbnow']],
                    [
                        'user_id'  => is_numeric($notify_user_id_raw) ? (int) $notify_user_id_raw : 0,
                        'auth_key' => $notify_exp['auth_key'],
                    ],
                );
            }

            PageState::current()->notifyApiKeyExpiration = null;
        }

        if (defined('IN_ADMIN')) {
            $admin_theme_raw = Kernel::service(PreferencesService::class)->userprefsGetParam('admin_theme', 'dark');
            $template = new Template(PHPWG_ROOT_PATH . 'themes/admin', is_string($admin_theme_raw) ? $admin_theme_raw : 'dark');
        } else {
            $user_arr_theme = self::readGlobal('user');
            $theme_raw = is_array($user_arr_theme) ? ($user_arr_theme['theme'] ?? '') : '';
            $theme     = is_string($theme_raw) ? $theme_raw : '';
            if (StringUtil::scriptBasename() != 'ws' and Kernel::service(Util::class)->mobileTheme()) {
                $theme = Config::mobilTheme();
            }
            $template = new Template(PHPWG_ROOT_PATH . 'themes', $theme);
        }
        TemplateRegistry::set($template);

        if (!Config::has('no_photo_yet')) {
            Kernel::service(NoPhotoYetRenderer::class)->render();
        }

        $user_arr_gs        = self::readGlobal('user');
        $internal_status_gs = is_array($user_arr_gs) ? ($user_arr_gs['internal_status'] ?? null) : null;
        if (is_array($internal_status_gs)
            && isset($internal_status_gs['guest_must_be_guest'])
            && $internal_status_gs['guest_must_be_guest'] === true) {
            $GLOBALS['header_msgs'][] = Lang::t('Bad status for user "guest", using default status. Please notify the webmaster.');
        }

        if (Config::galleryLocked()) {
            $GLOBALS['header_msgs'][] = Lang::t('The gallery is locked for maintenance. Please, come back later.');

            if (StringUtil::scriptBasename() != 'identification' and !Kernel::service(PermissionService::class)->isAdmin()) {
                Kernel::service(HtmlService::class)->setStatusHeader(503, 'Service Unavailable');
                if (!headers_sent()) {
                    header('Retry-After: 900');
                }
                header('Content-Type: text/html; charset=' . Kernel::service(StringUtil::class)->getPwgCharset());
                echo '<a href="' . Kernel::service(UrlGenerator::class)->identification() . '">' . Lang::t('The gallery is locked for maintenance. Please, come back later.') . '</a>';
                echo str_repeat(' ', 512);
                exit();
            }
        }

        if (Config::checkUpgradeFeed()) {
            if (UpgradeService::checkUpgradeFeed()) {
                $GLOBALS['header_msgs'][] = new Html(
                    'Some database upgrades are missing, '
                    . '<a href="' . UrlService::getAbsoluteRootUrl(false) . 'index.php?/upgrade_feed">upgrade now</a>'
                );
            }
        }

        if (count($GLOBALS['header_msgs']) > 0) {
            $template->assign('header_msgs', $GLOBALS['header_msgs']);
            $GLOBALS['header_msgs'] = [];
        }

        $GLOBALS['filter']['enabled'] = false;

        if (Config::has('header_notes')) {
            $GLOBALS['header_notes'] = array_merge($GLOBALS['header_notes'], Config::headerNotes());
        }

        EventDispatcher::addListener('render_category_literal_description', 'render_category_literal_description');
        if (!Config::allowHtmlDescriptions()) {
            EventDispatcher::addListener('render_category_description', 'pwg_nl2br');
        }
        EventDispatcher::addListener('render_comment_content', 'render_comment_content');
        EventDispatcher::addListener('render_comment_author', 'strip_tags');
        EventDispatcher::addListener('render_tag_url', 'str2url');
        EventDispatcher::addListener(
            'blockmanager_register_blocks',
            static function (BlockManager $menu): void {
                Kernel::service(HtmlService::class)->registerDefaultMenubarBlocks($menu);
            },
            EventDispatcher::NEUTRAL_PRIORITY - 1
        );
        if (!empty(Config::originalUrlProtection())) {
            EventDispatcher::addListener('UrlService::get()->getElementUrl', 'get_element_url_protection_handler');
            EventDispatcher::addListener('get_src_image_url', 'get_src_image_url_protection_handler');
        }
        EventDispatcher::notify('init');
    }
}
