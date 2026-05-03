<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Users\CurrentUser;

class Themes
{
    /** @var array<string, array<string,mixed>> */
    public array $fs_themes = [];
    /** @var array<string, array<string,mixed>> */
    public array $db_themes_by_id = [];
    /** @var array<int|string, array<string, mixed>> */
    public array $server_themes = [];

    /**
     * Initialize $fs_themes and $db_themes_by_id
    */
    public function __construct()
    {
        $this->get_fs_themes();

        foreach ($this->get_db_themes() as $db_theme) {
            if (isset($db_theme['id'])) {
                $this->db_themes_by_id[(string) $db_theme['id']] = $db_theme;
            }
        }
    }

    /**
     * Returns the maintain class of a theme
     * or build a new class with the procedural methods
     */
    private static function build_maintain_class(string $theme_id): \Piwigo\Admin\ThemeMaintain
    {
        $file_to_include = PHPWG_THEMES_PATH.'/'.$theme_id.'/admin/maintain.inc.php';
        $classname = $theme_id.'_maintain';

        if (file_exists($file_to_include)) {
            include_once($file_to_include);

            if (class_exists($classname) && is_a($classname, \Piwigo\Admin\ThemeMaintain::class, true)) {
                return instantiate_theme_maintain($classname, $theme_id);
            }
        }

        return new DummyThemeMaintain($theme_id);
    }

