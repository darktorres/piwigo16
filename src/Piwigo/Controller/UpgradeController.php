<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Admin\Languages;
use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Kernel;
use Piwigo\Http\ResponseFactory;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the Piwigo database upgrade (/upgrade).
 * Corresponds to the former upgrade.php entry-point.
 *
 * Accessed directly via the upgrade.php shim — NOT via common.inc.php.
 * The shim loads vendor/autoload.php and ConfigLoader before calling this.
 */
final class UpgradeController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $prefixeTable = Config::dbPrefix();
        $GLOBALS['prefixeTable'] = $prefixeTable;

        if (!InstallSentinel::isInstalled()) {
            die('Piwigo is not installed yet — run install.php first.');
        }

        define('USERS_TABLE', $prefixeTable . 'users');
        require_once PHPWG_ROOT_PATH . 'include/constants.php';
        define('PREFIX_TABLE', $prefixeTable);
        define('UPGRADES_PATH', PHPWG_ROOT_PATH . 'install/db');

        require_once PHPWG_ROOT_PATH . 'include/functions.inc.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        // Upgrade always reads fresh config from DB; prevent auto_migrate loop.
        Config::override('auto_migrate', false);
        Kernel::boot();

        $languages = new Languages('utf-8');
        $get_language = isset($_GET['language']) && is_string($_GET['language']) ? $_GET['language'] : null;
        if ($get_language !== null) {
            $language = strip_tags($get_language);
            if (!in_array($language, array_keys($languages->fs_languages))) {
                $language = PHPWG_DEFAULT_LANGUAGE;
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

        if ('fr_FR' == $language)      { define('PHPWG_DOMAIN', 'fr.piwigo.org'); }
        elseif ('it_IT' == $language)  { define('PHPWG_DOMAIN', 'it.piwigo.org'); }
        elseif ('de_DE' == $language)  { define('PHPWG_DOMAIN', 'de.piwigo.org'); }
        elseif ('es_ES' == $language)  { define('PHPWG_DOMAIN', 'es.piwigo.org'); }
        elseif ('pl_PL' == $language)  { define('PHPWG_DOMAIN', 'pl.piwigo.org'); }
        elseif ('zh_CN' == $language)  { define('PHPWG_DOMAIN', 'cn.piwigo.org'); }
        elseif ('ru_RU' == $language)  { define('PHPWG_DOMAIN', 'ru.piwigo.org'); }
        elseif ('nl_NL' == $language)  { define('PHPWG_DOMAIN', 'nl.piwigo.org'); }
        elseif ('tr_TR' == $language)  { define('PHPWG_DOMAIN', 'tr.piwigo.org'); }
        elseif ('da_DK' == $language)  { define('PHPWG_DOMAIN', 'da.piwigo.org'); }
        elseif ('pt_BR' == $language)  { define('PHPWG_DOMAIN', 'br.piwigo.org'); }
        else                           { define('PHPWG_DOMAIN', 'piwigo.org'); }
        define('PHPWG_URL', 'https://' . PHPWG_DOMAIN);

        load_language('common.lang',  '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        load_language('admin.lang',   '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        load_language('install.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);
        load_language('upgrade.lang', '', ['language' => $language, 'target_charset' => 'utf-8', 'no_fallback' => true]);

        require_once PHPWG_ROOT_PATH . 'admin/include/functions_upgrade.php';
        require PHPWG_ROOT_PATH . 'include/dblayer/functions_mysqli.inc.php';

        upgrade_db_connect();

        define('CURRENT_DATE', new \DateTimeImmutable()->format('Y-m-d H:i:s'));

        $tpl = new Template(PHPWG_ROOT_PATH . 'admin/themes', 'roma');
        TemplateRegistry::set($tpl);
        $tpl->set_filenames(['upgrade' => 'upgrade.tpl']);
        $tpl->assign([
            'RELEASE'        => PHPWG_VERSION,
            'L_UPGRADE_HELP' => l10n('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum'),
        ]);

        $page = &$GLOBALS['page'];
        $has_remote_site = false;
        foreach (get_dbal_connection()->executeQuery('SELECT galleries_url FROM ' . SITES_TABLE)->fetchAllAssociative() as $row) {
            if (url_is_remote(is_scalar($row['galleries_url']) ? (string) $row['galleries_url'] : '')) {
                $has_remote_site = true;
            }
        }

        if ($has_remote_site) {
            $step = 3;
            Updates::upgrade_to('2.3.4', $step, false);

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
            ? array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . PREFIX_TABLE . 'upgrade')->fetchAllAssociative(), 'id')
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

        conf_update_param('piwigo_db_version', get_branch_from_version(PHPWG_VERSION));
        header('Content-Type: text/html; charset=' . get_pwg_charset());
        echo 'No upgrade required, the database structure is up to date';
        echo '<br><a href="index.php">← back to gallery</a>';
        exit();
    }

    /** @return string[] */
    private function getTables(): array
    {
        $tables = [];
        foreach (get_dbal_connection()->executeQuery('SHOW TABLES')->fetchFirstColumn() as $tableName) {
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
                get_dbal_connection()->executeQuery('DESC `' . $table . '`')->fetchFirstColumn()
            );
        }
        return $columns_of;
    }
}
