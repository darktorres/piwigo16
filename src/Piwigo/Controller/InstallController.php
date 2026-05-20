<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Activity\Details\InstallDetails;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Extensions\ExtensionAction;
use Piwigo\Admin\InstallService;
use Piwigo\Admin\Languages;
use Piwigo\Admin\UpgradeService;
use Piwigo\Bootstrap\SessionBootstrap;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Config\TestMode;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Filesystem;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Mail\MailService;
use Piwigo\Session\ConnectionType;
use Piwigo\Session\Session;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the Piwigo installation wizard (/install).
 * Corresponds to the former install.php entry-point; now routed via index.php?/install.
 *
 * Accessed directly via the index.php?/install route — bypasses common.inc.php
 * (the DB may not exist yet). The shim loads vendor/autoload.php and
 * functions.inc.php before calling this controller.
 */
final readonly class InstallController implements ControllerInterface
{
    /** Default DB table prefix used when the install form leaves the field blank. */
    public const string DEFAULT_DB_PREFIX = 'piwigo_';

    public function __construct(private Paths $paths)
    {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $prefixRaw = $_POST['prefix'] ?? null;
        $prefixeTable = isset($_POST['install']) && is_string($prefixRaw)
            ? $prefixRaw
            : self::DEFAULT_DB_PREFIX;

        $rawDbhost    = $_POST['dbhost']     ?? null;
        $rawDbuser    = $_POST['dbuser']     ?? null;
        $rawDbpasswd  = $_POST['dbpasswd']   ?? null;
        $rawDbname    = $_POST['dbname']     ?? null;
        $rawAdminName = $_POST['admin_name'] ?? null;
        $rawAdminMail = $_POST['admin_mail'] ?? null;
        $dbhost   = is_string($rawDbhost) ? $rawDbhost : '';
        $dbuser   = is_string($rawDbuser) ? $rawDbuser : '';
        $dbpasswd = is_string($rawDbpasswd) ? $rawDbpasswd : '';
        $dbname   = is_string($rawDbname) ? $rawDbname : '';
        $dblayer  = 'mysqli';

        $admin_name  = is_string($rawAdminName) ? $rawAdminName : '';
        $rawAdminPass1 = $_POST['admin_pass1'] ?? null;
        $rawAdminPass2 = $_POST['admin_pass2'] ?? null;
        $admin_pass1 = is_string($rawAdminPass1) ? $rawAdminPass1 : '';
        $admin_pass2 = is_string($rawAdminPass2) ? $rawAdminPass2 : '';
        $admin_mail  = (is_string($rawAdminMail) && $rawAdminMail !== '') ? $rawAdminMail : '';

        $is_newsletter_subscribe = isset($_POST['install']) && isset($_POST['newsletter_subscribe']);

        $infos  = [];
        $errors = [];

        if (InstallSentinel::isInstalled($this->paths)) {
            die('Piwigo is already installed');
        }

        // Boot the container early so LangService, HtmlService, etc. are
        // resolvable. DB-requiring services stay lazy until credentials are
        // set in Config::override() inside the step-2 block below.
        // Languages is intentionally NOT resolved here — its constructor pulls
        // in AdminService/LanguageRepository (and therefore Connection), which
        // would fail on a fresh install where no DB credentials exist yet.
        Kernel::boot($this->paths);

        // Scan available languages from the filesystem — no DI or DB needed.
        $fsLanguages = $this->scanFsLanguages();

        if (isset($_GET['language'])) {
            $language = strip_tags(is_string($rawLang = $_GET['language']) ? $rawLang : '');
            if (!in_array($language, array_keys($fsLanguages))) {
                $language = AppInfo::DEFAULT_LANGUAGE;
            }
        } else {
            $rawAcceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
            $matched       = PreferencesService::pickFromAcceptLanguage(
                array_keys($fsLanguages),
                is_string($rawAcceptLang) ? $rawAcceptLang : '',
            );
            $language = $matched !== false ? $matched : AppInfo::DEFAULT_LANGUAGE;
        }

        Kernel::service(LangService::class)->loadLanguage('common.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);
        Kernel::service(LangService::class)->loadLanguage('admin.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);
        Kernel::service(LangService::class)->loadLanguage('install.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);

        header('Content-Type: text/html; charset=UTF-8');

        if (version_compare(PHP_VERSION, AppInfo::REQUIRED_PHP_VERSION, '<')) {
            $errors[] = Lang::t('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION);
        }

        $tpl = new Template($this->paths->root . 'themes/admin', 'dark');
        TemplateRegistry::set($tpl);
        $step = 1;

        if (isset($_POST['install'])) {
            InstallService::installDbConnect($infos, $errors);

            $dbConnectFailed = count($errors) > 0;

            if (
                strlen($prefixeTable) > 20
                || preg_match('/^\d/', $prefixeTable)
                || !preg_match('/^[a-zA-Z0-9_$]*$/u', $prefixeTable)
            ) {
                $errors[] = 'invalid table prefix';
            }

            $webmaster = trim((string) preg_replace('/\s{2,}/', ' ', $admin_name));
            if ($webmaster === '') {
                $errors[] = Lang::t('enter a login for webmaster');
            } elseif (preg_match('/[\'"]/', $webmaster)) {
                $errors[] = Lang::t('webmaster login can\'t contain characters \' or "');
            }
            $adminPass1 = $_POST['admin_pass1'] ?? '';
            $adminPass2 = $_POST['admin_pass2'] ?? '';
            if ($adminPass1 === '' || $adminPass2 === '' || $adminPass1 !== $adminPass2) {
                $errors[] = Lang::t('please enter your password again');
            }
            if (($_POST['admin_mail'] ?? '') === '') {
                $errors[] = Lang::t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
            } elseif (!$dbConnectFailed) {
                $error_mail_address = Kernel::service(AuthService::class)->validateMailAddress(null, $admin_mail);
                if ($error_mail_address !== null && $error_mail_address !== '') {
                    $errors[] = $error_mail_address;
                }
            }

            if (count($errors) == 0) {
                $step = 2;

                $envPath = $this->paths->root . TestMode::envFile();
                $envBody = "PIWIGO_DB_HOST={$dbhost}\n"
                         . "PIWIGO_DB_USER={$dbuser}\n"
                         . "PIWIGO_DB_PASSWORD={$dbpasswd}\n"
                         . "PIWIGO_DB_BASE={$dbname}\n"
                         . "PIWIGO_DB_PREFIX={$prefixeTable}\n";
                // PIWIGO_BASE_URL is consumed only by the test runner (see
                // .env.example). Write it in test mode so InstallChainTest
                // doesn't clobber the URL line on every install.
                if (TestMode::isActive()) {
                    $baseUrl = rtrim(UrlService::getAbsoluteRootUrl(), '/');
                    if ($baseUrl !== '') {
                        $envBody .= "PIWIGO_BASE_URL={$baseUrl}\n";
                    }
                }
                $envTmp = $envPath . '.tmp.' . bin2hex(random_bytes(4));
                if (file_put_contents($envTmp, $envBody) === false || !rename($envTmp, $envPath)) {
                    Filesystem::tryUnlink($envTmp);
                    HtmlService::fatalError('Could not write ' . $envPath . ' — check filesystem permissions.');
                }

                Config::override('db_host', $dbhost);
                Config::override('db_user', $dbuser);
                Config::override('db_password', $dbpasswd);
                Config::override('db_base', $dbname);
                Config::override('db_prefix', $prefixeTable);

                $configService = Kernel::service(ConfigService::class);

                InstallService::executeSqlFile($this->paths->root . 'install/piwigo_structure-mysql.sql', self::DEFAULT_DB_PREFIX, $prefixeTable);
                InstallService::executeSqlFile($this->paths->root . 'install/config.sql', self::DEFAULT_DB_PREFIX, $prefixeTable);

                $configService->confUpdateParam('secret_key', sha1(random_bytes(1000)));
                $configService->confUpdateParam('piwigo_db_version', AppInfo::branchFromVersion(AppInfo::VERSION));
                $configService->confUpdateParam('gallery_title', Lang::t('Just another Piwigo gallery'));
                $configService->confUpdateParam('page_banner', '<h1>%gallery_title%</h1>' . "\n\n<p>" . Lang::t('Welcome to my photo gallery') . '</p>');

                Kernel::service(Languages::class)->performAction(ExtensionAction::Activate, $language);
                ConfigService::loadConfFromDb();
                InstallService::activateCoreThemes();
                InstallService::activateCorePlugins();

                $conn = Kernel::service(Connection::class);
                $conn->insert(Tables::sites(), ['id' => 1, 'galleries_url' => './galleries/']);

                $conn->insert(Tables::users(), ['id' => 1, 'username' => $admin_name, 'password' => password_hash($admin_pass1, PASSWORD_BCRYPT), 'mail_address' => $admin_mail]);
                $conn->insert(Tables::users(), ['id' => 2, 'username' => 'guest']);
                Kernel::service(UserService::class)->createUserInfos([1, 2], ['language' => $language]);

                $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
                $upgradeIds = UpgradeService::getAvailableUpgradeIds();
                if ($upgradeIds !== []) {
                    $conn->transactional(static function (Connection $conn) use ($upgradeIds, $now): void {
                        foreach ($upgradeIds as $upgrade_id) {
                            $conn->insert(Tables::upgrade(), ['id' => $upgrade_id, 'applied' => $now, 'description' => 'upgrade included in installation']);
                        }
                    });
                }
                InstallSentinel::markInstalled($this->paths);
            }
        }

        // Template output
        $languages_options = [];
        foreach ($fsLanguages as $language_code => $fs_language) {
            if ($language == $language_code) {
                $tpl->assign('language_selection', $language_code);
            }
            $languages_options[$language_code] = $fs_language['name'];
        }
        $tpl->assign('language_options', $languages_options);
        $tpl->assign([
            'T_CONTENT_ENCODING'     => 'utf-8',
            'RELEASE'                => AppInfo::VERSION,
            'F_ACTION'               => 'index.php?/install&language=' . $language,
            'F_DB_HOST'              => $dbhost,
            'F_DB_USER'              => $dbuser,
            'F_DB_PASSWD'            => $dbpasswd,
            'F_DB_NAME'              => $dbname,
            'F_DB_PREFIX'            => $prefixeTable,
            'F_ADMIN'                => $admin_name,
            'F_ADMIN_PASS'           => $admin_pass1,
            'F_ADMIN_EMAIL'          => $admin_mail,
            'EMAIL'                  => new Html('<span class="adminEmail">' . htmlspecialchars($admin_mail) . '</span>'),
            'F_NEWSLETTER_SUBSCRIBE' => $is_newsletter_subscribe,
            'L_INSTALL_HELP'         => new Html(Lang::t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', AppInfo::PROJECT_URL . '/forum')),
        ]);

        if ($step == 1) {
            $tpl->assign('install', true);
        } else {
            Kernel::service(ActivityLogger::class)->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Core, ActivityAction::Install, new InstallDetails(AppInfo::VERSION)));
            $infos[] = Lang::t('Congratulations, Piwigo installation is completed');

            {
                SessionBootstrap::bootstrap();

                $user = Kernel::service(UserService::class)->buildUser(1, false);
                CurrentUser::setRawAttributes($user);
                Kernel::service(AuthService::class)->logUser(is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0, false);
                Kernel::service(Session::class)->connectedWith = ConnectionType::PwgUi->value;

                if (!is_array($user['preferences'] ?? null)) {
                    $user['preferences'] = [];
                }
                $user['preferences']['show_whats_new_' . AppInfo::branchFromVersion(AppInfo::VERSION)] = false;

                if ($is_newsletter_subscribe) {
                    $result = '';
                    Kernel::service(AdminService::class)->fetchRemote(
                        Kernel::service(AdminService::class)->getNewsletterSubscribeBaseUrl($language) . $admin_mail,
                        $result,
                        [],
                        ['origin' => 'installation']
                    );
                    $user['preferences']['show_newsletter_subscription'] = false;
                }

                Kernel::service(PreferencesService::class)->userprefsSave();

                if (isset($_POST['send_credentials_by_mail'])) {
                    $keyargs_content = [
                        Kernel::service(LangService::class)->getL10nArgs('Hello %s,', $admin_name),
                        Kernel::service(LangService::class)->getL10nArgs('Welcome to your new installation of Piwigo!', ''),
                        Kernel::service(LangService::class)->getL10nArgs('', ''),
                        Kernel::service(LangService::class)->getL10nArgs('Here are your connection settings', ''),
                        Kernel::service(LangService::class)->getL10nArgs('', ''),
                        Kernel::service(LangService::class)->getL10nArgs('Link: %s', UrlService::getAbsoluteRootUrl()),
                        Kernel::service(LangService::class)->getL10nArgs('Username: %s', $admin_name),
                        Kernel::service(LangService::class)->getL10nArgs('Password: ********** (no copy by email)', ''),
                        Kernel::service(LangService::class)->getL10nArgs('Email: %s', $admin_mail),
                        Kernel::service(LangService::class)->getL10nArgs('', ''),
                        Kernel::service(LangService::class)->getL10nArgs('Don\'t hesitate to consult our forums for any help: %s', AppInfo::PROJECT_URL),
                    ];
                    Kernel::service(MailService::class)->pwgMail($admin_mail, ['subject' => Lang::t('Just another Piwigo gallery'), 'content' => Kernel::service(LangService::class)->l10nArgs($keyargs_content), 'content_format' => 'text/plain']);
                }
            }
        }

        if (count($errors) != 0) {
            $tpl->assign('errors', $errors);
        }
        if (count($infos) != 0) {
            $tpl->assign('infos', $infos);
        }

        $tpl->pparse('install.latte');

        return ResponseFactory::create(200);
    }

    /**
     * Reads available languages from the filesystem without using the DI
     * container — safe to call before DB credentials are available.
     * Mirrors the core logic of Languages::getFsLanguages() for the install path.
     *
     * @return array<string, array<string, string>>
     */
    private function scanFsLanguages(): array
    {
        $langs = [];
        $dir = opendir($this->paths->root . 'language');
        if ($dir === false) {
            return $langs;
        }
        while ($file = readdir($dir)) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $this->paths->root . 'language/' . $file;
            if (!is_dir($path) || is_link($path)
                || !preg_match('/^[a-zA-Z0-9-_]+$/', $file)
                || !file_exists($path . '/common.po')
            ) {
                continue;
            }
            $language = ['name' => $file, 'code' => $file, 'version' => '0', 'uri' => '', 'author' => ''];
            $poLines  = file($path . '/common.po');
            $po       = implode('', $poLines !== false ? $poLines : []);
            if (preg_match('|X-Piwigo-Language-Name:\\s*(.+?)\\\\n|', $po, $val)) {
                $language['name'] = trim($val[1]);
            }
            $langs[$file] = array_map(htmlspecialchars(...), $language);
        }
        closedir($dir);
        uasort($langs, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $langs;
    }
}
