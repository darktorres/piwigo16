<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Db\Tables;
use Piwigo\Http\HttpClientService;

class themes
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public $fs_themes = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public $db_themes_by_id = [];

    /**
     * @var array<int|string, array<string, mixed>>
     */
    public $server_themes = [];

    /**
     * Initialize $fs_themes and $db_themes_by_id
     */
    public function __construct()
    {
        $this->get_fs_themes();

        foreach ($this->get_db_themes() as $db_theme) {
            $id = $db_theme['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }
            $this->db_themes_by_id[$id] = $db_theme;
        }
    }

    /**
     * Returns the maintain class of a theme
     * or build a new class with the procedural methods
     * @param string $theme_id
     */
    private static function build_maintain_class($theme_id): ThemeMaintain
    {
        $file_to_include = Config::themesPath() . '/' . $theme_id . '/admin/maintain.inc.php';
        $classname = $theme_id . '_maintain';

        if (file_exists($file_to_include)) {
            include_once $file_to_include;

            if (class_exists($classname)) {
                $maintain = new $classname($theme_id);
                if (! $maintain instanceof ThemeMaintain) {
                    throw new \LogicException("build_maintain_class(): {$classname} does not extend ThemeMaintain");
                }
                return $maintain;
            }
        }

        return new DummyTheme_maintain($theme_id);
    }

    /**
     * $fs_themes is declared with a loose `array<string, mixed>` value
     * type (it stores several differently-typed metadata fields under one
     * array), but the only writer, get_fs_themes(), always stores a real
     * string under 'version' (its own array literal guarantees `version:
     * string`, further narrowed by trim() overwrites). Narrow that single
     * invariant here instead of repeating an is_string()+fallback check at
     * every call site.
     */
    private function fs_theme_version(string $theme_id): string
    {
        $version = $this->fs_themes[$theme_id]['version'] ?? null;
        return is_string($version) ? $version : '0';
    }

    /**
     * See fs_theme_version()'s comment: 'name' is likewise always a real
     * string in the only writer, get_fs_themes().
     */
    private function fs_theme_name(string $theme_id): string
    {
        $name = $this->fs_themes[$theme_id]['name'] ?? null;
        return is_string($name) ? $name : $theme_id;
    }

    /**
     * Perform requested actions
     * @param string $action - action
     * @param string $theme_id - theme id
     * @return string[] errors
     */
    public function perform_action($action, $theme_id): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! (bool) $conf['enable_extensions_install'] and $action == 'delete') {
            die('Piwigo extensions install/update/delete system is disabled');
        }

        if (isset($this->db_themes_by_id[$theme_id])) {
            $crt_db_theme = $this->db_themes_by_id[$theme_id];
        }

        $errors = [];
        $activity_details = [
            'theme_id' => $theme_id,
        ];

        switch ($action) {
            case 'activate':
                if (isset($crt_db_theme)) {
                    // the theme is already active
                    break;
                }

                if ($theme_id == 'default') {
                    // you can't activate the "default" theme
                    break;
                }

                $missing_parent = $this->missing_parent_theme($theme_id);
                if (isset($missing_parent)) {
                    $errors[] = l10n(
                        'Impossible to activate this theme, the parent theme is missing: %s',
                        $missing_parent
                    );

                    break;
                }

                if ((bool) $this->fs_themes[$theme_id]['mobile']
                    and ! empty($conf['mobile_theme'])
                    and $conf['mobile_theme'] != $theme_id) {
                    $errors[] = l10n('You can activate only one mobile theme.');
                    break;
                }

                $theme_maintain = self::build_maintain_class($theme_id);
                $fs_version = $this->fs_theme_version($theme_id);
                $theme_maintain->activate($fs_version, $errors);

                if (empty($errors)) {
                    $fs_name = $this->fs_theme_name($theme_id);
                    $query = '
INSERT INTO ' . Tables::themes() . '
  (id, version, name)
  VALUES(\'' . $theme_id . '\',
         \'' . $fs_version . '\',
         \'' . $fs_name . '\')
;';
                    pwg_query($query);

                    $activity_details['version'] = $fs_version;

                    if ((bool) $this->fs_themes[$theme_id]['mobile']) {
                        conf_update_param('mobile_theme', $theme_id);
                    }
                }
                break;

            case 'deactivate':
                if (! isset($crt_db_theme)) {
                    // the theme is already inactive
                    break;
                }

                // you can't deactivate the last theme
                if (count($this->db_themes_by_id) <= 1) {
                    $errors[] = l10n('Impossible to deactivate this theme, you need at least one theme.');
                    break;
                }

                if ($theme_id == (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultTheme()) {
                    // find a random theme to replace
                    $new_theme = null;

                    $query = '
SELECT id
  FROM ' . Tables::themes() . '
  WHERE id != \'' . $theme_id . '\'
;';
                    $result = pwg_query($query);
                    if (pwg_db_num_rows($result) == 0) {
                        $new_theme = 'default';
                    } else {
                        $row = pwg_db_fetch_row($result);
                        assert($row !== null);
                        [$new_theme] = $row;
                        if (! is_string($new_theme)) {
                            $new_theme = 'default';
                        }
                    }

                    $this->set_default_theme($new_theme);
                }

                $theme_maintain = self::build_maintain_class($theme_id);
                $theme_maintain->deactivate();

                $query = '
DELETE
  FROM ' . Tables::themes() . '
  WHERE id= \'' . $theme_id . '\'
;';
                pwg_query($query);

                if ((bool) $this->fs_themes[$theme_id]['mobile']) {
                    conf_update_param('mobile_theme', '');
                }
                break;

            case 'delete':
                if (! empty($crt_db_theme)) {
                    $errors[] = 'CANNOT DELETE - THEME IS INSTALLED';
                    break;
                }
                if (! isset($this->fs_themes[$theme_id])) {
                    // nothing to do here
                    break;
                }

                $children = $this->get_children_themes($theme_id);
                if (count($children) > 0) {
                    $errors[] = l10n(
                        'Impossible to delete this theme. Other themes depends on it: %s',
                        implode(', ', $children)
                    );
                    break;
                }

                $theme_maintain = self::build_maintain_class($theme_id);
                $theme_maintain->delete();

                FilesystemHelper::deltree(Config::themesPath() . $theme_id, Config::themesPath() . 'trash');
                break;

            case 'set_default':
                // first we need to know which users are using the current default theme
                $this->set_default_theme($theme_id);
                break;
        }

        (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('system', ActivitySystem::Theme, $action, $activity_details);

        return $errors;
    }

    public function missing_parent_theme(string $theme_id): ?string
    {
        if (! isset($this->fs_themes[$theme_id]['parent'])) {
            return null;
        }

        $parent = $this->fs_themes[$theme_id]['parent'];

        if (! is_string($parent)) {
            return null;
        }

        if ($parent == 'default') {
            return null;
        }

        if (! isset($this->fs_themes[$parent])) {
            return $parent;
        }

        return $this->missing_parent_theme($parent);
    }

    /**
     * @return string[]
     */
    public function get_children_themes(string $theme_id): array
    {
        $children = [];

        foreach ($this->fs_themes as $test_child) {
            if (isset($test_child['parent']) and $test_child['parent'] == $theme_id) {
                $name = $test_child['name'] ?? null;
                if (is_string($name)) {
                    $children[] = $name;
                }
            }
        }

        return $children;
    }

    public function set_default_theme(string $theme_id): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        // first we need to know which users are using the current default theme
        $default_theme = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultTheme();

        $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE theme = \'' . $default_theme . '\'
;';
        // array_from_query() returns array<int|string, mixed>; only scalar
        // user_id values are ever selected by the query above, but narrow
        // defensively rather than trusting it, same as elsewhere in this
        // file (e.g. get_children_themes()).
        $user_ids_str = [];
        foreach (query2array($query, null, 'user_id') as $query_user_id) {
            if (is_scalar($query_user_id)) {
                $user_ids_str[] = (string) $query_user_id;
            }
        }

        // $conf['default_user_id']/'guest_id' are always ints (see
        // include/config_default.inc.php); same narrowing as
        // languages.class.php::perform_action()'s 'set_default' case.
        $default_user_id = is_numeric($conf['default_user_id']) ? (int) $conf['default_user_id'] : 0;
        $guest_id = is_numeric($conf['guest_id']) ? (int) $conf['guest_id'] : 0;

        $user_ids = array_unique(
            array_merge(
                $user_ids_str,
                [(string) $guest_id, (string) $default_user_id]
            )
        );

        // $user_ids can't be empty, at least the default user has the default
        // theme

        $query = '
UPDATE ' . Tables::userInfos() . '
  SET theme = \'' . $theme_id . '\'
  WHERE user_id IN (' . implode(',', $user_ids) . ')
;';
        pwg_query($query);
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function get_db_themes(string $id = ''): array
    {
        $query = '
SELECT
    *
  FROM ' . Tables::themes();

        $clauses = [];
        if (! empty($id)) {
            $clauses[] = 'id = \'' . $id . '\'';
        }
        if (count($clauses) > 0) {
            $query .= '
  WHERE ' . implode(' AND ', $clauses);
        }

        $result = pwg_query($query);
        $themes = [];
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $themes[] = $row;
        }
        return $themes;
    }

    /**
     *  Get themes defined in the theme directory
     */
    public function get_fs_themes(): void
    {
        $dir = opendir(Config::themesPath());
        if ($dir === false) {
            return;
        }

        while ((bool) ($file = readdir($dir))) {
            if ($file != '.' and $file != '..') {
                $path = Config::themesPath() . $file;
                if (is_dir($path)
                    and (bool) preg_match('/^[a-zA-Z0-9-_]+$/', $file)
                    and file_exists($path . '/themeconf.inc.php')
                ) {
                    $theme = [
                        'id' => $file,
                        'name' => $file,
                        'version' => '0',
                        'uri' => '',
                        'description' => '',
                        'author' => '',
                        'mobile' => false,
                    ];
                    $theme_data_lines = file($path . '/themeconf.inc.php');
                    if ($theme_data_lines === false) {
                        continue;
                    }
                    $theme_data = implode('', $theme_data_lines);
                    if ((bool) preg_match('|Theme Name:\\s*(.+)|', $theme_data, $val)) {
                        $theme['name'] = trim($val[1]);
                    }
                    if ((bool) preg_match('|Version:\\s*([\\w.-]+)|', $theme_data, $val)) {
                        $theme['version'] = trim($val[1]);
                    }
                    if ((bool) preg_match('|Theme URI:\\s*(https?:\\/\\/.+)|', $theme_data, $val)) {
                        $theme['uri'] = trim($val[1]);
                    }
                    $desc = Lang::load('description.txt', $path . '/', [
                        'return' => true,
                    ]);
                    if (is_string($desc) && $desc !== '') {
                        $theme['description'] = trim($desc);
                    } elseif ((bool) preg_match('|Description:\\s*(.+)|', $theme_data, $val)) {
                        $theme['description'] = trim($val[1]);
                    }
                    if ((bool) preg_match('|Author:\\s*(.+)|', $theme_data, $val)) {
                        $theme['author'] = trim($val[1]);
                    }
                    if ((bool) preg_match('|Author URI:\\s*(https?:\\/\\/.+)|', $theme_data, $val)) {
                        $theme['author uri'] = trim($val[1]);
                    }
                    if (! empty($theme['uri']) and (bool) strpos($theme['uri'], 'extension_view.php?eid=')) {
                        [, $extension] = explode('extension_view.php?eid=', $theme['uri']);
                        if (is_numeric($extension)) {
                            $theme['extension'] = $extension;
                        }
                    }
                    if ((bool) preg_match('/["\']parent["\'][^"\']+["\']([^"\']+)["\']/', $theme_data, $val)) {
                        $theme['parent'] = $val[1];
                    }
                    if ((bool) preg_match('/["\']activable["\'].*?(true|false)/i', $theme_data, $val)) {
                        $theme['activable'] = get_boolean($val[1]);
                    }
                    if ((bool) preg_match('/["\']mobile["\'].*?(true|false)/i', $theme_data, $val)) {
                        $theme['mobile'] = get_boolean($val[1]);
                    }
                    if ((bool) preg_match('/["\']use_standard_pages["\'].*?(true|false)/i', $theme_data, $val)) {
                        $theme['use_standard_pages'] = get_boolean($val[1]);
                    }

                    // screenshot
                    $screenshot_path = $path . '/screenshot.png';
                    if (file_exists($screenshot_path)) {
                        $theme['screenshot'] = $screenshot_path;
                    } else {
                        global $conf;
                        $admin_theme = (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->getParam('admin_theme', 'clear');
                        $theme['screenshot'] =
                          PHPWG_ROOT_PATH . 'admin/themes/'
                          . (is_string($admin_theme) ? $admin_theme : 'clear')
                          . '/images/missing_screenshot.png'
                        ;
                    }

                    $admin_file = $path . '/admin/admin.inc.php';
                    if (file_exists($admin_file)) {
                        $theme['admin_uri'] = get_root_url() . 'admin.php?page=theme&theme=' . $file;
                    }

                    // IMPORTANT SECURITY ! (only string fields — 'mobile'/
                    // 'activable'/'use_standard_pages' are real bools,
                    // which htmlspecialchars() can't accept under
                    // strict_types)
                    foreach ($theme as $theme_key => $theme_value) {
                        if (is_string($theme_value)) {
                            $theme[$theme_key] = htmlspecialchars($theme_value);
                        }
                    }
                    $this->fs_themes[$file] = $theme;
                }
            }
        }
        closedir($dir);
    }

    /**
     * Sort fs_themes
     */
    public function sort_fs_themes(string $order = 'name'): void
    {
        switch ($order) {
            case 'name':
                uasort($this->fs_themes, name_compare(...));
                break;
            case 'status':
                $this->sort_themes_by_state();
                break;
            case 'author':
                uasort($this->fs_themes, $this->theme_author_compare(...));
                break;
            case 'id':
                uksort($this->fs_themes, strcasecmp(...));
                break;
        }
    }

    /**
     * Retrieve PEM server datas to $server_themes
     */
    public function get_server_themes(bool $new = false): bool
    {
        /**
         * @var array<string, mixed> $user
         * @var array<string, mixed> $conf
         */
        global $user, $conf;

        // PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url']) in
        // one branch of include/common.inc.php, so PHPStan can't prove it's a
        // string across that file boundary — narrow it once here (same
        // pattern as updates.class.php::get_server_extensions()).
        $pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

        $get_data = [
            'category_id' => $conf['pem_themes_category'],
            'format' => 'php',
        ];

        // Retrieve PEM versions
        $version = AppInfo::VERSION;
        $versions_to_check = [];
        $url = $pem_base_url . '/api/get_version_list.php';
        if (is_string($result = HttpClientService::fetch($url, $get_data)) and (bool) ($pem_versions = @unserialize($result))) {
            // unserialize() of a remote PEM response is genuinely untyped —
            // validate it's an array of arrays before indexing into it
            // below, rather than trusting the external payload (see
            // plugins::get_versions_to_check()'s identical guard).
            if (! is_array($pem_versions)) {
                return false;
            }

            if (! (bool) preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $first_pem_version = $pem_versions[0] ?? null;
                $first_pem_version_name = is_array($first_pem_version) ? ($first_pem_version['name'] ?? null) : null;
                if (is_string($first_pem_version_name)) {
                    $version = $first_pem_version_name;
                }
            }
            $branch = \Piwigo\Core\VersionHelper::getBranchFromVersion($version);
            foreach ($pem_versions as $pem_version) {
                if (! is_array($pem_version)) {
                    continue;
                }
                $pem_version_name = $pem_version['name'] ?? null;
                if (is_string($pem_version_name) and str_starts_with($pem_version_name, $branch)) {
                    $pem_version_id = $pem_version['id'] ?? null;
                    if (is_scalar($pem_version_id)) {
                        $versions_to_check[] = (string) $pem_version_id;
                    }
                }
            }
        }
        if (empty($versions_to_check)) {
            return false;
        }

        // Themes to check
        $themes_to_check = [];
        foreach ($this->fs_themes as $fs_theme) {
            if (isset($fs_theme['extension']) and is_scalar($fs_theme['extension'])) {
                $themes_to_check[] = (string) $fs_theme['extension'];
            }
        }

        // Retrieve PEM themes infos
        $url = $pem_base_url . '/api/get_revision_list-next.php';
        $user_language = $user['language'] ?? null;
        $user_language = is_scalar($user_language) ? (string) $user_language : '';
        $get_data = array_merge(
            $get_data,
            [
                'last_revision_only' => 'true',
                'version' => implode(',', $versions_to_check),
                'lang' => substr($user_language, 0, 2),
                'get_nb_downloads' => 'true',
            ]
        );

        if (! empty($themes_to_check)) {
            if ($new) {
                $get_data['extension_exclude'] = implode(',', $themes_to_check);
            } else {
                $get_data['extension_include'] = implode(',', $themes_to_check);
            }
        }
        if (is_string($result = HttpClientService::fetch($url, $get_data))) {
            $pem_themes = @unserialize($result);
            if (! is_array($pem_themes)) {
                return false;
            }
            foreach ($pem_themes as $theme) {
                if (! is_array($theme) || ! isset($theme['extension_id'])) {
                    continue;
                }
                /** @var array<string, mixed> $theme */
                $extension_id = $theme['extension_id'];
                if (! is_string($extension_id) && ! is_int($extension_id)) {
                    continue;
                }
                $this->server_themes[$extension_id] = $theme;
            }
            return true;
        }
        return false;
    }

    /**
     * Sort $server_themes
     */
    public function sort_server_themes(string $order = 'date'): void
    {
        switch ($order) {
            case 'date':
                krsort($this->server_themes);
                break;
            case 'revision':
                usort($this->server_themes, $this->extension_revision_compare(...));
                break;
            case 'name':
                uasort($this->server_themes, $this->extension_name_compare(...));
                break;
            case 'author':
                uasort($this->server_themes, $this->extension_author_compare(...));
                break;
            case 'downloads':
                usort($this->server_themes, $this->extension_downloads_compare(...));
                break;
        }
    }

    /**
     * Extract theme files from archive
     *
     * @param string $action - install or upgrade
     * @param string $revision - remote revision identifier (numeric)
     * @param string $dest - theme id or extension id
     */
    public function extract_theme_files($action, $revision, $dest, ?string &$theme_id = null): string
    {
        /** @var Logger $logger */
        global $logger;

        // PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url']) in
        // one branch of include/common.inc.php, so PHPStan can't prove it's a
        // string across that file boundary — narrow it once here (same
        // pattern as updates.class.php::get_server_extensions()).
        $pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

        if ($archive = tempnam(Config::themesPath(), 'zip')) {
            $url = $pem_base_url . '/download.php';
            $get_data = [
                'rid' => $revision,
                'origin' => 'piwigo_' . $action,
            ];

            if ((bool) ($handle = @fopen($archive, 'wb')) and HttpClientService::fetchToFile($handle, $url, $get_data)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                $zip_extractor = new ZipExtractor();
                if (($list = $zip_extractor->listFilenames($archive)) !== null) {
                    // Declared before the loop (rather than relying on
                    // isset($main_filepath) to narrow it after the loop) --
                    // PHPStan doesn't reliably preserve isset()-based
                    // narrowing for a variable only ever conditionally
                    // assigned inside a foreach body.
                    $main_filepath = null;
                    foreach ($list as $filename) {
                        // we search main.inc.php in archive
                        if (basename($filename) == 'themeconf.inc.php'
                          and ($main_filepath === null
                          or strlen($filename) < strlen($main_filepath))) {
                            $main_filepath = $filename;
                        }
                    }

                    if ($main_filepath !== null) {
                        $logger->debug(__FUNCTION__ . ', $main_filepath = ' . $main_filepath);

                        $root = dirname($main_filepath); // main.inc.php path in archive
                        if ($action == 'upgrade') {
                            $theme_id = $dest;
                        } else {
                            $theme_id = ($root == '.' ? 'extension_' . $dest : basename($root));
                        }
                        $extract_path = Config::themesPath() . $theme_id;
                        $logger->debug(__FUNCTION__ . ', $extract_path = ' . $extract_path);

                        if (($result = $zip_extractor->extract($archive, $extract_path, $root)) !== null) {
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
                              and (bool) ($old_files = file($extract_path . '/obsolete.list', FILE_IGNORE_NEW_LINES))) {
                                $old_files[] = 'obsolete.list';

                                $logger->debug(__FUNCTION__ . ', $old_files = {' . join('},{', $old_files) . '}');

                                $extract_path_realpath = realpath($extract_path);

                                // realpath() failing here would mean
                                // $extract_path (just populated by
                                // ZipExtractor::extract() above) doesn't
                                // actually exist as a real directory — skip the
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
                                        FilesystemHelper::deltree($path, Config::themesPath() . 'trash');
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
     * Sort functions
     */
    /**
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
        // 'extension_name' comes from an untyped unserialize() of a remote
        // PEM payload (see get_server_themes()); only cast scalars actually
        // safe to stringify, treat anything else as empty for comparison.
        $a_name = $a['extension_name'] ?? null;
        $b_name = $b['extension_name'] ?? null;
        $a_name = is_scalar($a_name) ? (string) $a_name : '';
        $b_name = is_scalar($b_name) ? (string) $b_name : '';
        return strcmp(strtolower($a_name), strtolower($b_name));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function extension_author_compare(array $a, array $b): int
    {
        // see extension_name_compare()'s comment on 'extension_name'
        $a_author = $a['author_name'] ?? null;
        $b_author = $b['author_name'] ?? null;
        $a_author = is_scalar($a_author) ? (string) $a_author : '';
        $b_author = is_scalar($b_author) ? (string) $b_author : '';
        $r = strcasecmp($a_author, $b_author);
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
    public function theme_author_compare(array $a, array $b): int
    {
        // see extension_name_compare()'s comment on 'extension_name'
        $a_author = $a['author'] ?? null;
        $b_author = $b['author'] ?? null;
        $a_author = is_scalar($a_author) ? (string) $a_author : '';
        $b_author = is_scalar($b_author) ? (string) $b_author : '';
        $r = strcasecmp($a_author, $b_author);
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

    public function sort_themes_by_state(): void
    {
        uasort($this->fs_themes, name_compare(...));

        $active_themes = [];
        $inactive_themes = [];
        $not_installed = [];

        foreach ($this->fs_themes as $theme_id => $theme) {
            if (isset($this->db_themes_by_id[$theme_id])) {
                $this->db_themes_by_id[$theme_id]['state'] == 'active' ?
                  $active_themes[$theme_id] = $theme : $inactive_themes[$theme_id] = $theme;
            } else {
                $not_installed[$theme_id] = $theme;
            }
        }
        $this->fs_themes = $active_themes + $inactive_themes + $not_installed;
    }
}
