<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Piwigo\Admin\languages;
use Piwigo\Admin\updates;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * upgrade.php's orchestration, ported verbatim from that script's former
 * top-level code (P23 sub-batch 8f-6). The upgrade.php entry shell keeps
 * only bootstrap (config/database includes, the SEC-60-constrained
 * define()s, session/renderer wiring, DB connection) and drives this
 * runner: loadLanguage() -> prepare() -> [shell: access-rights check +
 * PHPWG_IN_UPGRADE define] -> performUpgrade() or renderIntro() ->
 * finish().
 *
 * Every method declares the globals the former top-level scope shared with
 * the code it runs: the frozen install/upgrade_X.Y.Z.php scripts included
 * by performUpgrade() execute in that method's variable scope, so their
 * bare $conf/$prefixeTable/$last_time references only keep resolving to the
 * true globals because of those declarations (the recurring
 * "method-scoped include breaks bare global bootstrap vars" bug class).
 */
class UpgradeRunner
{
    private string $language = '';

    private string $currentRelease = '';

    public function __construct(
        private readonly string $configFile,
        private readonly string $configFileContents,
        private readonly int $phpEndTag,
    ) {}

    /**
     * Language pick + Lang loads (former upgrade.php "language" section).
     * Runs before the database connection on purpose, exactly like the old
     * top-level order: a failed connection must die with an already
     * localized message.
     */
    public function loadLanguage(): void
    {
        $languages = new languages('utf-8');
        if (isset($_GET['language'])) {
            $language = is_string($_GET['language']) ? strip_tags($_GET['language']) : '';

            if (! in_array($language, array_keys($languages->fs_languages))) {
                $language = AppInfo::DEFAULT_LANGUAGE;
            }
        } else {
            $language = 'en_UK';
            // Try to get browser language
            $http_accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
            $http_accept_language = is_string($http_accept_language) ? $http_accept_language : '';
            foreach ($languages->fs_languages as $language_code => $fs_language) {
                if (substr($language_code, 0, 2) == substr($http_accept_language, 0, 2)) {
                    $language = $language_code;
                    break;
                }
            }
        }

        Lang::load('common.lang', '', [
            'language' => $language,
            'no_fallback' => true,
        ]);
        Lang::load('admin.lang', '', [
            'language' => $language,
            'no_fallback' => true,
        ]);
        Lang::load('install.lang', '', [
            'language' => $language,
            'no_fallback' => true,
        ]);
        Lang::load('upgrade.lang', '', [
            'language' => $language,
            'no_fallback' => true,
        ]);

        $this->language = $language;
    }

    /**
     * Template init, remote-site refusal, current-release detection
     * (former upgrade.php "template initialization" through "upgrade
     * launch" preamble). May exit(): remote sites, or an already
     * up-to-date database.
     *
     * @return string the detected current release, for the entry shell's
     *                checkUpgradeAccessRights() call
     */
    public function prepare(): string
    {
        global $template;

        // +-------------------------------------------------------------------+
        // |                        template initialization                     |
        // +-------------------------------------------------------------------+

        $template = new Template(PHPWG_ROOT_PATH . 'admin/themes', 'clear');
        \Piwigo\Template\CurrentTemplate::set($template);
        $template->set_filenames([
            'upgrade' => 'upgrade.tpl',
        ]);
        $template->assign(
            [
                'RELEASE' => AppInfo::VERSION,
                'L_UPGRADE_HELP' => l10n('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', PHPWG_URL . '/forum'),
            ]
        );

        // +-------------------------------------------------------------------+
        // | Remote sites are not compatible with Piwigo 2.4+                   |
        // +-------------------------------------------------------------------+

        $has_remote_site = false;

        $query = 'SELECT galleries_url FROM ' . Tables::sites() . ';';
        $result = \Piwigo\Db\MysqliDb::query($query);
        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
            $galleries_url = $row['galleries_url'] ?? null;
            if (is_string($galleries_url) && url_is_remote($galleries_url)) {
                $has_remote_site = true;
            }
        }

