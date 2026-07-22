<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;

/**
 * upgrade.php's orchestration, ported verbatim from that script's former
 * top-level code (P23 sub-batch 8f-6). The upgrade.php entry shell keeps
 * only bootstrap (config/database includes, session/renderer wiring, DB
 * connection) and drives this runner: loadLanguage() -> prepare() ->
 * [shell: access-rights check] -> performUpgrade() or
 * renderIntro($isAuthorized) -> finish(). Legacy Coupling Retirement
 * gap-closure (install/upgrade-flow constants round): the access-rights
 * result used to round-trip through a `define('PHPWG_IN_UPGRADE', ...)`
 * the shell set and `UpgradeService::checkUpgrade()` re-read -- the
 * shell already has the boolean in scope from its own
 * `checkUpgradeAccessRights()` call, so it's now passed straight into
 * `renderIntro()` as a parameter instead (`checkUpgrade()` itself is
 * gone, it had exactly these two callers).
 *
 * Every method declares the $template global the code it calls still
 * needs -- updates/mail-era template helpers historically resolve the
 * shared instance through the global. performUpgrade()'s
 * PersistentFileCache is published via Piwigo\Cache\
 * CurrentPersistentCache::set() instead (Legacy Coupling Retirement
 * Track A gap-fill batch G5), which UserCacheInvalidator::invalidate()
 * reads. The former install/upgrade_X.Y.Z.php and
 * install/db/*.php scripts are no longer raw includes sharing this
 * method's scope -- P23 sub-batch 8g ported them to real
 * VersionUpgrade/DbPatch classes, each with its own independent
 * `global $conf, $prefixeTable;` declarations, so this method no longer
 * needs to declare those two itself (Legacy Coupling Retirement Track A
 * gap-fill batch G3).
 *
 * Legacy Coupling Retirement Phase 8, 8b: upgrade.php now calls
 * InstallBootstrap::boot($paths) early (before this class is even
 * constructed), so the DI container is available by the time this class's
 * methods run -- and (Phase 8, 8d) upgrade.php also calls
 * InstallBootstrap::activateConfigService() right after its own db_*
 * credential seeding, so every real ConfigDb:: call here has been
 * retargeted onto CurrentConfigService::get().
 */
final class UpgradeRunner
{
    private string $language = '';

    private string $currentRelease = '';

    public function __construct(
        private readonly string $configFile,
        private readonly string $configFileContents,
        private readonly int $phpEndTag,
        private readonly Paths $paths,
    ) {}

    /**
     * Language pick + Lang loads (former upgrade.php "language" section).
     * Runs before the database connection on purpose, exactly like the old
     * top-level order: a failed connection must die with an already
     * localized message.
     */
    public function loadLanguage(): void
    {
        // This request goes through InstallBootstrap::boot() (Legacy
        // Coupling Retirement Phase 8, 8b) but never CommonBootstrap::run(),
        // so CurrentUser is never guest-initialized either -- same latent
        // crash as InstallWizard::boot()'s own attachGlobals() call (see its
        // docblock): UpgradeService::deactivateNonStandardThemes() can
        // reach ExtensionScanner::scan()'s missing-screenshot fallback,
        // which reads CurrentUser. Idempotent, so calling it this early
        // (before the DB connection) is safe.
        \Piwigo\Users\CurrentUser::attachGlobals();

        // Same no-boot gap, CurrentLogger this time -- same construction
        // recipe as RequestBootstrap::connect()'s own site. No DB access
        // needed, just Config reads (already valid from database.inc.php,
        // included by the upgrade.php entry shell before this runs) --
        // direct calls, so their declared `string` return types are
        // already certain, no is_string() re-guard needed.
        \Piwigo\Core\CurrentLogger::set(new Logger([
            'directory' => $this->paths->root . \Piwigo\Config\Config::dataLocation() . \Piwigo\Config\Config::logDir(),
            'severity' => \Piwigo\Config\Config::logLevel(),
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . \Piwigo\Config\Config::dbPassword()) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => \Piwigo\Config\Config::logArchiveDays(),
        ]));

