<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\UpgradeService;
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
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Event\Lifecycle\Init;
use Piwigo\Event\Lifecycle\LoadingLang;
use Piwigo\Html\HtmlService;
use Piwigo\Http\DeviceDetectionService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\LangService;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserBootstrap;
use Piwigo\Users\UserService;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcher;

final class CommonBootstrap
{
    private static function sanitizeMysqlKv(mixed &$v, string $k): void
    {
        $v = addslashes(is_scalar($v) ? (string) $v : '');
    }

    public static function run(Paths $paths): void
    {
        ExceptionHandler::register();

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

        ConfigLoader::applyDefaults();

        ConfigLoader::loadEnv($paths->root);
        ConfigLoader::applyEnvOverrides();

        if (!InstallSentinel::isInstalled($paths)) {
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

        // Boot the DI container now — env credentials are loaded. The container is
        // lazy (PHP-DI instantiates nothing until first get() call), so booting here
        // does NOT require Config::$data to be populated.
        Kernel::boot($paths);

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

        LoggerRegistry::set(new Logger([
            'directory'   => $paths->root . Config::dataLocation() . Config::logDir(),
            'severity'    => Config::logLevel(),
            'filename'    => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => Config::logArchiveDays(),
        ]));

        if (!Config::checkUpgradeFeed()) {
            if (!Config::has('piwigo_db_version') or Config::piwigoDbVersion() != AppInfo::branchFromVersion(AppInfo::VERSION)) {
                Kernel::service(RedirectResponder::class)->redirect(UrlService::getRootUrl() . 'index.php?/upgrade');
            }
        }

        ImageStdParams::loadFromDb();

        SessionBootstrap::bootstrap();
        session_start();
        UserBootstrap::bootstrap();
        // Core event subscribers register themselves via the EventDispatcher
        // factory in config/container.php (see Piwigo\Listener\CoreSubscribers).
        // Plugin subscribers attach here, after auth so plugin::boot() can
        // read CurrentUser; no-op when plugins/ holds no plugin.json file.
        Kernel::service(PluginRegistry::class)->bootActive(
            Kernel::service(SymfonyEventDispatcher::class),
            Kernel::service(ContainerInterface::class),
        );

        if (!Config::has('piwigo_installed_version')) {
            Kernel::service(ConfigService::class)->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        } elseif (Config::piwigoInstalledVersion() != AppInfo::VERSION) {
            Kernel::service(ActivityLogger::class)->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Core, 'autoupdate', ['from_version' => Config::piwigoInstalledVersion(), 'to_version' => AppInfo::VERSION]));
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

        Kernel::service(CategoryAdminService::class)->checkLounge();

        Kernel::service(LangService::class)->loadLanguage('common.lang');
        if (Kernel::service(PermissionService::class)->isAdmin() || RequestContextRegistry::current() === RequestContext::Admin) {
            Kernel::service(LangService::class)->loadLanguage('admin.lang');
            Kernel::service(LangService::class)->loadLanguage('whats_new_' . AppInfo::branchFromVersion(AppInfo::VERSION) . '.lang');
        }
        Kernel::service(EventDispatcherInterface::class)->dispatch(new LoadingLang());
        Kernel::service(LangService::class)->loadLanguage('lang', $paths->local, ['no_fallback' => true, 'local' => true]);

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

        $notify_exp = PageState::current()->notifyApiKeyExpiration;
        if (is_array($notify_exp)) {
            $user_attr   = CurrentUser::get()->rawAttributes;
            $notify_days_left = $notify_exp['days_left'];
            $is_mail_send = Kernel::service(UserService::class)->notificationApiKeyExpiration(
                is_scalar($user_attr['username'] ?? null) ? (string) $user_attr['username'] : '',
                is_scalar($user_attr['email'] ?? null) ? (string) $user_attr['email'] : '',
                is_numeric($notify_days_left) ? (int) $notify_days_left : 0
            );

            if ($is_mail_send) {
                Kernel::service(Connection::class)->update(
                    Tables::userAuthKeys(),
                    ['last_notified_on' => $notify_exp['dbnow']],
                    [
                        'user_id'  => CurrentUser::get()->id,
                        'auth_key' => $notify_exp['auth_key'],
                    ],
                );
            }

            PageState::current()->notifyApiKeyExpiration = null;
        }

        if (RequestContextRegistry::current() === RequestContext::Admin) {
            $admin_theme_raw = Kernel::service(PreferencesService::class)->userprefsGetParam('admin_theme', 'dark');
            $template = new Template($paths->root . 'themes/admin', is_string($admin_theme_raw) ? $admin_theme_raw : 'dark');
        } else {
            $theme_raw = CurrentUser::get()->rawAttributes['theme'] ?? '';
            $theme     = is_string($theme_raw) ? $theme_raw : '';
            if (StringUtil::scriptBasename() != 'ws' and Kernel::service(DeviceDetectionService::class)->isMobileTheme()) {
                $theme = Config::mobilTheme();
            }
            $template = new Template($paths->root . 'themes', $theme);
        }
        TemplateRegistry::set($template);

        if (!Config::has('no_photo_yet')) {
            Kernel::service(NoPhotoYetRenderer::class)->render();
        }

        $internal_status_gs = CurrentUser::get()->rawAttributes['internal_status'] ?? null;
        if (is_array($internal_status_gs)
            && isset($internal_status_gs['guest_must_be_guest'])
            && $internal_status_gs['guest_must_be_guest'] === true) {
            PageState::current()->headerMessages[] = Lang::t('Bad status for user "guest", using default status. Please notify the webmaster.');
        }

        if (Config::galleryLocked()) {
            PageState::current()->headerMessages[] = Lang::t('The gallery is locked for maintenance. Please, come back later.');

            if (StringUtil::scriptBasename() != 'identification' and !Kernel::service(PermissionService::class)->isAdmin()) {
                Kernel::service(HtmlService::class)->setStatusHeader(503, 'Service Unavailable');
                if (!headers_sent()) {
                    header('Retry-After: 900');
                }
                header('Content-Type: text/html; charset=' . StringUtil::getPwgCharset());
                echo '<a href="' . Kernel::service(UrlGenerator::class)->identification() . '">' . Lang::t('The gallery is locked for maintenance. Please, come back later.') . '</a>';
                echo str_repeat(' ', 512);
                exit();
            }
        }

        if (Config::checkUpgradeFeed()) {
            if (UpgradeService::checkUpgradeFeed()) {
                PageState::current()->headerMessages[] = new Html(
                    'Some database upgrades are missing, '
                    . '<a href="' . UrlService::getAbsoluteRootUrl(false) . 'index.php?/upgrade_feed">upgrade now</a>'
                );
            }
        }

        if (Config::has('header_notes')) {
            foreach (Config::headerNotes() as $note) {
                PageState::current()->headerNotes[] = $note;
            }
        }

        // Listener registration moved to Piwigo\Listener\CoreSubscribers and
        // wired into the EventDispatcher factory in config/container.php.
        // Conditional registrations (Config::allowHtmlDescriptions,
        // Config::originalUrlProtection) became dispatch-time guards inside
        // the respective subscribers since Symfony subscribers register once
        // at boot.
        Kernel::service(EventDispatcherInterface::class)->dispatch(new Init());
    }
}
