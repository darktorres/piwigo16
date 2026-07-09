<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * used when a plugin uses the old procedural declaration of maintenance methods
 */
class DummyPlugin_maintain extends PluginMaintain
{
    // Each is_callable() here checks for a bare function dynamically defined
    // by a plugin's own maintain.inc.php (include_once'd in
    // plugins::build_maintain_class(), outside this codebase, not
    // statically knowable) — genuinely undecidable until real PluginMaintain
    // contracts (P31) replace this pre-2.7 procedural fallback entirely.
    /**
     * @param array<int, string> $errors - not natively typed: PluginMaintain's
     *   own base declares $errors with no native type, and PHP's parameter
     *   contravariance rules fatal on narrowing an untyped parent param to a
     *   native type in the override (verified empirically)
     */
    public function install($plugin_version, &$errors = []): mixed
    {
        // @phpstan-ignore function.impossibleType
        if (is_callable('plugin_install')) {
            return plugin_install($this->plugin_id, $plugin_version, $errors);
        }

        return null;
    }

    /**
     * @param array<int, string> $errors - see install()'s $errors docblock
     */
    public function activate($plugin_version, &$errors = []): mixed
    {
        // @phpstan-ignore function.impossibleType
        if (is_callable('plugin_activate')) {
            return plugin_activate($this->plugin_id, $plugin_version, $errors);
        }

        return null;
    }

    public function deactivate(): mixed
    {
        // @phpstan-ignore function.impossibleType
        if (is_callable('plugin_deactivate')) {
            return plugin_deactivate($this->plugin_id);
        }

        return null;
    }

    public function uninstall(): mixed
    {
        // @phpstan-ignore function.impossibleType
        if (is_callable('plugin_uninstall')) {
            return plugin_uninstall($this->plugin_id);
        }

        return null;
    }

    /**
     * @param array<int, string> $errors - see install()'s $errors docblock
     */
    public function update($old_version, $new_version, &$errors = []): void {}
}