        $fs_languages = new ExtensionScanner()
            ->scan(ExtensionType::Language, new UrlService(new HtmlService()), 'utf-8');
        if (isset($_GET['language'])) {
            $language = is_string($_GET['language']) ? strip_tags($_GET['language']) : '';

            if (! in_array($language, array_keys($fs_languages))) {
                $language = AppInfo::DEFAULT_LANGUAGE;
            }
        } else {
            $language = 'en_UK';
            // Try to get browser language
            $http_accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
            $http_accept_language = is_string($http_accept_language) ? $http_accept_language : '';
            foreach ($fs_languages as $language_code => $fs_language) {
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
    public function prepare(Connection $conn): string
    {
        // +-------------------------------------------------------------------+
        // |                        template initialization                     |
        // +-------------------------------------------------------------------+

        $template = new Template($this->paths->root . 'admin/themes', 'clear');
        \Piwigo\Template\CurrentTemplate::set($template);
        $template->set_filenames([
            'upgrade' => 'upgrade.tpl',
        ]);
        $template->assign(
            [
                'RELEASE' => AppInfo::VERSION,
                'L_UPGRADE_HELP' => Lang::t('Need help ? Ask your question on <a href="%s">Piwigo message board</a>.', AppInfo::URL . '/forum'),
            ]
        );

        // +-------------------------------------------------------------------+
        // | Remote sites are not compatible with Piwigo 2.4+                   |
        // +-------------------------------------------------------------------+

        $has_remote_site = false;

        $query = 'SELECT galleries_url FROM ' . Tables::sites() . ';';
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $galleries_url = $row['galleries_url'] ?? null;
            if (is_string($galleries_url) && new UrlService(new HtmlService())->urlIsRemote($galleries_url)) {
                $has_remote_site = true;
            }
        }

        if ($has_remote_site) {
            \Piwigo\Core\PageState::current()->errors = [];
            $step = 3;
            new CoreUpdateService(new ZipExtractor(), new RedirectService(), new UrlService(new HtmlService()), CurrentConfigService::get(), \Piwigo\Core\CurrentPaths::get())
                ->upgradeTo('2.3.4', $step, false);

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

        $tables = UpgradeService::getTables($conn);
        $columns_of = UpgradeService::getColumnsOf($conn, $tables);

        // Legacy Coupling Retirement gap-closure (install/upgrade-flow
        // constants round): PREFIX_TABLE used to be a SEC-60 global the
        // entry shell define()d -- this class is real, already-DI-shaped
        // src/Piwigo/ code, so it reads Config::dbPrefix() directly like
        // everything else in the codebase instead.
        $dbPrefix = \Piwigo\Config\Config::dbPrefix();

        // find the current release
        if (! in_array('param', $columns_of[$dbPrefix . 'config'])) {
            // we're in branch 1.3, important upgrade, isn't it?
            if (in_array($dbPrefix . 'user_category', $tables)) {
                $current_release = '1.3.1';
            } else {
                $current_release = '1.3.0';
            }
        } elseif (! in_array($dbPrefix . 'user_cache', $tables)) {
            $current_release = '1.4.0';
        } elseif (! in_array($dbPrefix . 'tags', $tables)) {
            $current_release = '1.5.0';
        } elseif (! in_array($dbPrefix . 'plugins', $tables)) {
            if (! in_array('auto_login_key', $columns_of[$dbPrefix . 'user_infos'])) {
                $current_release = '1.6.0';
            } else {
                $current_release = '1.6.2';
            }
        } elseif (! in_array('md5sum', $columns_of[$dbPrefix . 'images'])) {
            $current_release = '1.7.0';
        } elseif (! in_array($dbPrefix . 'themes', $tables)) {
            $current_release = '2.0.0';
        } elseif (! in_array('added_by', $columns_of[$dbPrefix . 'images'])) {
            $current_release = '2.1.0';
        } elseif (! in_array('rating_score', $columns_of[$dbPrefix . 'images'])) {
            $current_release = '2.2.0';
        } elseif (! in_array('rotation', $columns_of[$dbPrefix . 'images'])) {
            $current_release = '2.3.0';
        } elseif (! in_array('website_url', $columns_of[$dbPrefix . 'comments'])) {
            $current_release = '2.4.0';
        } elseif (! in_array('nb_available_tags', $columns_of[$dbPrefix . 'user_cache'])) {
            $current_release = '2.5.0';
        } elseif (! in_array('activation_key_expire', $columns_of[$dbPrefix . 'user_infos'])) {
            $current_release = '2.6.0';
        } elseif (! in_array('auth_key_id', $columns_of[$dbPrefix . 'history'])) {
            $current_release = '2.7.0';
        } elseif (! in_array('history_id_to', $columns_of[$dbPrefix . 'history_summary'])) {
            $current_release = '2.8.0';
        } elseif (! in_array($dbPrefix . 'activity', $tables)) {
            $current_release = '2.9.0';
        } else {
            // retrieve already applied upgrades
            $query = '
SELECT id
  FROM ' . Tables::upgrade() . '
;';
            // Loose in_array() below tolerates either native int or string
            // ids from the DBAL fetch, same as it always tolerated mysqli's
            // all-string rows -- no explicit per-element cast needed.
            $applied_upgrades = $conn->fetchFirstColumn($query);

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
                \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('piwigo_db_version', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION));

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
            \Piwigo\Core\PageState::current()->addError(Lang::t('PHP version %s required (you are running on PHP %s)', AppInfo::REQUIRED_PHP_VERSION, PHP_VERSION));
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
     * The VersionUpgrade/DbPatch class chain applied below (P23 sub-batch
     * 8g) each declares its own `global $conf, $prefixeTable;` where
     * needed, so this method itself only needs $template (assigned into
     * further down); the PersistentFileCache instance built further down
     * publishes to Piwigo\Cache\CurrentPersistentCache, which
     * UserCacheInvalidator::invalidate() reads (Legacy Coupling
     * Retirement Track A gap-fill batch G5).
     */
    public function performUpgrade(Connection $conn): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (\Piwigo\Admin\Install\VersionUpgrade\VersionUpgradeRegistry::has($this->currentRelease)) {
            // reset SQL counters
            \Piwigo\Core\PageState::current()->resetQueryCounters();

            $upgrade_start = \Piwigo\Core\TimingHelper::getMoment();
            \Piwigo\Config\Config::override('die_on_sql_error', false);
            // P23 sub-batch 8g-4: the former `include install/upgrade_
            // <release>.php` (whose chain of scripts array_push()ed onto a
            // $mysql_changes local in THIS scope, harvested back via
            // get_defined_vars()) is now the VersionUpgrade class chain;
            // steps push database.inc.php snippets through the
            // DatabaseConfigChanges collector instead.
            \Piwigo\Admin\Install\VersionUpgrade\VersionUpgradeRegistry::make($this->currentRelease)
                ->apply($conn);
            $mysql_changes = \Piwigo\Admin\Install\DbPatch\DatabaseConfigChanges::drain();

            \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('piwigo_db_version', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION));

            // Conf delete param on last major update for whats new popin to be displayed when changing major version
            \Piwigo\Config\CurrentConfigService::get()->confDeleteParam('last_major_update');

            // Something to add in database.inc.php? (install/upgrade_*.php
            // scripts may push onto $mysql_changes)
            if (count($mysql_changes) > 0) {
                $config_file_contents =
                  substr($this->configFileContents, 0, $this->phpEndTag) . "\r\n"
                  . implode("\r\n", $mysql_changes) . "\r\n"
                  . substr($this->configFileContents, $this->phpEndTag);

                if (! (bool) @file_put_contents($this->configFile, $config_file_contents)) {
                    // Relative directory name for display -- same
                    // root-prefix-stripping shape as Template::
                    // prefilter_local_css()'s own siteLocalDir computation.
                    $siteLocalDir = substr($this->paths->siteLocal, strlen($this->paths->root));
                    \Piwigo\Core\PageState::current()->addInfo(Lang::t(
                        'In <i>%s</i>, before <b>?></b>, insert:',
                        $siteLocalDir . 'config/database.inc.php'
                    )
                    . '<p><textarea rows="4" cols="40">'
                    . implode("\r\n", $mysql_changes) . '</textarea></p>');
                }
            }

            // Deactivate non standard extensions
            UpgradeService::deactivateNonStandardPlugins($conn);
            UpgradeService::deactivateNonStandardThemes($conn);
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

            \Piwigo\Core\PageState::current()->addInfo(Lang::t('Perform a maintenance check in [Administration>Tools>Maintenance] if you encounter any problem.'));

            // Save PageState's infos in order to restore after maintenance actions
            $infos_sav = \Piwigo\Core\PageState::current()->infos;
            \Piwigo\Core\PageState::current()->infos = [];

            $template->assign(
                [
                    'button_label' => Lang::t('Home'),
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
                    $conn->executeStatement($query);

                    // we need the secret key for get_pwg_token()
                    \Piwigo\Config\CurrentConfigService::get()->loadConfFromDb();

                    $template->assign(
                        [
                            'button_label' => Lang::t('Discover what\'s new in Piwigo %s', \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION)),
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
            \Piwigo\Cache\CurrentPersistentCache::set(new PersistentFileCache());

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
     * position. $isAuthorized is the same `checkUpgradeAccessRights()`
     * result the entry shell already computed for its own access-gate
     * check just above this call -- see this class's own docblock for
     * why it's a parameter now instead of the former
     * `UpgradeService::checkUpgrade()` re-read.
     */
    public function renderIntro(bool $isAuthorized): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $fs_languages = new ExtensionScanner()
            ->scan(ExtensionType::Language, new UrlService(new HtmlService()));

        $languages_options = [];
        foreach ($fs_languages as $language_code => $fs_language) {
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

        if (! $isAuthorized) {
            $template->assign('login', true);
        }
    }

    /**
     * Errors/infos assignment + final template output (former upgrade.php
     * closing section).
     */
    public function finish(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

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
