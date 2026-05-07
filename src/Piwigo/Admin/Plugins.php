<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Users\CurrentUser;

class Plugins
{
    /** @var array<string, array<string,mixed>> */
    public array $fs_plugins = [];
    /** @var array<string, array<string,mixed>> */
    public array $db_plugins_by_id = [];
    /** @var array<int|string, array<string, mixed>> */
    public array $server_plugins = [];
    /** @var string[] */
    public array $default_plugins = ['LocalFilesEditor', 'language_switch', 'TakeATour', 'AdminTools'];

    /**
     * Initialize $fs_plugins and $db_plugins_by_id
     */
    public function __construct()
    {
        $this->getFsPlugins();

        foreach (ServiceLocator::get(PluginRepository::class)->findAll() as $db_plugin) {
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
        $file_to_include = PHPWG_PLUGINS_PATH . $plugin_id . '/maintain';
        $classname = $plugin_id.'_maintain';

        // piwigo-videojs and piwigo-openstreetmap unfortunately have a "-" in their folder
        // name (=plugin_id) and a class name can't have a "-". So we have to replace with a "_"
        $classname = str_replace('-', '_', $classname);

        // 2.7 pattern (OO only)
        if (file_exists($file_to_include.'.class.php')) {
            require_once($file_to_include.'.class.php');
            if (class_exists($classname) && is_a($classname, PluginMaintain::class, true)) {
                return new $classname($plugin_id); // @phpstan-ignore piwigo.noDynamicNew
            }
        }

        // before 2.7 pattern (OO only)
        if (file_exists($file_to_include.'.inc.php')) {
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
                $errors = trigger_change('plugin_install_errors', $errors);

                if (empty($errors)) {
                    ServiceLocator::get(PluginRepository::class)->insert($plugin_id, $installVersionStr);
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
                        ServiceLocator::get(PluginRepository::class)->updateVersion($plugin_id, $new_version);
                    }
                } else {
                    $activity_details['result'] = 'error';
                }


                break;

            case 'activate':
                if (!isset($crt_db_plugin)) {
                    $errors = $this->performAction('install', $plugin_id);
                    [$crt_db_plugin] = ServiceLocator::get(PluginRepository::class)->findAll(null, $plugin_id);
                    load_conf_from_db();
                } elseif ($crt_db_plugin['state'] == 'active') {
                    break;
                }

                if (empty($errors)) {
                    $vRaw = $crt_db_plugin['version'] ?? null;
                    $version = is_scalar($vRaw) ? (string) $vRaw : '';
                    $errorsArr = is_array($errors) ? $errors : [];
                    self::buildMaintainClass($plugin_id)->activate($version, $errorsArr);
                    $errors = $errorsArr;
                    $activity_details['version'] = $version;
                }

                if (empty($errors)) {
                    ServiceLocator::get(PluginRepository::class)->updateState($plugin_id, 'active');
                } else {
                    $activity_details['result'] = 'error';
                }
                break;

            case 'deactivate':
                if (!isset($crt_db_plugin) or $crt_db_plugin['state'] != 'active') {
                    $activity_details['result'] = 'error';
                    break;
                }

                ServiceLocator::get(PluginRepository::class)->updateState($plugin_id, 'inactive');

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

                ServiceLocator::get(PluginRepository::class)->delete($plugin_id);

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

                ServiceLocator::get(AdminService::class)->deltree(PHPWG_PLUGINS_PATH . $plugin_id, PHPWG_PLUGINS_PATH . 'trash');
                break;
        }

        pwg_activity('system', ACTIVITY_SYSTEM_PLUGIN, $action, $activity_details);

        return $errors;
    }