        if ($has_remote_site) {
            \Piwigo\Core\PageState::current()->errors = [];
            $step = 3;
            updates::upgrade_to('2.3.4', $step, false);

            $upgrade_errors = \Piwigo\Core\PageState::current()->errors;
            if (! empty($upgrade_errors)) {
                echo '<ul>';
                foreach ($upgrade_errors as $error) {
                    echo '<li>' . $error . '</li>';
                }
                echo '</ul>';
            }

            exit();
        }

        // +-------------------------------------------------------------------+
        // |                            upgrade choice                          |
        // +-------------------------------------------------------------------+

        $tables = UpgradeService::getTables();
        $columns_of = UpgradeService::getColumnsOf($tables);

        // find the current release
        if (! in_array('param', $columns_of[PREFIX_TABLE . 'config'])) {
            // we're in branch 1.3, important upgrade, isn't it?
            if (in_array(PREFIX_TABLE . 'user_category', $tables)) {
                $current_release = '1.3.1';
            } else {
                $current_release = '1.3.0';
            }
        } elseif (! in_array(PREFIX_TABLE . 'user_cache', $tables)) {
            $current_release = '1.4.0';
        } elseif (! in_array(PREFIX_TABLE . 'tags', $tables)) {
            $current_release = '1.5.0';
        } elseif (! in_array(PREFIX_TABLE . 'plugins', $tables)) {
            if (! in_array('auto_login_key', $columns_of[PREFIX_TABLE . 'user_infos'])) {
                $current_release = '1.6.0';
            } else {
                $current_release = '1.6.2';
            }
        } elseif (! in_array('md5sum', $columns_of[PREFIX_TABLE . 'images'])) {
            $current_release = '1.7.0';
        } elseif (! in_array(PREFIX_TABLE . 'themes', $tables)) {
            $current_release = '2.0.0';
        } elseif (! in_array('added_by', $columns_of[PREFIX_TABLE . 'images'])) {
            $current_release = '2.1.0';
        } elseif (! in_array('rating_score', $columns_of[PREFIX_TABLE . 'images'])) {
            $current_release = '2.2.0';
        } elseif (! in_array('rotation', $columns_of[PREFIX_TABLE . 'images'])) {
            $current_release = '2.3.0';
        } elseif (! in_array('website_url', $columns_of[PREFIX_TABLE . 'comments'])) {
            $current_release = '2.4.0';
        } elseif (! in_array('nb_available_tags', $columns_of[PREFIX_TABLE . 'user_cache'])) {
            $current_release = '2.5.0';
        } elseif (! in_array('activation_key_expire', $columns_of[PREFIX_TABLE . 'user_infos'])) {
            $current_release = '2.6.0';
        } elseif (! in_array('auth_key_id', $columns_of[PREFIX_TABLE . 'history'])) {
            $current_release = '2.7.0';
        } elseif (! in_array('history_id_to', $columns_of[PREFIX_TABLE . 'history_summary'])) {
            $current_release = '2.8.0';
        } elseif (! in_array(PREFIX_TABLE . 'activity', $tables)) {
            $current_release = '2.9.0';
        } else {
            // retrieve already applied upgrades
            $query = '
SELECT id
  FROM ' . PREFIX_TABLE . 'upgrade
;';
            $applied_upgrades = \Piwigo\Db\MysqliDb::query2Array($query, null, 'id');

            if (! in_array(159, $applied_upgrades)) {
                $current_release = '2.10.0';
            } elseif (! in_array(162, $applied_upgrades)) {
                $current_release = '11.0.0';
            } elseif (! in_array(164, $applied_upgrades)) {
                $current_release = '12.0.0';
            } elseif (! in_array(170, $applied_upgrades)) {
                $current_release = '13.0.0';
            } elseif (! in_array(174, $applied_upgrades)) {
                $current_release = '14.0.0';
            } elseif (! in_array(181, $applied_upgrades)) {
                $current_release = '15.0.0';
            } else {
                // confirm that the database is in the same version as source code files
                \Piwigo\Config\ConfigDb::confUpdateParam('piwigo_db_version', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION));

                header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
                echo 'No upgrade required, the database structure is up to date';
                echo '<br><a href="index.php">← back to gallery</a>';
                exit();
            }
        }

        // +-------------------------------------------------------------------+
        // |                            upgrade launch                          |
        // +-------------------------------------------------------------------+

        \Piwigo\Core\PageState::current()->infos = [];
        \Piwigo\Core\PageState::current()->errors = [];

        // check php version
        if (version_compare(PHP_VERSION, AppInfo::REQUIRED_PHP_VERSION, '<')) {
            \Piwigo\Core\PageState::current()->addError(l10n('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION));
        }

        $this->currentRelease = $current_release;

        return $current_release;
    }

    /**
     * Runs the actual database upgrade (former upgrade.php POST/now launch
     * branch). Only called by the entry shell once check_upgrade passed --
     * i.e. checkUpgradeAccessRights() authorized the requester and the
     * shell define()d PHPWG_IN_UPGRADE (the auth gate; never weakened).
     *
     * The frozen install/upgrade_X.Y.Z.php script (and, transitively, the
     * frozen install/db/*.php scripts it includes) executes inside this
     * method's scope: the global declarations below are what keep its bare
     * top-level $conf/$prefixeTable/$last_time references pointing at the
     * true globals, exactly as when this code ran at upgrade.php's own
     * top level. $persistent_cache must be a real global too --
     * UserCacheInvalidator::invalidate() reads `global $persistent_cache`.
     */
    public function performUpgrade(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var string
         */
        global $prefixeTable;
        global $template;
        global $persistent_cache;
        global $last_time;

        if (\Piwigo\Admin\Install\VersionUpgrade\VersionUpgradeRegistry::has($this->currentRelease)) {
            // reset SQL counters
            \Piwigo\Core\PageState::current()->resetQueryCounters();

            $upgrade_start = \Piwigo\Core\TimingHelper::getMoment();
            $conf['die_on_sql_error'] = false;
            \Piwigo\Config\Config::override('die_on_sql_error', false);
            // P23 sub-batch 8g-4: the former `include install/upgrade_
            // <release>.php` (whose chain of scripts array_push()ed onto a
            // $mysql_changes local in THIS scope, harvested back via
            // get_defined_vars()) is now the VersionUpgrade class chain;
            // steps push database.inc.php snippets through the
            // DatabaseConfigChanges collector instead.
            \Piwigo\Admin\Install\VersionUpgrade\VersionUpgradeRegistry::make($this->currentRelease)
                ->apply();
            $mysql_changes = \Piwigo\Admin\Install\DbPatch\DatabaseConfigChanges::drain();

            \Piwigo\Config\ConfigDb::confUpdateParam('piwigo_db_version', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION));

            // Conf delete param on last major update for whats new popin to be displayed when changing major version
            \Piwigo\Config\ConfigDb::confDeleteParam('last_major_update');

            // Something to add in database.inc.php? (install/upgrade_*.php
            // scripts may push onto $mysql_changes)
            if (count($mysql_changes) > 0) {
                $config_file_contents =
                  substr($this->configFileContents, 0, $this->phpEndTag) . "\r\n"
                  . implode("\r\n", $mysql_changes) . "\r\n"
                  . substr($this->configFileContents, $this->phpEndTag);

                if (! (bool) @file_put_contents($this->configFile, $config_file_contents)) {
                    \Piwigo\Core\PageState::current()->addInfo(l10n(
                        'In <i>%s</i>, before <b>?></b>, insert:',
                        PWG_LOCAL_DIR . 'config/database.inc.php'
                    )
                    . '<p><textarea rows="4" cols="40">'
                    . implode("\r\n", $mysql_changes) . '</textarea></p>');
                }
            }

            // Deactivate non standard extensions
            UpgradeService::deactivateNonStandardPlugins();
            UpgradeService::deactivateNonStandardThemes();
            UpgradeService::deactivateTemplates();

            $upgrade_end = \Piwigo\Core\TimingHelper::getMoment();

            $template->assign(
                'upgrade',
                [
                    'VERSION' => $this->currentRelease,
                    'TOTAL_TIME' => \Piwigo\Core\TimingHelper::getElapsedTime($upgrade_start, $upgrade_end),
                    'SQL_TIME' => number_format(
                        \Piwigo\Core\PageState::current()->queriesTime,
                        3,
                        '.',
                        ' '
                    ) . ' s',
                    'NB_QUERIES' => \Piwigo\Core\PageState::current()->countQueries,
                ]
            );

            \Piwigo\Core\PageState::current()->addInfo(l10n('Perform a maintenance check in [Administration>Tools>Maintenance] if you encounter any problem.'));

            // Save PageState's infos in order to restore after maintenance actions
            $infos_sav = \Piwigo\Core\PageState::current()->infos;
            \Piwigo\Core\PageState::current()->infos = [];

            $template->assign(
                [
                    'button_label' => l10n('Home'),
                    'button_link' => 'index.php',
                ]
            );

            // if the webmaster has a session, let's give a link to discover new features
            if (! empty($_SESSION['pwg_uid'])) {
                $version_ = str_replace('.', '_', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.0');

                if (file_exists(\Piwigo\Admin\PluginLoader::pluginsPath() . 'TakeATour/tours/' . $version_ . '/config.inc.php')) {
                    $query = '
REPLACE INTO ' . Tables::plugins() . '
  (id, state)
  VALUES (\'TakeATour\', \'active\')
;';
                    \Piwigo\Db\MysqliDb::query($query);

                    // we need the secret key for get_pwg_token()
                    \Piwigo\Config\ConfigDb::loadConfFromDb();

                    $template->assign(
                        [
                            'button_label' => l10n('Discover what\'s new in Piwigo %s', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION)),
                            'button_link' => 'admin.php?submited_tour_path=tours/' . $version_ . '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                        ]
                    );
                }
            }

            if (! isset($_SESSION['connected_with'])) {
                $_SESSION['connected_with'] = 'pwg_ui';
            }

            // Delete cache data
            // invalidate_user_cache will purge persistent_cache so it needs to be instantiated first
            $persistent_cache = new PersistentFileCache();

            UserCacheInvalidator::invalidate(true);
            $template->delete_compiled_templates();

            // Restore PageState's infos in order to hide informations messages from functions calles
            // errors messages are not hide
            \Piwigo\Core\PageState::current()->infos = $infos_sav;

        }
    }

    /**
     * Language/introduction form (former upgrade.php "start template
     * output" else-branch). The `defined('PWG_CHARSET') or define(...)`
     * that used to open this branch stays in the upgrade.php entry shell
     * (SEC-60 forbids define() in src/Piwigo), at the exact same branch
     * position.
     */
    public function renderIntro(): void
    {
        global $template;

        $languages = new languages();

        $languages_options = [];
        foreach ($languages->fs_languages as $language_code => $fs_language) {
            if ($this->language == $language_code) {
                $template->assign('language_selection', $language_code);
            }
            $languages_options[$language_code] = $fs_language['name'];
        }
        $template->assign('language_options', $languages_options);

        $template->assign('introduction', [
            'CURRENT_RELEASE' => $this->currentRelease,
            'F_ACTION' => 'upgrade.php?language=' . $this->language,
        ]);

        if (! UpgradeService::checkUpgrade()) {
            $template->assign('login', true);
        }
    }

    /**
     * Errors/infos assignment + final template output (former upgrade.php
     * closing section).
     */
    public function finish(): void
    {
        global $template;

        $page_errors = \Piwigo\Core\PageState::current()->errors;
        if (count($page_errors) != 0) {
            $template->assign('errors', $page_errors);
        }

        $page_infos = \Piwigo\Core\PageState::current()->infos;
        if (count($page_infos) != 0) {
            $template->assign('infos', $page_infos);
        }

        $template->pparse('upgrade');
    }
}