    /**
     * Perform requested actions
     * @param string $action
     * @return list<mixed>
     */
    public function perform_action(string $action, string $theme_id): array
    {
        if (!\Piwigo\Config\Config::enableExtensionsInstall() and 'delete' == $action) {
            die('Piwigo extensions install/update/delete system is disabled');
        }

        if (isset($this->db_themes_by_id[$theme_id])) {
            $crt_db_theme = $this->db_themes_by_id[$theme_id];
        }

        $theme_maintain = self::build_maintain_class($theme_id);

        /** @var list<mixed> $errors */
        $errors = [];
        $activity_details = ['theme_id' => $theme_id];

        switch ($action) {
            case 'activate':
                if (isset($crt_db_theme)) {
                    // the theme is already active
                    break;
                }

                if ('default' == $theme_id) {
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

                if ($this->fs_themes[$theme_id]['mobile']
                    and !empty(\Piwigo\Config\Config::mobilTheme())
                    and \Piwigo\Config\Config::mobilTheme() != $theme_id) {
                    $errors[] = l10n('You can activate only one mobile theme.');
                    break;
                }

                $vRaw = $this->fs_themes[$theme_id]['version'] ?? null;
                $version = is_scalar($vRaw) ? (string) $vRaw : '';
                $theme_maintain->activate($version, $errors);
                $errors = trigger_change('theme_activate_errors', $errors);

                if (empty($errors)) {
                    $tvRaw = $this->fs_themes[$theme_id]['version'] ?? null;
                    $themeVersion = is_scalar($tvRaw) ? (string) $tvRaw : '';
                    $tnRaw = $this->fs_themes[$theme_id]['name'] ?? null;
                    $themeName = is_scalar($tnRaw) ? (string) $tnRaw : '';
                    $query = '
INSERT INTO '.THEMES_TABLE.'
  (id, version, name)
  VALUES(\''.$theme_id.'\',
         \''.$themeVersion.'\',
         \''.$themeName.'\')
;';
                    pwg_query($query);

                    $activity_details['version'] = $themeVersion;

                    if ($this->fs_themes[$theme_id]['mobile']) {
                        conf_update_param('mobile_theme', $theme_id);
                    }
                }
                break;

            case 'deactivate':
                if (!isset($crt_db_theme)) {
                    // the theme is already inactive
                    break;
                }

                // you can't deactivate the last theme
                if (count($this->db_themes_by_id) <= 1) {
                    $errors[] = l10n('Impossible to deactivate this theme, you need at least one theme.');
                    break;
                }

                if ($theme_id == get_default_theme()) {
                    // find a random theme to replace
                    $new_theme = null;

                    $query = '
SELECT id
  FROM '.THEMES_TABLE.'
  WHERE id != \''.$theme_id.'\'
;';
                    $result = pwg_query($query);
                    if (pwg_db_num_rows($result) == 0) {
                        $new_theme = 'default';
                    } else {
                        $new_theme_row = pwg_db_fetch_row($result) ?? [null];
                        $new_theme = (string) ($new_theme_row[0] ?? 'default');
                    }

                    $this->set_default_theme($new_theme);
                }

                $theme_maintain->deactivate();

                $query = '
DELETE
  FROM '.THEMES_TABLE.'
  WHERE id= \''.$theme_id.'\'
;';
                pwg_query($query);

                if ($this->fs_themes[$theme_id]['mobile']) {
                    conf_update_param('mobile_theme', '');
                }
                break;

            case 'delete':
                if (!empty($crt_db_theme)) {
                    $errors[] = 'CANNOT DELETE - THEME IS INSTALLED';
                    break;
                }
                if (!isset($this->fs_themes[$theme_id])) {
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

                $theme_maintain->delete();

                include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
                deltree(PHPWG_THEMES_PATH.$theme_id, PHPWG_THEMES_PATH . 'trash');
                break;

            case 'set_default':
                // first we need to know which users are using the current default theme
                $this->set_default_theme($theme_id);
                break;
        }

        pwg_activity('system', ACTIVITY_SYSTEM_THEME, $action, $activity_details);

        return array_values($errors);
    }

    public function missing_parent_theme(string $theme_id): ?string
    {
        if (!isset($this->fs_themes[$theme_id]['parent'])) {
            return null;
        }

        $parent = $this->fs_themes[$theme_id]['parent'];
        $parent = is_scalar($parent) ? (string) $parent : '';

        if ('default' == $parent) {
            return null;
        }

        if (!isset($this->fs_themes[$parent])) {
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
                $cn = $test_child['name'] ?? null;
                $children[] = is_scalar($cn) ? (string) $cn : '';
            }
        }

        return $children;
    }

    public function set_default_theme(string $theme_id): void
    {
        // first we need to know which users are using the current default theme
        $default_theme = get_default_theme();

        $query = '
SELECT
    user_id
  FROM '.USER_INFOS_TABLE.'
  WHERE theme = \''.$default_theme.'\'
;';
        $user_ids = array_unique(
            array_merge(
                query2array($query, null, 'user_id'),
                [\Piwigo\Config\Config::guestId(), \Piwigo\Config\Config::defaultUserId()]
            )
        );

        // $user_ids can't be empty, at least the default user has the default
        // theme

        $query = '
UPDATE '.USER_INFOS_TABLE.'
  SET theme = \''.$theme_id.'\'
  WHERE user_id IN ('.implode(',', $user_ids).')
;';
        pwg_query($query);
    }

    /**
     * @return list<array<string, float|int|string|null>>
     */
    public function get_db_themes(?string $id = ''): array
    {
        $query = '
SELECT
    *
  FROM '.THEMES_TABLE;

        $clauses = [];
        if (!empty($id)) {
            $clauses[] = 'id = \''.$id.'\'';
        }
        if (count($clauses) > 0) {
            $query .= '
  WHERE '. implode(' AND ', $clauses);
        }

        $result = pwg_query($query);
        $themes = [];
        while ($row = pwg_db_fetch_assoc($result)) {
            $themes[] = $row;
        }
        return $themes;
    }


    /**
    *  Get themes defined in the theme directory
    */
    public function get_fs_themes(): void
    {
        $dir = opendir(PHPWG_THEMES_PATH);
        if ($dir === false) {
            return;
        }

        while ($file = readdir($dir)) {
            if ($file != '.' and $file != '..') {
                $path = PHPWG_THEMES_PATH.$file;
                if (is_dir($path)
                    and preg_match('/^[a-zA-Z0-9-_]+$/', $file)
                    and file_exists($path.'/themeconf.inc.php')
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
                    $theme_data = implode('', file($path.'/themeconf.inc.php') ?: []);
                    if (preg_match('|Theme Name:\\s*(.+)|', $theme_data, $val)) {
                        $theme['name'] = trim($val[1]);
                    }
                    if (preg_match('|Version:\\s*([\\w.-]+)|', $theme_data, $val)) {
                        $theme['version'] = trim($val[1]);
                    }
                    if (preg_match('|Theme URI:\\s*(https?:\\/\\/.+)|', $theme_data, $val)) {
                        $theme['uri'] = trim($val[1]);
                    }
                    if (is_string($desc = load_language('description.txt', $path.'/', ['return' => true]))) {
                        $theme['description'] = trim($desc);
                    } elseif (preg_match('|Description:\\s*(.+)|', $theme_data, $val)) {
                        $theme['description'] = trim($val[1]);
                    }
                    if (preg_match('|Author:\\s*(.+)|', $theme_data, $val)) {
                        $theme['author'] = trim($val[1]);
                    }
                    if (preg_match('|Author URI:\\s*(https?:\\/\\/.+)|', $theme_data, $val)) {
                        $theme['author uri'] = trim($val[1]);
                    }
                    if (!empty($theme['uri']) and strpos($theme['uri'], 'extension_view.php?eid=')) {
                        list(, $extension) = explode('extension_view.php?eid=', $theme['uri']);
                        if (is_numeric($extension)) {
                            $theme['extension'] = $extension;
                        }
                    }
                    if (preg_match('/["\']parent["\'][^"\']+["\']([^"\']+)["\']/', $theme_data, $val)) {
                        $theme['parent'] = $val[1];
                    }
                    if (preg_match('/["\']activable["\'].*?(true|false)/i', $theme_data, $val)) {
                        $theme['activable'] = get_boolean($val[1]);
                    }
                    if (preg_match('/["\']mobile["\'].*?(true|false)/i', $theme_data, $val)) {
                        $theme['mobile'] = get_boolean($val[1]);
                    }
                    if (preg_match('/["\']use_standard_pages["\'].*?(true|false)/i', $theme_data, $val)) {
                        $theme['use_standard_pages'] = get_boolean($val[1]);
                    }

                    // screenshot
                    $screenshot_path = $path.'/screenshot.png';
                    if (file_exists($screenshot_path)) {
                        $theme['screenshot'] = $screenshot_path;
                    } else {
                        $admin_theme = userprefs_get_param('admin_theme', 'roma');
                        $admin_theme = is_scalar($admin_theme) ? (string) $admin_theme : 'roma';
                        $theme['screenshot'] =
                          PHPWG_ROOT_PATH.'admin/themes/'
                          .$admin_theme
                          .'/images/missing_screenshot.png'
                        ;
                    }

                    $admin_file = $path.'/admin/admin.inc.php';
                    if (file_exists($admin_file)) {
                        $theme['admin_uri'] = get_root_url().'admin.php?page=theme&theme='.$file;
                    }

                    // IMPORTANT SECURITY !
                    $theme = array_map(fn ($v) => is_string($v) ? htmlspecialchars($v) : $v, $theme);
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
        $get_data = [
          'category_id' => \Piwigo\Config\Config::pemThemesCategory(),
          'format' => 'php',
        ];

        // Retrieve PEM versions
        $version = PHPWG_VERSION;
        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php';
        if (fetchRemote($url, $result, $get_data) and $pem_versions = @unserialize($result)) {
            if (!is_array($pem_versions)) {
                return false;
            }
            if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $pv0 = $pem_versions[0] ?? null;
                $pv0name = is_array($pv0) && isset($pv0['name']) ? $pv0['name'] : null;
                $version = is_scalar($pv0name) ? (string) $pv0name : $version;
            }
            $branch = get_branch_from_version($version);
            foreach ($pem_versions as $pem_version) {
                if (!is_array($pem_version) || !isset($pem_version['name'], $pem_version['id'])) {
                    continue;
                }
                $pvName = $pem_version['name'];
                $pvId = $pem_version['id'];
                if (str_starts_with(is_scalar($pvName) ? (string) $pvName : '', $branch)) {
                    $versions_to_check[] = is_scalar($pvId) ? (string) $pvId : '';
                }
            }
        }
        if (empty($versions_to_check)) {
            return false;
        }

        // Themes to check
        $themes_to_check = [];
        foreach ($this->fs_themes as $fs_theme) {
            if (isset($fs_theme['extension'])) {
                $themes_to_check[] = is_scalar($fs_theme['extension']) ? (string) $fs_theme['extension'] : '';
            }
        }

        // Retrieve PEM themes infos
        $url = PEM_URL . '/api/get_revision_list-next.php';
        $get_data = array_merge(
            $get_data,
            [
      'last_revision_only' => 'true',
      'version' => implode(',', $versions_to_check),
      'lang' => substr(CurrentUser::get()->language, 0, 2),
      'get_nb_downloads' => 'true',
      ]
        );

        if (!empty($themes_to_check)) {
            if ($new) {
                $get_data['extension_exclude'] = implode(',', $themes_to_check);
            } else {
                $get_data['extension_include'] = implode(',', $themes_to_check);
            }
        }
        if (fetchRemote($url, $result, $get_data)) {
            $pem_themes = @unserialize($result);
            if (!is_array($pem_themes)) {
                return false;
            }
            foreach ($pem_themes as $theme) {
                if (is_array($theme) && isset($theme['extension_id']) && (is_string($theme['extension_id']) || is_int($theme['extension_id']))) {
                    $this->server_themes[$theme['extension_id']] = $theme;
                }
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
                usort($this->server_themes, fn (array $a, array $b): int => $this->extension_revision_compare($a, $b));
                break;
            case 'name':
                uasort($this->server_themes, fn (array $a, array $b): int => $this->extension_name_compare($a, $b));
                break;
            case 'author':
                uasort($this->server_themes, fn (array $a, array $b): int => $this->extension_author_compare($a, $b));
                break;
            case 'downloads':
                usort($this->server_themes, fn (array $a, array $b): int => $this->extension_downloads_compare($a, $b));
                break;
        }
    }

    /**
     * Extract theme files from archive
     *
     */
    public function extract_theme_files(string $action, string $revision, string $dest, ?string &$theme_id = null): string
    {
        $logger = \Piwigo\Core\LoggerRegistry::current();

        if ($archive = tempnam(PHPWG_THEMES_PATH, 'zip')) {
            $url = PEM_URL . '/download.php';
            $get_data = [
              'rid' => $revision,
              'origin' => 'piwigo_'.$action,
            ];

            $handle = @fopen($archive, 'wb');
            if ($handle !== false) {
                $fh = $handle;
                if (fetchRemote($url, $handle, $get_data)) {
                    fclose($fh);
                    $zip = new \PclZip($archive);
                    if ($list = $zip->listContent()) {
                        $main_filepath = null;
                        $status = 'ok';
                        foreach ($list as $file) {
                            // we search main.inc.php in archive
                            if (basename((string) $file['filename']) == 'themeconf.inc.php'
                              and ($main_filepath === null
                              or strlen((string) $file['filename']) < strlen($main_filepath))) {
                                $main_filepath = $file['filename'];
                            }
                        }

                        $logger->debug(__FUNCTION__.', $main_filepath = '.$main_filepath);

                        if (isset($main_filepath)) {
                            $root = dirname($main_filepath); // main.inc.php path in archive
                            if ($action == 'upgrade') {
                                $theme_id = $dest;
                            } else {
                                $theme_id = ($root == '.' ? 'extension_' . $dest : basename($root));
                            }
                            $extract_path = PHPWG_THEMES_PATH . $theme_id;
                            $logger->debug(__FUNCTION__.', $extract_path = '.$extract_path);

                            if (
                                $result = $zip->extract(
                                    PCLZIP_OPT_PATH,
                                    $extract_path,
                                    PCLZIP_OPT_REMOVE_PATH,
                                    $root,
                                    PCLZIP_OPT_REPLACE_NEWER
                                )
                            ) {
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
                                            @unlink($path);
                                        } elseif (is_dir($path)) {
                                            deltree($path, PHPWG_THEMES_PATH . 'trash');
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
            @unlink($archive);
        }
        return $status;
    }

    /**
     * Sort functions
     */
    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
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
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extension_name_compare(array $a, array $b): int
    {
        $na = $a['extension_name'] ?? null;
        $nb = $b['extension_name'] ?? null;
        return strcmp(strtolower(is_scalar($na) ? (string) $na : ''), strtolower(is_scalar($nb) ? (string) $nb : ''));
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extension_author_compare(array $a, array $b): int
    {
        $na = $a['author_name'] ?? null;
        $nb = $b['author_name'] ?? null;
        $r = strcasecmp(is_scalar($na) ? (string) $na : '', is_scalar($nb) ? (string) $nb : '');
        if ($r == 0) {
            return $this->extension_name_compare($a, $b);
        } else {
            return $r;
        }
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function theme_author_compare(array $a, array $b): int
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
