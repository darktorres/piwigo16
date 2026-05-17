<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Languages;
use Piwigo\Admin\Updates;
use Piwigo\Admin\UpgradeService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Lang\LangService;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\PreferencesService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the Piwigo database upgrade (/upgrade).
 * Corresponds to the former upgrade.php entry-point; now routed via index.php?/upgrade.
 *
 * Accessed directly via the index.php?/upgrade route — bypasses common.inc.php.
 * The shim loads vendor/autoload.php and ConfigLoader before calling this.
 */
final readonly class UpgradeController implements ControllerInterface
{
    public function __construct(
        private ConfigService $configService,
        private Connection $conn,
        private LangService $langService,
        private Paths $paths,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        RequestContextRegistry::set(RequestContext::Upgrade);

        $prefixeTable = Config::dbPrefix();

        if (!InstallSentinel::isInstalled()) {
            die('Piwigo is not installed yet — navigate to index.php?/install first.');
        }

        define('PREFIX_TABLE', $prefixeTable);
        define('UPGRADES_PATH', $this->paths->root . 'install/db');

        Kernel::boot($this->paths);

        $languages = Kernel::service(Languages::class);
        $languages->getFsLanguages('utf-8');
        $get_language = isset($_GET['language']) && is_string($_GET['language']) ? $_GET['language'] : null;
        if ($get_language !== null) {
            $language = strip_tags($get_language);
            if (!in_array($language, array_keys($languages->fs_languages))) {
                $language = AppInfo::DEFAULT_LANGUAGE;
            }
        } else {
            $rawAcceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
            $matched       = PreferencesService::pickFromAcceptLanguage(
                array_keys($languages->fs_languages),
                is_string($rawAcceptLang) ? $rawAcceptLang : '',
            );
            $language = $matched !== false ? $matched : AppInfo::DEFAULT_LANGUAGE;
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
        // Fork policy — see CommonBootstrap.php for the rationale.
        define('PHPWG_URL', '');

        $this->langService->loadLanguage('common.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        $this->langService->loadLanguage('admin.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        $this->langService->loadLanguage('install.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        $this->langService->loadLanguage('upgrade.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);

        UpgradeService::upgradeDbConnect();

        // Refuse databases predating Piwigo 15.x. The piwigo_activity table was
        // introduced in 15.0.0; its absence reliably identifies older schemas.
        // Fresh fork installs always have piwigo_activity (it is in the baseline
        // SQL), so this check never fires for them.
        $existingTables = $this->conn->executeQuery('SHOW TABLES')->fetchFirstColumn();
        $activityTable  = Config::dbPrefix() . 'activity';
        if (!in_array($activityTable, $existingTables, true)) {
            header('Content-Type: text/html; charset=UTF-8', true, 409);
            echo '<h1>Upgrade refused</h1>';
            echo '<p>Your database predates Piwigo 15.0.0. '
                . 'Upgrade to Piwigo 15.x first, then retry.</p>';
            exit;
        }

        $tpl = new Template($this->paths->root . 'themes/admin', 'dark');
        TemplateRegistry::set($tpl);
        $tpl->assign([
            'RELEASE'        => AppInfo::VERSION,
            'L_UPGRADE_HELP' => Lang::t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum'),
        ]);

        $has_remote_site = false;
        foreach ($this->conn->executeQuery('SELECT galleries_url FROM ' . Tables::sites())->fetchAllAssociative() as $row) {
            if (UrlService::urlIsRemote(is_string($row['galleries_url'] ?? null) ? $row['galleries_url'] : '')) {
                $has_remote_site = true;
            }
        }

        if ($has_remote_site) {
            $step = 3;
            Kernel::service(Updates::class)->upgradeTo('2.3.4', $step, false);

            $upgradeErrors = PageState::current()->errors;
            if ($upgradeErrors !== []) {
                echo '<ul>';
                foreach ($upgradeErrors as $error) {
                    echo '<li>' . (string) $error . '</li>';
                }
                echo '</ul>';
            }
            exit();
        }

        $this->configService->confUpdateParam('piwigo_db_version', AppInfo::branchFromVersion(AppInfo::VERSION));
        header('Content-Type: text/html; charset=' . StringUtil::getPwgCharset());
        echo 'No upgrade required, the database structure is up to date';
        echo '<br><a href="index.php">← back to gallery</a>';
        exit();
    }
}
