<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ZipExtractor;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangService;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Users\CurrentUser;

final class Plugins
{
    /** @var array<string, array<string,mixed>> */
    public array $fs_plugins = [];
    /** @var array<string, array<string,mixed>> */
    public array $db_plugins_by_id = [];
    /** @var array<int|string, array<mixed>> */
    public array $server_plugins = [];
    /** @var string[] */
    public array $default_plugins = ['LocalFilesEditor', 'language_switch', 'TakeATour', 'AdminTools'];

    /**
     * Initialize $fs_plugins and $db_plugins_by_id
     */
    public function __construct(
        private readonly AdminService $adminService,
        private readonly HtmlService $htmlService,
        private readonly LangService $langService,
        private readonly PluginRepository $pluginRepository,
        private readonly ActivityLogger $activityLogger,
    ) {
        $this->getFsPlugins();

        foreach ($this->pluginRepository->findAll() as $db_plugin) {
            if (isset($db_plugin['id']) && is_string($db_plugin['id'])) {
                $this->db_plugins_by_id[$db_plugin['id']] = $db_plugin;
            }
        }
    }

    /**
     * Returns the maintain class of a plugin
     * or build a new class with the procedural methods
     */
    private static function buildMaintainClass(string $plugin_id): PluginMaintain
    {
        $file_to_include = Config::pluginsPath() . $plugin_id . '/maintain';
        $classname = $plugin_id.'_maintain';

        // piwigo-videojs and piwigo-openstreetmap unfortunately have a "-" in their folder
        // name (=plugin_id) and a class name can't have a "-". So we have to replace with a "_"
        $classname = str_replace('-', '_', $classname);

        // 2.7 pattern (OO only)
        if (file_exists($file_to_include.'.class.php')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once($file_to_include.'.class.php');
            if (class_exists($classname) && is_a($classname, PluginMaintain::class, true)) {
                return new $classname($plugin_id); // @phpstan-ignore piwigo.noDynamicNew
            }
        }

        // before 2.7 pattern (OO only)
        if (file_exists($file_to_include.'.inc.php')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once($file_to_include.'.inc.php');

            if (class_exists($classname) && is_a($classname, PluginMaintain::class, true)) {
                return new $classname($plugin_id); // @phpstan-ignore piwigo.noDynamicNew
            }
        }

        throw new \RuntimeException("Plugin $plugin_id has no PluginMaintain class");
    }