    /**
     * Get plugins defined in the plugin directory
     */
    public function getFsPlugins(): void
    {
        $dir = opendir(PHPWG_PLUGINS_PATH);
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
        $path = PHPWG_PLUGINS_PATH.$plugin_id;

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
            if (is_string($desc = load_language('description.txt', $path.'/', ['return' => true]))) {
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
            if (!empty($plugin['uri']) and strpos($plugin['uri'], 'extension_view.php?eid=')) {
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
                uasort($this->fs_plugins, name_compare(...));
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

    // Retrieve PEM versions
    // Beta test : return last version on PEM if the current version isn't known or else return the current and the last version
    /**
     * @return string[]
     */
    public function getVersionsToCheck(bool $beta_test = false, string $version = PHPWG_VERSION): array
    {
        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php?category_id='. Config::pemPluginsCategory() .'&format=php';
        if (ServiceLocator::get(AdminService::class)->fetchRemote($url, $result) and $pem_versions = safe_unserialize($result)) {
            $i = 0;

            // If the actual version exist, put the PEM id in $versions_to_check
            while ($i < count($pem_versions) && count($versions_to_check) == 0) {
                if (!is_array($pem_versions[$i]) || !isset($pem_versions[$i]['name'], $pem_versions[$i]['id'])) {
                    $i++;
                    continue;
                }
                if (get_branch_from_version(is_scalar($pem_versions[$i]['name']) ? (string) $pem_versions[$i]['name'] : '') == get_branch_from_version($version)) {
                    $versions_to_check[] = is_scalar($pem_versions[$i]['id']) ? (string) $pem_versions[$i]['id'] : '';
                }
                $i++;
            }

            // If $beta_test is true, search the previous version
            if ($beta_test) {
                // If the actual version is not in PEM, put the latest PEM version
                if (count($versions_to_check) == 0) {
                    if (is_array($pem_versions[0]) && isset($pem_versions[0]['id'])) {
                        $versions_to_check[] = is_scalar($pem_versions[0]['id']) ? (string) $pem_versions[0]['id'] : '';
                    }
                } else { // Else search the next version in PEM
                    $has_found_previous_version = false;
                    while ($i < count($pem_versions) && !$has_found_previous_version) {
                        if (!is_array($pem_versions[$i]) || !isset($pem_versions[$i]['id'])) {
                            $i++;
                            continue;
                        }
                        if ($pem_versions[$i]['id'] != $versions_to_check[0]) {
                            $versions_to_check[] = is_scalar($pem_versions[$i]['id']) ? (string) $pem_versions[$i]['id'] : '';
                            $has_found_previous_version = true;
                        }
                        $i++;
                    }
                }
            }
        }
        return $versions_to_check;
    }

    /**
     * Retrieve PEM server datas to $server_plugins
     * $beta_test parameter add plugins compatible with the previous version
     */
    public function getServerPlugins(bool $new = false, bool $beta_test = false): bool
    {
        $versions_to_check = $this->getVersionsToCheck($beta_test);
        if (empty($versions_to_check)) {
            return true;
        }

        // Plugins to check
        $plugins_to_check = [];
        foreach ($this->fs_plugins as $fs_plugin) {
            if (isset($fs_plugin['extension'])) {
                $plugins_to_check[] = is_scalar($fs_plugin['extension']) ? (string) $fs_plugin['extension'] : '';
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
        if (ServiceLocator::get(AdminService::class)->fetchRemote($url, $result, $get_data)) {
            $pem_plugins = safe_unserialize($result);
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
                $plugins_to_check[] = is_scalar($fs_plugin['extension']) ? (string) $fs_plugin['extension'] : '';
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

        if (ServiceLocator::get(AdminService::class)->fetchRemote($url, $result, $get_data)) {
            $pem_plugins = safe_unserialize($result);
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
                $server_plugins[$eid][] = is_scalar($plugin['revision_name']) ? (string) $plugin['revision_name'] : '';
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

        if ($archive = tempnam(PHPWG_PLUGINS_PATH, 'zip')) {
            $url = PEM_URL . '/download.php';
            $get_data = [
              'rid' => $revision,
              'origin' => 'piwigo_'.$action,
            ];

            $handle = Filesystem::tryFopen($archive, 'wb');
            if (is_resource($handle)) {
                $fh = $handle;
                if (ServiceLocator::get(AdminService::class)->fetchRemote($url, $handle, $get_data)) {
                    fclose($fh);
                    $zip = new \PclZip($archive);
                    if ($list = $zip->listContent()) {
                        $main_filepath = null;
                        $status = 'ok';
                        foreach ($list as $file) {
                            // we search main.inc.php in archive
                            if (basename((string) $file['filename']) == 'main.inc.php'
                              and ($main_filepath === null
                              or strlen((string) $file['filename']) < strlen($main_filepath))) {
                                $main_filepath = $file['filename'];
                            }
                        }

                        $logger->debug(__FUNCTION__.', $main_filepath = '.$main_filepath);

                        if (isset($main_filepath)) {
                            $root = dirname($main_filepath); // main.inc.php path in archive
                            if ($action == 'upgrade') {
                                $plugin_id = $dest;
                            } else {
                                $plugin_id = ($root == '.' ? 'extension_' . $dest : basename($root));
                            }
                            $extract_path = PHPWG_PLUGINS_PATH . $plugin_id;
                            $logger->debug(__FUNCTION__.', $extract_path = '.$extract_path);

                            if ($result = $zip->extract(
                                PCLZIP_OPT_PATH,
                                $extract_path,
                                PCLZIP_OPT_REMOVE_PATH,
                                $root,
                                PCLZIP_OPT_REPLACE_NEWER
                            )) {
                                foreach ($result as $file) {
                                    if ($file['stored_filename'] == $main_filepath) {
                                        $status = $file['status'];
                                        break;
                                    }
                                }
                                if (file_exists($extract_path.'/obsolete.list')
                                  and $old_files = file($extract_path.'/obsolete.list', FILE_IGNORE_NEW_LINES)) {
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
                                            ServiceLocator::get(AdminService::class)->deltree($path, PHPWG_PLUGINS_PATH . 'trash');
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
    public function getMergedExtensions(string $version = PHPWG_VERSION): array
    {
        $file = PHPWG_ROOT_PATH.'install/obsolete_extensions.list';
        $merged_extensions = [];

        if (file_exists($file) and $obsolete_ext = file($file, FILE_IGNORE_NEW_LINES)) {
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
            return name_compare($a, $b);
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
        uasort($this->fs_plugins, name_compare(...));

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
