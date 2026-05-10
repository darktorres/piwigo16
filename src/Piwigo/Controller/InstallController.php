<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Admin\AdminService;
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
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Mail\MailService;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
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
final class InstallController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $prefixRaw = $_POST['prefix'] ?? null;
        $prefixeTable = isset($_POST['install']) && is_string($prefixRaw)
            ? $prefixRaw
            : DEFAULT_PREFIX_TABLE;
        $GLOBALS['prefixeTable'] = $prefixeTable;

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

        if (InstallSentinel::isInstalled()) {
            die('Piwigo is already installed');
        }

        Kernel::boot();

        $languages = new Languages('utf-8');

        if (isset($_GET['language'])) {
            $language = strip_tags(is_string($rawLang = $_GET['language']) ? $rawLang : '');
            if (!in_array($language, array_keys($languages->fs_languages))) {
                $language = AppInfo::DEFAULT_LANGUAGE;
            }
        } else {
            $language = 'en_UK';
            foreach ($languages->fs_languages as $language_code => $fs_language) {
                /** @var string $rawAcceptLang */
                $rawAcceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
                if (substr($language_code, 0, 2) == substr($rawAcceptLang, 0, 2)) {
                    $language = $language_code;
                    break;
                }
            }
        }

        if ('fr_FR' == $language) {
            define('PHPWG_DOMAIN', 'fr.piwigo.org');
        } elseif ('it_IT' == $language) {
            define('PHPWG_DOMAIN', 'it.piwigo.org');
        } elseif ('de_DE' == $language) {
            define('PHPWG_DOMAIN', 'de.piwigo.org');
        } elseif ('es_ES' == $language) {
            define('PHPWG_DOMAIN', 'es.piwigo.org');
        } elseif ('pl_PL' == $language) {
            define('PHPWG_DOMAIN', 'pl.piwigo.org');
        } elseif ('zh_CN' == $language) {
            define('PHPWG_DOMAIN', 'cn.piwigo.org');
        } elseif ('ru_RU' == $language) {
            define('PHPWG_DOMAIN', 'ru.piwigo.org');
        } elseif ('nl_NL' == $language) {
            define('PHPWG_DOMAIN', 'nl.piwigo.org');
        } elseif ('tr_TR' == $language) {
            define('PHPWG_DOMAIN', 'tr.piwigo.org');
        } elseif ('da_DK' == $language) {
            define('PHPWG_DOMAIN', 'da.piwigo.org');
        } elseif ('pt_BR' == $language) {
            define('PHPWG_DOMAIN', 'br.piwigo.org');
        } else {
            define('PHPWG_DOMAIN', 'piwigo.org');
        }
        define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);

        LangService::get()->loadLanguage('common.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);
        LangService::get()->loadLanguage('admin.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);
        LangService::get()->loadLanguage('install.lang', '', ['language' => $language, 'target_charset' => 'utf-8']);

        header('Content-Type: text/html; charset=UTF-8');

        if (version_compare(PHP_VERSION, AppInfo::REQUIRED_PHP_VERSION, '<')) {
            $errors[] = Lang::t('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION);
        }

        $tpl = new Template(PHPWG_ROOT_PATH . 'themes/admin', 'dark');
        TemplateRegistry::set($tpl);
        $tpl->setFilenames(['install' => 'install.latte']);
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
                $error_mail_address = AuthService::get()->validateMailAddress(null, $admin_mail);
                if ($error_mail_address !== null && $error_mail_address !== '') {
                    $errors[] = $error_mail_address;
                }
            }

            if (count($errors) == 0) {
                $step = 2;

                $envPath = PHPWG_ROOT_PATH . TestMode::envFile();
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

                InstallService::executeSqlFile(PHPWG_ROOT_PATH . 'install/piwigo_structure-mysql.sql', DEFAULT_PREFIX_TABLE, $prefixeTable, 'mysql');
                InstallService::executeSqlFile(PHPWG_ROOT_PATH . 'install/config.sql', DEFAULT_PREFIX_TABLE, $prefixeTable, 'mysql');

                Dml::singleInsert($prefixeTable . 'config', [
                    'param'   => 'secret_key',
                    'value'   => sha1(random_bytes(1000)),
                    'comment' => 'a secret key specific to the gallery for internal use',
                ]);

                ServiceLocator::get(ConfigService::class)->confUpdateParam('piwigo_db_version', AppInfo::branchFromVersion(AppInfo::VERSION));
                ServiceLocator::get(ConfigService::class)->confUpdateParam('gallery_title', Lang::t('Just another Piwigo gallery'));
                ServiceLocator::get(ConfigService::class)->confUpdateParam('page_banner', '<h1>%gallery_title%</h1>' . "\n\n<p>" . Lang::t('Welcome to my photo gallery') . '</p>');

                $languages->performAction('activate', $language);
                ConfigService::loadConfFromDb();
                InstallService::activateCoreThemes();
                InstallService::activateCorePlugins();

                Dml::massInserts(Tables::sites(), ['id', 'galleries_url'], [['id' => 1, 'galleries_url' => PHPWG_ROOT_PATH . 'galleries/']]);

                $inserts = [
                    ['id' => 1, 'username' => $admin_name, 'password' => password_hash($admin_pass1, PASSWORD_BCRYPT), 'mail_address' => $admin_mail],
                    ['id' => 2, 'username' => 'guest'],
                ];
                Dml::massInserts(Tables::users(), array_keys($inserts[0]), $inserts);
                UserService::get()->createUserInfos([1, 2], ['language' => $language]);

                define('CURRENT_DATE', new \DateTimeImmutable()->format('Y-m-d H:i:s'));
                $datas = [];
                foreach (UpgradeService::getAvailableUpgradeIds() as $upgrade_id) {
                    $datas[] = ['id' => $upgrade_id, 'applied' => CURRENT_DATE, 'description' => 'upgrade included in installation'];
                }
                if (!empty($datas)) {
                    Dml::massInserts(Tables::upgrade(), array_keys($datas[0]), $datas);
                }
                InstallSentinel::markInstalled();
            }
        }

        // Template output
        $languages_options = [];
        foreach ($languages->fs_languages as $language_code => $fs_language) {
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
            'L_INSTALL_HELP'         => new Html(Lang::t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum')),
        ]);

        if ($step == 1) {
            $tpl->assign('install', true);
        } else {
            ServiceLocator::get(Util::class)->pwgActivity('system', ActivitySystem::Core, 'install', ['version' => AppInfo::VERSION]);
            $infos[] = Lang::t('Congratulations, Piwigo installation is completed');

            {
                SessionBootstrap::bootstrap();

                $user = UserService::get()->buildUser(1, false);
                $GLOBALS['user'] = $user;
                AuthService::get()->logUser(is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0, false);
                $_SESSION['connected_with'] = 'pwg_ui';

                if (!is_array($user['preferences'] ?? null)) {
                    $user['preferences'] = [];
                }
                $user['preferences']['show_whats_new_' . AppInfo::branchFromVersion(AppInfo::VERSION)] = false;

                if ($is_newsletter_subscribe) {
                    $result = '';
                    ServiceLocator::get(AdminService::class)->fetchRemote(
                        ServiceLocator::get(AdminService::class)->getNewsletterSubscribeBaseUrl($language) . $admin_mail,
                        $result,
                        [],
                        ['origin' => 'installation']
                    );
                    $user['preferences']['show_newsletter_subscription'] = false;
                }

                PreferencesService::get()->userprefsSave();

                if (isset($_POST['send_credentials_by_mail'])) {
                    $keyargs_content = [
                        LangService::get()->getL10nArgs('Hello %s,', $admin_name),
                        LangService::get()->getL10nArgs('Welcome to your new installation of Piwigo!', ''),
                        LangService::get()->getL10nArgs('', ''),
                        LangService::get()->getL10nArgs('Here are your connection settings', ''),
                        LangService::get()->getL10nArgs('', ''),
                        LangService::get()->getL10nArgs('Link: %s', UrlService::getAbsoluteRootUrl()),
                        LangService::get()->getL10nArgs('Username: %s', $admin_name),
                        LangService::get()->getL10nArgs('Password: ********** (no copy by email)', ''),
                        LangService::get()->getL10nArgs('Email: %s', $admin_mail),
                        LangService::get()->getL10nArgs('', ''),
                        LangService::get()->getL10nArgs('Don\'t hesitate to consult our forums for any help: %s', PHPWG_URL),
                    ];
                    ServiceLocator::get(MailService::class)->pwgMail($admin_mail, ['subject' => Lang::t('Just another Piwigo gallery'), 'content' => LangService::get()->l10nArgs($keyargs_content), 'content_format' => 'text/plain']);
                }
            }
        }

        if (count($errors) != 0) {
            $tpl->assign('errors', $errors);
        }
        if (count($infos) != 0) {
            $tpl->assign('infos', $infos);
        }

        $tpl->pparse('install');

        return ResponseFactory::create(200);
    }
}