    /**
     * Perform requested actions
     * @param string $action
     */
    /**
     * @param array<mixed> $options
     */
    public function performAction(string $action, string $plugin_id, array $options = []): mixed
    {
        if (!Config::enableExtensionsInstall() and 'delete' == $action) {
            die('Piwigo extensions install/update/delete system is disabled');
        }

        if (isset($this->db_plugins_by_id[$plugin_id])) {
            $crt_db_plugin = $this->db_plugins_by_id[$plugin_id];
        }

        $activity_details = ['plugin_id' => $plugin_id];

        $errors = [];

        switch ($action) {
            case 'install':
                if (!empty($crt_db_plugin) or !isset($this->fs_plugins[$plugin_id])) {
                    break;
                }

                $installVersion = $this->fs_plugins[$plugin_id]['version'];
                $installVersionStr = is_string($installVersion) ? $installVersion : '';
                self::buildMaintainClass($plugin_id)->install($installVersionStr, $errors);
                $activity_details['version'] = $installVersionStr;
                $errors = EventDispatcher::dispatch('plugin_install_errors', $errors);

                if (empty($errors)) {
                    $this->pluginRepository->insert($plugin_id, $installVersionStr);
                } else {
                    $activity_details['result'] = 'error';
                }
                break;

            case 'update':
                $prevVersionRaw = $this->fs_plugins[$plugin_id]['version'] ?? '';
                $previous_version = is_string($prevVersionRaw) ? $prevVersionRaw : '';
                $activity_details['from_version'] = $previous_version;
                $revisionRaw = $options['revision'] ?? '';
                $revisionStr = is_string($revisionRaw) ? $revisionRaw : '';
                $errors[0] = $this->extractPluginFiles('upgrade', $revisionStr, $plugin_id);

                if ($errors[0] === 'ok') {
                    $this->getFsPlugin($plugin_id); // refresh plugins list
                    $newVersionRaw = $this->fs_plugins[$plugin_id]['version'] ?? '';
                    $new_version = is_string($newVersionRaw) ? $newVersionRaw : '';
                    $activity_details['to_version'] = $new_version;

                    $plugin_maintain = self::buildMaintainClass($plugin_id);
                    $plugin_maintain->update($previous_version, $new_version, $errors);

                    if ($new_version != 'auto') {
                        $this->pluginRepository->updateVersion($plugin_id, $new_version);
                    }
                } else {
                    $activity_details['result'] = 'error';
                }


                break;

            case 'activate':
                if (!isset($crt_db_plugin)) {
                    $installResult = $this->performAction('install', $plugin_id);
                    $errors = is_array($installResult) ? $installResult : [];
                    $crt_db_plugin = $this->pluginRepository->findAll(null, $plugin_id)[0] ?? null;
                    ConfigService::loadConfFromDb();
                } elseif ($crt_db_plugin['state'] == 'active') {
                    break;
                }

                if (count($errors) === 0) {
                    $vRaw = is_array($crt_db_plugin) ? ($crt_db_plugin['version'] ?? null) : null;
                    $version = is_scalar($vRaw) ? (string) $vRaw : '';
                    self::buildMaintainClass($plugin_id)->activate($version, $errors);
                    $activity_details['version'] = $version;
                }

                if (count($errors) === 0) {
                    $this->pluginRepository->updateState($plugin_id, 'active');
                } else {
                    $activity_details['result'] = 'error';
                }
                break;

            case 'deactivate':
                if (!isset($crt_db_plugin) or $crt_db_plugin['state'] != 'active') {
                    $activity_details['result'] = 'error';
                    break;
                }

                $this->pluginRepository->updateState($plugin_id, 'inactive');

                self::buildMaintainClass($plugin_id)->deactivate();

                if (isset($crt_db_plugin['version'])) {
                    $activity_details['version'] = $crt_db_plugin['version'];
                }

                break;

            case 'uninstall':
                if (!isset($crt_db_plugin)) {
                    $activity_details['result'] = 'error';
                    $activity_details['error'] = 'plugin not installed';
                    break;
                }

                if (isset($crt_db_plugin['version'])) {
                    $activity_details['version'] = $crt_db_plugin['version'];
                }

                if ($crt_db_plugin['state'] == 'active') {
                    $this->performAction('deactivate', $plugin_id);
                }

                $this->pluginRepository->delete($plugin_id);

                self::buildMaintainClass($plugin_id)->uninstall();
                break;

            case 'restore':
                $this->performAction('uninstall', $plugin_id);
                unset($this->db_plugins_by_id[$plugin_id]);
                $errors = $this->performAction('activate', $plugin_id);
                break;

            case 'delete':
                if (!empty($crt_db_plugin)) {
                    if (isset($crt_db_plugin['version'])) {
                        $activity_details['db_version'] = $crt_db_plugin['version'];
                    }

                    $this->performAction('uninstall', $plugin_id);
                }
                if (!isset($this->fs_plugins[$plugin_id])) {
                    break;
                } else {
                    $activity_details['fs_version'] = $this->fs_plugins[$plugin_id]['version'];
                }

                $this->adminService->deltree(Config::pluginsPath() . $plugin_id, Config::pluginsPath() . 'trash');
                break;
        }

        $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Plugin, $action, $activity_details));

        return $errors;
    }

    /**
     * Get plugins defined in the plugin directory
     */
    public function getFsPlugins(): void
    {
        $dir = opendir(Config::pluginsPath());
        if ($dir === false) {
            return;
        }
        while ($file = readdir($dir)) {
            if ($file != '.' and $file != '..') {
                if (preg_match('/^[a-zA-Z0-9-_]+$/', $file)) {
                    $this->getFsPlugin($file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * Load metadata of a plugin in `fs_plugins` array
     * @from 2.7
     * @param $plugin_id
     * @return false|array
     */
    /** @return array<string,mixed>|false */
    public function getFsPlugin(string $plugin_id): array|false
    {
        $path = Config::pluginsPath().$plugin_id;

        if (is_dir($path) and !is_link($path)
            and file_exists($path.'/main.inc.php')
        ) {
            $plugin = [
                'name' => $plugin_id,
                'version' => '0',
                'uri' => '',
                'description' => '',
                'author' => '',
                'hasSettings' => false,
              ];
            $plg_data = file_get_contents($path.'/main.inc.php', false, null, 0, 2048);
            if ($plg_data === false) {
                return false;
            }

            if (preg_match('|Plugin Name:\\s*(.+)|', $plg_data, $val)) {
                $plugin['name'] = trim($val[1]);
            }
            if (preg_match('|Version:\\s*([\\w.-]+)|', $plg_data, $val)) {
                $plugin['version'] = trim($val[1]);
            }
            if (preg_match('|Plugin URI:\\s*(https?:\\/\\/.+)|', $plg_data, $val)) {
                $plugin['uri'] = trim($val[1]);
            }
            if (is_string($desc = $this->langService->loadLanguage('description.txt', $path.'/', ['return' => true]))) {
                $plugin['description'] = trim($desc);
            } elseif (preg_match('|Description:\\s*(.+)|', $plg_data, $val)) {
                $plugin['description'] = trim($val[1]);
            }
            if (preg_match('|Author:\\s*(.+)|', $plg_data, $val)) {
                $plugin['author'] = trim($val[1]);
            }
            if (preg_match('|Author URI:\\s*(https?:\\/\\/.+)|', $plg_data, $val)) {
                $plugin['author uri'] = trim($val[1]);
            }
            if (preg_match('/Has Settings:\\s*([Tt]rue|[Ww]ebmaster)/', $plg_data, $val)) {
                if (strtolower($val[1]) == 'webmaster') {
                    if (CurrentUser::isInitialized() && 'webmaster' == CurrentUser::get()->status) {
                        $plugin['hasSettings'] = true;
                    }
                } else {
                    $plugin['hasSettings'] = true;
                }
            }
            if ($plugin['uri'] !== '' and str_contains($plugin['uri'], 'extension_view.php?eid=')) {
                list(, $extension) = explode('extension_view.php?eid=', $plugin['uri']);
                if (is_numeric($extension)) {
                    $plugin['extension'] = $extension;
                }
            }

            // IMPORTANT SECURITY !
            $plugin = array_map(fn (bool|string $v): string|bool => is_string($v) ? htmlspecialchars($v) : $v, $plugin);
            $this->fs_plugins[$plugin_id] = $plugin;

            return $plugin;
        }

        return false;
    }

    /**
     * Sort fs_plugins
     */
    public function sortFsPlugins(string $order = 'name'): void
    {
        switch ($order) {
            case 'name':
                uasort($this->fs_plugins, $this->htmlService->nameCompare(...));
                break;
            case 'status':
                $this->sortPluginsByState();
                break;
            case 'author':
                uasort($this->fs_plugins, $this->pluginAuthorCompare(...));
                break;
            case 'id':
                uksort($this->fs_plugins, strcasecmp(...));
                break;
        }
    }

    /**
     * Return the PEM version id matching the current Piwigo branch, or empty if not on PEM yet.
     *
     * @return string[]
     */
    public function getVersionsToCheck(): array
    {
        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php?category_id='. Config::pemPluginsCategory() .'&format=php';
        $result = '';
        if ($this->adminService->fetchRemote($url, $result) and is_string($result) and $pem_versions = StringUtil::safeUnserialize($result)) {
            foreach ($pem_versions as $entry) {
                if (!is_array($entry) || !isset($entry['name'], $entry['id'])) {
                    continue;
                }
                if (AppInfo::branchFromVersion(is_scalar($entry['name']) ? (string) $entry['name'] : '') == AppInfo::branchFromVersion(AppInfo::VERSION)) {
                    $versions_to_check[] = is_scalar($entry['id']) ? (string) $entry['id'] : '';
                    break;
                }
            }
        }
        return $versions_to_check;
    }

    /**
     * Retrieve PEM server datas to $server_plugins.
     */
    public function getServerPlugins(bool $new = false): bool
    {
        $versions_to_check = $this->getVersionsToCheck();
        if (empty($versions_to_check)) {
            return true;
        }

        // Plugins to check
        $plugins_to_check = [];
        foreach ($this->fs_plugins as $fs_plugin) {
            if (isset($fs_plugin['extension'])) {
                $plugins_to_check[] = is_string($fs_plugin['extension']) ? $fs_plugin['extension'] : '';
            }
        }

        // Retrieve PEM plugins infos
        $url = PEM_URL . '/api/get_revision_list-next.php';
        $get_data = [
          'category_id' => Config::pemPluginsCategory(),
          'format' => 'php',
          'last_revision_only' => 'true',
          'version' => implode(',', $versions_to_check),
          'lang' => substr(CurrentUser::get()->language, 0, 2),
          'get_nb_downloads' => 'true',
        ];

        if (!empty($plugins_to_check)) {
            if ($new) {
                $get_data['extension_exclude'] = implode(',', $plugins_to_check);
            } else {
                $get_data['extension_include'] = implode(',', $plugins_to_check);
            }
        }
        $result = '';
        if ($this->adminService->fetchRemote($url, $result, $get_data) && is_string($result)) {
            $pem_plugins = StringUtil::safeUnserialize($result);
            if ($pem_plugins === []) {
                return false;
            }
            foreach ($pem_plugins as $plugin) {
                if (is_array($plugin) && isset($plugin['extension_id']) && (is_string($plugin['extension_id']) || is_int($plugin['extension_id']))) {
                    $this->server_plugins[$plugin['extension_id']] = $plugin;
                }
            }
            return true;
        }
        return false;
    }

    /** @return array<mixed>|false */
    public function getIncompatiblePlugins(bool $actualize = false): array|false
    {
        if (isset($_SESSION['incompatible_plugins']) and !$actualize
          and is_array($_SESSION['incompatible_plugins'])
          and isset($_SESSION['incompatible_plugins']['~~expire~~'])
          and $_SESSION['incompatible_plugins']['~~expire~~'] > time()) {
            return $_SESSION['incompatible_plugins'];
        }

        $_SESSION['incompatible_plugins'] = ['~~expire~~' => time() + 300];

        $versions_to_check = $this->getVersionsToCheck();
        if (empty($versions_to_check)) {
            return false;
        }

        // Plugins to check
        $plugins_to_check = [];
        foreach ($this->fs_plugins as $fs_plugin) {
            if (isset($fs_plugin['extension'])) {
                $plugins_to_check[] = is_string($fs_plugin['extension']) ? $fs_plugin['extension'] : '';
            }
        }

        // Retrieve PEM plugins infos
        $url = PEM_URL . '/api/get_revision_list.php';
        $get_data = [
          'category_id' => Config::pemPluginsCategory(),
          'format' => 'php',
          'version' => implode(',', $versions_to_check),
          'extension_include' => implode(',', $plugins_to_check),
        ];

        $result = '';
        if ($this->adminService->fetchRemote($url, $result, $get_data) && is_string($result)) {
            $pem_plugins = StringUtil::safeUnserialize($result);
            if ($pem_plugins === []) {
                return false;
            }

            $server_plugins = [];
            foreach ($pem_plugins as $plugin) {
                if (!is_array($plugin) || !isset($plugin['extension_id'], $plugin['revision_name'])) {
                    continue;
                }
                $eid = $plugin['extension_id'];
                if (!(is_string($eid) || is_int($eid))) {
                    continue;
                }
                if (!isset($server_plugins[$eid])) {
                    $server_plugins[$eid] = [];
                }
                $server_plugins[$eid][] = is_string($plugin['revision_name']) ? $plugin['revision_name'] : '';
            }

            foreach ($this->fs_plugins as $plugin_id => $fs_plugin) {
                $extIdPlug = $fs_plugin['extension'] ?? null;
                if (!is_string($extIdPlug) && !is_int($extIdPlug)) {
                    continue;
                }
                if (!in_array($plugin_id, $this->default_plugins)
                  and $fs_plugin['version'] != 'auto'
                  and (!isset($server_plugins[$extIdPlug]) or !in_array($fs_plugin['version'], $server_plugins[$extIdPlug]))) {
                    $_SESSION['incompatible_plugins'][$plugin_id] = $fs_plugin['version'];
                }
            }
            return $_SESSION['incompatible_plugins'];
        }
        return false;
    }

    /**
     * Sort $server_plugins
     */
    public function sortServerPlugins(string $order = 'date'): void
    {
        switch ($order) {
            case 'date':
                krsort($this->server_plugins);
                break;
            case 'revision':
                usort($this->server_plugins, fn (array $a, array $b): int => $this->extensionRevisionCompare($a, $b));
                break;
            case 'name':
                uasort($this->server_plugins, fn (array $a, array $b): int => $this->extensionNameCompare($a, $b));
                break;
            case 'author':
                uasort($this->server_plugins, fn (array $a, array $b): int => $this->extensionAuthorCompare($a, $b));
                break;
            case 'downloads':
                usort($this->server_plugins, fn (array $a, array $b): int => $this->extensionDownloadsCompare($a, $b));
                break;
        }
    }

    /**
     * Extract plugin files from archive
     * @param string $action install or upgrade
     * @param string $revision remote revision identifier
     * @param string $dest plugin id or extension id
     */
    public function extractPluginFiles(string $action, string $revision, string $dest, ?string &$plugin_id = null): string
    {
        $logger = LoggerRegistry::current();

        if (($archive = tempnam(Config::pluginsPath(), 'zip')) !== false) {
            $url = PEM_URL . '/download.php';
            $get_data = [
              'rid' => $revision,
              'origin' => 'piwigo_'.$action,
            ];

            $handle = Filesystem::tryFopen($archive, 'wb');
            if (is_resource($handle)) {
                $fh = $handle;
                if ($this->adminService->fetchRemote($url, $handle, $get_data)) {
                    fclose($fh);
                    $names = ZipExtractor::listNames($archive);
                    if ($names !== []) {
                        $main_filepath = null;
                        $status = 'ok';
                        foreach ($names as $filename) {
                            // we search main.inc.php in archive
                            if (basename($filename) == 'main.inc.php'
                              and ($main_filepath === null
                              or strlen($filename) < strlen($main_filepath))) {
                                $main_filepath = $filename;
                            }
                        }

                        $logger->debug(__FUNCTION__.', $main_filepath = '.(string) $main_filepath);

                        if (isset($main_filepath)) {
                            $root = dirname($main_filepath); // main.inc.php path in archive
                            if ($action == 'upgrade') {
                                $plugin_id = $dest;
                            } else {
                                $plugin_id = ($root == '.' ? 'extension_' . $dest : basename($root));
                            }
                            $extract_path = Config::pluginsPath() . $plugin_id;
                            $logger->debug(__FUNCTION__.', $extract_path = '.$extract_path);

                            $result = ZipExtractor::extract($archive, $extract_path, $root === '.' ? '' : $root);
                            if ($result !== []) {
                                foreach ($result as $file) {
                                    if ($file['stored_filename'] === $main_filepath) {
                                        $status = $file['status'];
                                        break;
                                    }
                                }
                                if (file_exists($extract_path.'/obsolete.list')
                                  and ($old_files = file($extract_path.'/obsolete.list', FILE_IGNORE_NEW_LINES)) !== false) {
                                    $old_files[] = 'obsolete.list';
                                    $logger->debug(__FUNCTION__.', $old_files = {'.join('},{', $old_files).'}');

                                    $extract_path_realpath = realpath($extract_path);

                                    foreach ($old_files as $old_file) {
                                        $old_file = trim($old_file);
                                        $old_file = trim($old_file, '/'); // prevent path starting with a "/"

                                        if (empty($old_file)) { // empty here means the extension itself
                                            continue;
                                        }

                                        $path = $extract_path.'/'.$old_file;

                                        // make sure the obsolete file is withing the extension directory, prevent traversal path
                                        $realpath = realpath($path);
                                        if ($realpath === false or $extract_path_realpath === false or !str_starts_with($realpath, $extract_path_realpath)) {
                                            continue;
                                        }

                                        $logger->debug(__FUNCTION__.', to delete = '.$path);

                                        if (is_file($path)) {
                                            Filesystem::tryUnlink($path);
                                        } elseif (is_dir($path)) {
                                            $this->adminService->deltree($path, Config::pluginsPath() . 'trash');
                                        }
                                    }
                                }
                            } else {
                                $status = 'extract_error';
                            }
                        } else {
                            $status = 'archive_error';
                        }
                    } else {
                        $status = 'archive_error';
                    }
                } else {
                    $status = 'dl_archive_error';
                }
            } else {
                $status = 'dl_archive_error';
            }
        } else {
            $status = 'temp_path_error';
        }

        if (is_string($archive)) {
            Filesystem::tryUnlink($archive);
        }
        return $status;
    }

    /**
     * @return string[]
     */
    /** @return array<mixed> */
    public function getMergedExtensions(string $version = AppInfo::VERSION): array
    {
        $file = PHPWG_ROOT_PATH.'install/obsolete_extensions.list';
        $merged_extensions = [];

        if (file_exists($file) and ($obsolete_ext = file($file, FILE_IGNORE_NEW_LINES)) !== false) {
            foreach ($obsolete_ext as $ext) {
                if (preg_match('/^(\d+) ?: ?(.*?)$/', $ext, $matches)) {
                    $merged_extensions[$matches[1]] = $matches[2];
                }
            }
        }
        return $merged_extensions;
    }

    /**
     * Sort functions
     */
    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extensionRevisionCompare(array $a, array $b): int
    {
        if ($a['revision_date'] < $b['revision_date']) {
            return 1;
        } else {
            return -1;
        }
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extensionNameCompare(array $a, array $b): int
    {
        $na = $a['extension_name'] ?? null;
        $nb = $b['extension_name'] ?? null;
        return strcmp(strtolower(is_scalar($na) ? (string) $na : ''), strtolower(is_scalar($nb) ? (string) $nb : ''));
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extensionAuthorCompare(array $a, array $b): int
    {
        $na = $a['author_name'] ?? null;
        $nb = $b['author_name'] ?? null;
        $r = strcasecmp(is_scalar($na) ? (string) $na : '', is_scalar($nb) ? (string) $nb : '');
        if ($r == 0) {
            return $this->extensionNameCompare($a, $b);
        } else {
            return $r;
        }
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function pluginAuthorCompare(array $a, array $b): int
    {
        $na = $a['author'] ?? null;
        $nb = $b['author'] ?? null;
        $r = strcasecmp(is_scalar($na) ? (string) $na : '', is_scalar($nb) ? (string) $nb : '');
        if ($r == 0) {
            return $this->htmlService->nameCompare($a, $b);
        } else {
            return $r;
        }
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extensionDownloadsCompare(array $a, array $b): int
    {
        if ($a['extension_nb_downloads'] < $b['extension_nb_downloads']) {
            return 1;
        } else {
            return -1;
        }
    }

    public function sortPluginsByState(): void
    {
        uasort($this->fs_plugins, $this->htmlService->nameCompare(...));

        $active_plugins = [];
        $inactive_plugins = [];
        $not_installed = [];

        foreach ($this->fs_plugins as $plugin_id => $plugin) {
            if (isset($this->db_plugins_by_id[$plugin_id])) {
                $this->db_plugins_by_id[$plugin_id]['state'] == 'active' ?
                  $active_plugins[$plugin_id] = $plugin : $inactive_plugins[$plugin_id] = $plugin;
            } else {
                $not_installed[$plugin_id] = $plugin;
            }
        }
        $this->fs_plugins = $active_plugins + $inactive_plugins + $not_installed;
    }
}