class plugins
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public $fs_plugins = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public $db_plugins_by_id = [];

    /**
     * @var array<int|string, array<string, mixed>>
     */
    public $server_plugins = [];

    /**
     * @var string[]
     */
    public $default_plugins = ['LocalFilesEditor', 'language_switch', 'TakeATour', 'AdminTools'];

    /**
     * Initialize $fs_plugins and $db_plugins_by_id
     */
    public function __construct()
    {
        $this->get_fs_plugins();

        foreach (get_db_plugins() as $db_plugin) {
            $this->db_plugins_by_id[$db_plugin['id']] = $db_plugin;
        }
    }

    /**
     * Returns the maintain class of a plugin
     * or build a new class with the procedural methods
     * @param string $plugin_id
     */
    private static function build_maintain_class($plugin_id): PluginMaintain
    {
        $file_to_include = PHPWG_PLUGINS_PATH . $plugin_id . '/maintain';
        $classname = $plugin_id . '_maintain';

        // piwigo-videojs and piwigo-openstreetmap unfortunately have a "-" in their folder
        // name (=plugin_id) and a class name can't have a "-". So we have to replace with a "_"
        $classname = str_replace('-', '_', $classname);

        // 2.7 pattern (OO only)
        if (file_exists($file_to_include . '.class.php')) {
            include_once $file_to_include . '.class.php';
            $maintain = new $classname($plugin_id);
            if (! $maintain instanceof PluginMaintain) {
                throw new \LogicException("build_maintain_class(): {$classname} does not extend PluginMaintain");
            }
            return $maintain;
        }

        // before 2.7 pattern (OO or procedural)
        if (file_exists($file_to_include . '.inc.php')) {
            include_once $file_to_include . '.inc.php';

            if (class_exists($classname)) {
                $maintain = new $classname($plugin_id);
                if (! $maintain instanceof PluginMaintain) {
                    throw new \LogicException("build_maintain_class(): {$classname} does not extend PluginMaintain");
                }
                return $maintain;
            }
        }

        return new DummyPlugin_maintain($plugin_id);
    }

    /**
     * Perform requested actions
     * @param string $action - action
     * @param string $plugin_id - plugin id
     * @param array{revision?: mixed} $options - errors
     * @return array<int|string, mixed>
     */
    public function perform_action($action, $plugin_id, array $options = []): array
    {
        global $conf;

        if (! $conf['enable_extensions_install'] and $action == 'delete') {
            die('Piwigo extensions install/update/delete system is disabled');
        }

        if (isset($this->db_plugins_by_id[$plugin_id])) {
            $crt_db_plugin = $this->db_plugins_by_id[$plugin_id];
        }

        $activity_details = [
            'plugin_id' => $plugin_id,
        ];

        $errors = [];

        switch ($action) {
            case 'install':
                if (! empty($crt_db_plugin) or ! isset($this->fs_plugins[$plugin_id])) {
                    break;
                }

                $plugin_maintain = self::build_maintain_class($plugin_id);
                $plugin_maintain->install($this->fs_plugins[$plugin_id]['version'], $errors);
                $activity_details['version'] = $this->fs_plugins[$plugin_id]['version'];

                if (empty($errors)) {
                    $query = '
INSERT INTO ' . PLUGINS_TABLE . ' (id,version)
  VALUES (\'' . $plugin_id . '\', \'' . $this->fs_plugins[$plugin_id]['version'] . '\')
;';
                    pwg_query($query);
                } else {
                    $activity_details['result'] = 'error';
                }
                break;

            case 'update':
                $previous_version = $this->fs_plugins[$plugin_id]['version'];
                $activity_details['from_version'] = $previous_version;
                // the only real caller (pwg.extensions.php's ws_extensions_ignoreUpdate
                // upgrade path) always passes 'revision' alongside action='update'
                if (! isset($options['revision'])) {
                    throw new \LogicException("perform_action('update'): missing 'revision' option");
                }
                $errors[0] = $this->extract_plugin_files('upgrade', $options['revision'], $plugin_id);

                if ($errors[0] === 'ok') {
                    $this->get_fs_plugin($plugin_id); // refresh plugins list
                    $new_version = $this->fs_plugins[$plugin_id]['version'];
                    $activity_details['to_version'] = $new_version;

                    $plugin_maintain = self::build_maintain_class($plugin_id);
                    $plugin_maintain->update($previous_version, $new_version, $errors);

                    if ($new_version != 'auto') {
                        $query = '
UPDATE ' . PLUGINS_TABLE . '
  SET version=\'' . $new_version . '\'
  WHERE id=\'' . $plugin_id . '\'
;';
                        pwg_query($query);
                    }
                } else {
                    $activity_details['result'] = 'error';
                }

                break;

            case 'activate':
                if (! isset($crt_db_plugin)) {
                    $errors = $this->perform_action('install', $plugin_id);
                    [$crt_db_plugin] = get_db_plugins(null, $plugin_id);
                    load_conf_from_db();
                } elseif ($crt_db_plugin['state'] == 'active') {
                    break;
                }

                if (empty($errors)) {
                    $plugin_maintain = self::build_maintain_class($plugin_id);
                    $plugin_maintain->activate($crt_db_plugin['version'], $errors);
                    $activity_details['version'] = $crt_db_plugin['version'];
                }

                if (empty($errors)) {
                    $query = '
UPDATE ' . PLUGINS_TABLE . '
  SET state=\'active\'
  WHERE id=\'' . $plugin_id . '\'
;';
                    pwg_query($query);
                } else {
                    $activity_details['result'] = 'error';
                }
                break;

            case 'deactivate':
                if (! isset($crt_db_plugin) or $crt_db_plugin['state'] != 'active') {
                    $activity_details['result'] = 'error';
                    break;
                }

                $query = '
UPDATE ' . PLUGINS_TABLE . '
  SET state=\'inactive\'
  WHERE id=\'' . $plugin_id . '\'
;';
                pwg_query($query);

                $plugin_maintain = self::build_maintain_class($plugin_id);
                $plugin_maintain->deactivate();

                if (isset($crt_db_plugin['version'])) {
                    $activity_details['version'] = $crt_db_plugin['version'];
                }

                break;

            case 'uninstall':
                if (! isset($crt_db_plugin)) {
                    $activity_details['result'] = 'error';
                    $activity_details['error'] = 'plugin not installed';
                    break;
                }

                if (isset($crt_db_plugin['version'])) {
                    $activity_details['version'] = $crt_db_plugin['version'];
                }

                if ($crt_db_plugin['state'] == 'active') {
                    $this->perform_action('deactivate', $plugin_id);
                }

                $query = '
DELETE FROM ' . PLUGINS_TABLE . '
  WHERE id=\'' . $plugin_id . '\'
;';
                pwg_query($query);

                $plugin_maintain = self::build_maintain_class($plugin_id);
                $plugin_maintain->uninstall();
                break;

            case 'restore':
                $this->perform_action('uninstall', $plugin_id);
                unset($this->db_plugins_by_id[$plugin_id]);
                $errors = $this->perform_action('activate', $plugin_id);
                break;

            case 'delete':
                if (! empty($crt_db_plugin)) {
                    if (isset($crt_db_plugin['version'])) {
                        $activity_details['db_version'] = $crt_db_plugin['version'];
                    }

                    $this->perform_action('uninstall', $plugin_id);
                }
                if (! isset($this->fs_plugins[$plugin_id])) {
                    break;
                } else {
                    $activity_details['fs_version'] = $this->fs_plugins[$plugin_id]['version'];
                }

                include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
                deltree(PHPWG_PLUGINS_PATH . $plugin_id, PHPWG_PLUGINS_PATH . 'trash');
                break;
        }

        pwg_activity('system', ACTIVITY_SYSTEM_PLUGIN, $action, $activity_details);

        return $errors;
    }

    /**
     * Get plugins defined in the plugin directory
     */
    public function get_fs_plugins(): void
    {
        $dir = opendir(PHPWG_PLUGINS_PATH);
        if ($dir === false) {
            return;
        }
        while ($file = readdir($dir)) {
            if ($file != '.' and $file != '..') {
                if (preg_match('/^[a-zA-Z0-9-_]+$/', $file)) {
                    $this->get_fs_plugin($file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * Load metadata of a plugin in `fs_plugins` array
     * @from 2.7
     * @return array{name: string, version: string, uri: string, description: string, author: string, hasSettings: bool, 'author uri'?: string, extension?: string}|false
     */
    public function get_fs_plugin(string $plugin_id): array|false
    {
        $path = PHPWG_PLUGINS_PATH . $plugin_id;

        if (is_dir($path) and ! is_link($path)
            and file_exists($path . '/main.inc.php')
        ) {
            $plugin = [
                'name' => $plugin_id,
                'version' => '0',
                'uri' => '',
                'description' => '',
                'author' => '',
                'hasSettings' => false,
            ];
            $plg_data = file_get_contents($path . '/main.inc.php', false, null, 0, 2048);
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
            $desc = load_language('description.txt', $path . '/', [
                'return' => true,
            ]);
            if (is_string($desc) && $desc !== '') {
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
                    global $user;

                    if (isset($user) and $user['status'] == 'webmaster') {
                        $plugin['hasSettings'] = true;
                    }
                } else {
                    $plugin['hasSettings'] = true;
                }
            }
            if (! empty($plugin['uri']) and strpos($plugin['uri'], 'extension_view.php?eid=')) {
                [, $extension] = explode('extension_view.php?eid=', $plugin['uri']);
                if (is_numeric($extension)) {
                    $plugin['extension'] = $extension;
                }
            }

            // IMPORTANT SECURITY !
            // hasSettings is bool, not a display string; htmlspecialchars()
            // rejects non-string arguments under strict_types, so exclude it
            // from the escaping pass and restore it afterwards.
            $has_settings = $plugin['hasSettings'];
            unset($plugin['hasSettings']);
            $plugin = array_map(htmlspecialchars(...), $plugin);
            $plugin['hasSettings'] = $has_settings;
            $this->fs_plugins[$plugin_id] = $plugin;

            return $plugin;
        }

        return false;
    }

    /**
     * Sort fs_plugins
     */
    public function sort_fs_plugins(string $order = 'name'): void
    {
        switch ($order) {
            case 'name':
                uasort($this->fs_plugins, name_compare(...));
                break;
            case 'status':
                $this->sort_plugins_by_state();
                break;
            case 'author':
                uasort($this->fs_plugins, $this->plugin_author_compare(...));
                break;
            case 'id':
                uksort($this->fs_plugins, strcasecmp(...));
                break;
        }
    }

    // Retrieve PEM versions
    // Beta test : return last version on PEM if the current version isn't known or else return the current and the last version
    /**
     * @return mixed[]
     */
    public function get_versions_to_check(bool $beta_test = false, string $version = PHPWG_VERSION): array
    {
        global $conf;

        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php?category_id=' . $conf['pem_plugins_category'] . '&format=php';
        // $result is never a resource here: no fopen() handle is passed to
        // fetchRemote() above.
        if (fetchRemote($url, $result) and is_string($result) and $pem_versions = @unserialize($result)) {
            $i = 0;

            // If the actual version exist, put the PEM id in $versions_to_check
            while ($i < count($pem_versions) && count($versions_to_check) == 0) {
                if (get_branch_from_version($pem_versions[$i]['name']) == get_branch_from_version($version)) {
                    $versions_to_check[] = $pem_versions[$i]['id'];
                }
                $i++;
            }

            // If $beta_test is true, search the previous version
            if ($beta_test) {
                // If the actual version is not in PEM, put the latest PEM version
                if (count($versions_to_check) == 0) {
                    $versions_to_check[] = $pem_versions[0]['id'];
                } else { // Else search the next version in PEM
                    $has_found_previous_version = false;
                    while ($i < count($pem_versions) && ! $has_found_previous_version) {
                        if ($pem_versions[$i]['id'] != $versions_to_check[0]) {
                            $versions_to_check[] = $pem_versions[$i]['id'];
                            $has_found_previous_version = true;
                        }
                        $i++;
                    }
                }
            }

            // if (!preg_match('/^\d+\.\d+\.\d+$/', $version))
            // {
            //   $version = $pem_versions[0]['name'];
            // }
            // $branch = get_branch_from_version($version);
            // foreach ($pem_versions as $pem_version)
            // {
            //   if (strpos($pem_version['name'], $branch) === 0)
            //   {
            //     $versions_to_check[] = $pem_version['id'];
            //   }
            // }
        }
        return $versions_to_check;
    }

    /**
     * Retrieve PEM server datas to $server_plugins
     * $beta_test parameter add plugins compatible with the previous version
     */
    public function get_server_plugins(bool $new = false, bool $beta_test = false): bool
    {
        global $user, $conf;

        $versions_to_check = $this->get_versions_to_check($beta_test);
        if (empty($versions_to_check)) {
            return true;
        }

        // Plugins to check
        $plugins_to_check = [];
        foreach ($this->fs_plugins as $fs_plugin) {
            if (isset($fs_plugin['extension'])) {
                $plugins_to_check[] = $fs_plugin['extension'];
            }
        }

        // Retrieve PEM plugins infos
        $url = PEM_URL . '/api/get_revision_list-next.php';
        $get_data = [
            'category_id' => $conf['pem_plugins_category'],
            'format' => 'php',
            'last_revision_only' => 'true',
            'version' => implode(',', $versions_to_check),
            'lang' => substr((string) $user['language'], 0, 2),
            'get_nb_downloads' => 'true',
        ];

        if (! empty($plugins_to_check)) {
            if ($new) {
                $get_data['extension_exclude'] = implode(',', $plugins_to_check);
            } else {
                $get_data['extension_include'] = implode(',', $plugins_to_check);
            }
        }
        // $result is never a resource here: no fopen() handle is passed to
        // fetchRemote() above.
        if (fetchRemote($url, $result, $get_data) and is_string($result)) {
            $pem_plugins = @unserialize($result);
            if (! is_array($pem_plugins)) {
                return false;
            }
            foreach ($pem_plugins as $plugin) {
                $this->server_plugins[$plugin['extension_id']] = $plugin;
            }
            return true;
        }
        return false;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function get_incompatible_plugins(bool $actualize = false): array|false
    {
        if (isset($_SESSION['incompatible_plugins']) and ! $actualize
          and $_SESSION['incompatible_plugins']['~~expire~~'] > time()) {
            return $_SESSION['incompatible_plugins'];
        }

        $_SESSION['incompatible_plugins'] = [
            '~~expire~~' => time() + 300,
        ];

        $versions_to_check = $this->get_versions_to_check();
        if (empty($versions_to_check)) {
            return false;
        }

        global $conf;

        // Plugins to check
        $plugins_to_check = [];
        foreach ($this->fs_plugins as $fs_plugin) {
            if (isset($fs_plugin['extension'])) {
                $plugins_to_check[] = $fs_plugin['extension'];
            }
        }

        // Retrieve PEM plugins infos
        $url = PEM_URL . '/api/get_revision_list.php';
        $get_data = [
            'category_id' => $conf['pem_plugins_category'],
            'format' => 'php',
            'version' => implode(',', $versions_to_check),
            'extension_include' => implode(',', $plugins_to_check),
        ];

        // $result is never a resource here: no fopen() handle is passed to
        // fetchRemote() above.
        if (fetchRemote($url, $result, $get_data) and is_string($result)) {
            $pem_plugins = @unserialize($result);
            if (! is_array($pem_plugins)) {
                return false;
            }

            $server_plugins = [];
            foreach ($pem_plugins as $plugin) {
                if (! isset($server_plugins[$plugin['extension_id']])) {
                    $server_plugins[$plugin['extension_id']] = [];
                }
                $server_plugins[$plugin['extension_id']][] = $plugin['revision_name'];
            }

            foreach ($this->fs_plugins as $plugin_id => $fs_plugin) {
                if (isset($fs_plugin['extension'])
                  and ! in_array($plugin_id, $this->default_plugins)
                  and $fs_plugin['version'] != 'auto'
                  and (! isset($server_plugins[$fs_plugin['extension']]) or ! in_array($fs_plugin['version'], $server_plugins[$fs_plugin['extension']]))) {
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
    public function sort_server_plugins(string $order = 'date'): void
    {
        switch ($order) {
            case 'date':
                krsort($this->server_plugins);
                break;
            case 'revision':
                usort($this->server_plugins, $this->extension_revision_compare(...));
                break;
            case 'name':
                uasort($this->server_plugins, $this->extension_name_compare(...));
                break;
            case 'author':
                uasort($this->server_plugins, $this->extension_author_compare(...));
                break;
            case 'downloads':
                usort($this->server_plugins, $this->extension_downloads_compare(...));
                break;
        }
    }

    /**
     * Extract plugin files from archive
     * @param string $action - install or upgrade
     *  @param string $revision - archive URL
     * @param string $dest - plugin id or extension id
     */
    public function extract_plugin_files($action, $revision, $dest, ?string &$plugin_id = null): string
    {
        global $logger;

        if ($archive = tempnam(PHPWG_PLUGINS_PATH, 'zip')) {
            $url = PEM_URL . '/download.php';
            $get_data = [
                'rid' => $revision,
                'origin' => 'piwigo_' . $action,
            ];

            if ($handle = @fopen($archive, 'wb') and fetchRemote($url, $handle, $get_data)) {
                // fetchRemote()'s &$dest out-param could in principle reset
                // to a string, but only when the value passed in wasn't
                // already a resource — $handle always is here (just opened
                // above), so it's still a resource after the call.
                if (is_resource($handle)) {
                    fclose($handle);
                }
                include_once PHPWG_ROOT_PATH . 'admin/include/functions_zip.inc.php';
                if ($list = zip_list_filenames($archive)) {
                    foreach ($list as $file) {
                        // we search main.inc.php in archive
                        if (basename((string) $file['filename']) == 'main.inc.php'
                          and (! isset($main_filepath)
                          or strlen((string) $file['filename']) < strlen($main_filepath))) {
                            $main_filepath = $file['filename'];
                        }
                    }

                    if (isset($main_filepath)) {
                        $logger->debug(__FUNCTION__ . ', $main_filepath = ' . $main_filepath);

                        $root = dirname($main_filepath); // main.inc.php path in archive
                        if ($action == 'upgrade') {
                            $plugin_id = $dest;
                        } else {
                            $plugin_id = ($root == '.' ? 'extension_' . $dest : basename($root));
                        }
                        $extract_path = PHPWG_PLUGINS_PATH . $plugin_id;
                        $logger->debug(__FUNCTION__ . ', $extract_path = ' . $extract_path);

                        if ($result = zip_extract($archive, $extract_path, $root)) {
                            // extraction succeeded; 'ok' if the extracted result
                            // list doesn't happen to include main.inc.php itself
                            $status = 'ok';
                            foreach ($result as $file) {
                                if ($file['stored_filename'] == $main_filepath) {
                                    $status = $file['status'];
                                    break;
                                }
                            }
                            if (file_exists($extract_path . '/obsolete.list')
                              and $old_files = file($extract_path . '/obsolete.list', FILE_IGNORE_NEW_LINES)) {
                                $old_files[] = 'obsolete.list';
                                $logger->debug(__FUNCTION__ . ', $old_files = {' . join('},{', $old_files) . '}');

                                $extract_path_realpath = realpath($extract_path);

                                // realpath() failing here would mean
                                // $extract_path (just populated by the
                                // zip_extract() above) doesn't actually
                                // exist as a real directory — skip the
                                // obsolete-file cleanup rather than risk the
                                // traversal check below against a
                                // non-canonical path.
                                if ($extract_path_realpath === false) {
                                    $old_files = [];
                                }

                                foreach ($old_files as $old_file) {
                                    $old_file = trim($old_file);
                                    $old_file = trim($old_file, '/'); // prevent path starting with a "/"

                                    if (empty($old_file)) { // empty here means the extension itself
                                        continue;
                                    }

                                    $path = $extract_path . '/' . $old_file;

                                    // make sure the obsolete file is withing the extension directory, prevent traversal path
                                    $realpath = realpath($path);
                                    if ($realpath === false or ! str_starts_with($realpath, $extract_path_realpath)) {
                                        continue;
                                    }

                                    $logger->debug(__FUNCTION__ . ', to delete = ' . $path);

                                    if (is_file($path)) {
                                        @unlink($path);
                                    } elseif (is_dir($path)) {
                                        deltree($path, PHPWG_PLUGINS_PATH . 'trash');
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
            $status = 'temp_path_error';
        }

        if (is_string($archive)) {
            @unlink($archive);
        }
        return $status;
    }

    /**
     * @return string[]
     */
    public function get_merged_extensions(string $version = PHPWG_VERSION): array
    {
        $file = PHPWG_ROOT_PATH . 'install/obsolete_extensions.list';
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
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function extension_revision_compare(array $a, array $b): int
    {
        if ($a['revision_date'] < $b['revision_date']) {
            return 1;
        } else {
            return -1;
        }
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function extension_name_compare(array $a, array $b): int
    {
        return strcmp(strtolower((string) $a['extension_name']), strtolower((string) $b['extension_name']));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function extension_author_compare(array $a, array $b): int
    {
        $r = strcasecmp((string) $a['author_name'], (string) $b['author_name']);
        if ($r == 0) {
            return $this->extension_name_compare($a, $b);
        } else {
            return $r;
        }
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function plugin_author_compare(array $a, array $b): int
    {
        $r = strcasecmp((string) $a['author'], (string) $b['author']);
        if ($r == 0) {
            return name_compare($a, $b);
        } else {
            return $r;
        }
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function extension_downloads_compare(array $a, array $b): int
    {
        if ($a['extension_nb_downloads'] < $b['extension_nb_downloads']) {
            return 1;
        } else {
            return -1;
        }
    }

    public function sort_plugins_by_state(): void
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
