<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Admin\Languages;
use Piwigo\Admin\Updates;
use Piwigo\Admin\UpgradeService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Lang\LangService;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the Piwigo database upgrade (/upgrade).
 * Corresponds to the former upgrade.php entry-point; now routed via index.php?/upgrade.
 *
 * Accessed directly via the index.php?/upgrade route — bypasses common.inc.php.
 * The shim loads vendor/autoload.php and ConfigLoader before calling this.
 */
final class UpgradeController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $prefixeTable = Config::dbPrefix();
        $GLOBALS['prefixeTable'] = $prefixeTable;

        if (!InstallSentinel::isInstalled()) {
            die('Piwigo is not installed yet — navigate to index.php?/install first.');
        }

        define('PREFIX_TABLE', $prefixeTable);
        define('UPGRADES_PATH', PHPWG_ROOT_PATH . 'install/db');

        require_once PHPWG_ROOT_PATH . 'include/functions.inc.php';
        // Upgrade always reads fresh config from DB; prevent auto_migrate loop.
        Config::override('auto_migrate', false);
        Kernel::boot();

        $languages = new Languages('utf-8');
        $get_language = isset($_GET['language']) && is_string($_GET['language']) ? $_GET['language'] : null;
        if ($get_language !== null) {
            $language = strip_tags($get_language);
            if (!in_array($language, array_keys($languages->fs_languages))) {
                $language = AppInfo::DEFAULT_LANGUAGE;
            }
        } else {
            $language = 'en_UK';
            foreach ($languages->fs_languages as $language_code => $fs_language) {
                $httpAccLang = is_scalar($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
                if (substr((string) $language_code, 0, 2) == substr($httpAccLang, 0, 2)) {
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

        LangService::get()->loadLanguage('common.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        LangService::get()->loadLanguage('admin.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        LangService::get()->loadLanguage('install.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        LangService::get()->loadLanguage('upgrade.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);

        UpgradeService::upgradeDbConnect();

        define('CURRENT_DATE', new \DateTimeImmutable()->format('Y-m-d H:i:s'));

        $tpl = new Template(PHPWG_ROOT_PATH . 'themes/admin', 'dark');
        TemplateRegistry::set($tpl);
        $tpl->setFilenames(['upgrade' => 'upgrade.tpl']);
        $tpl->assign([
            'RELEASE'        => AppInfo::VERSION,
            'L_UPGRADE_HELP' => Lang::t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum'),
        ]);

        $page = &$GLOBALS['page'];
        $has_remote_site = false;
        foreach (DbConnection::get()->executeQuery('SELECT galleries_url FROM ' . Tables::sites())->fetchAllAssociative() as $row) {
            if (UrlService::urlIsRemote(is_scalar($row['galleries_url']) ? (string) $row['galleries_url'] : '')) {
                $has_remote_site = true;
            }
        }

        if ($has_remote_site) {
            $step = 3;
            Updates::upgradeTo('2.3.4', $step, false);

            $rawPage       = $GLOBALS['page'] ?? null;
            $upgradeErrors = is_array($rawPage) && is_array($rawPage['errors'] ?? null)
                ? $rawPage['errors'] : [];
            if ($upgradeErrors !== []) {
                echo '<ul>';
                foreach ($upgradeErrors as $error) {
                    echo '<li>' . (is_scalar($error) ? (string) $error : '') . '</li>';
                }
                echo '</ul>';
            }
            exit();
        }

        $tables     = $this->getTables();
        $columns_of = $this->getColumnsOf($tables);

        $applied_upgrades = in_array(PREFIX_TABLE . 'upgrade', $tables, true)
            ? array_column(DbConnection::get()->executeQuery('SELECT id FROM ' . PREFIX_TABLE . 'upgrade')->fetchAllAssociative(), 'id')
            : [];

        if (!in_array('181', $applied_upgrades, true)) {
            header('Content-Type: text/html; charset=UTF-8', true, 409);
            echo '<h1>Upgrade refused</h1>';
            echo '<p>This Piwigo build only upgrades from <strong>Piwigo 16.x</strong> sources. ';
            echo 'Your database appears to be older than Piwigo 15.0.0 ';
            echo '(applied_upgrades does not contain id 181). ';
            echo 'Please upgrade to Piwigo 16.x through the upstream project first, ';
            echo 'then run this upgrade.</p>';
            exit;
        }

        ServiceLocator::get(ConfigService::class)->confUpdateParam('piwigo_db_version', AppInfo::branchFromVersion(AppInfo::VERSION));
        header('Content-Type: text/html; charset=' . ServiceLocator::get(StringUtil::class)->getPwgCharset());
        echo 'No upgrade required, the database structure is up to date';
        echo '<br><a href="index.php">← back to gallery</a>';
        exit();
    }

    /** @return string[] */
    private function getTables(): array
    {
        $tables = [];
        foreach (DbConnection::get()->executeQuery('SHOW TABLES')->fetchFirstColumn() as $tableName) {
            $tableNameStr = is_scalar($tableName) ? (string) $tableName : '';
            if (preg_match('/^' . PREFIX_TABLE . '/', $tableNameStr)) {
                $tables[] = $tableNameStr;
            }
        }
        return $tables;
    }

    /**
     * @param string[] $tables
     * @return array<string, string[]>
     */
    private function getColumnsOf(array $tables): array
    {
        $columns_of = [];
        foreach ($tables as $table) {
            $columns_of[$table] = array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                DbConnection::get()->executeQuery('DESC `' . $table . '`')->fetchFirstColumn()
            );
        }
        return $columns_of;
    }
}
