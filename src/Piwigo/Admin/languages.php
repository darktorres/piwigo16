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
use Piwigo\Core\AppInfo;
use Piwigo\Core\Logger;
use Piwigo\Db\Tables;

class languages
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public $fs_languages = [];

    /**
     * @var array<string, mixed>
     */
    public $db_languages = [];

    /**
     * @var array<int|string, array<string, mixed>>
     */
    public $server_languages = [];

    /**
     * Initialize $fs_languages and $db_languages
     */
    public function __construct(?string $target_charset = null)
    {
        $this->get_fs_languages($target_charset);
    }

    /**
     * Perform requested actions
     * @param string $action - action
     * @param string $language_id - language id
     * @return list<('CANNOT ACTIVATE - LANGUAGE IS ALREADY ACTIVATED'|'CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED'|'CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE'|'CANNOT DELETE - LANGUAGE DOES NOT EXIST'|'CANNOT DELETE - LANGUAGE IS ACTIVATED')>
     */
    public function perform_action($action, $language_id): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! (bool) $conf['enable_extensions_install'] and $action == 'delete') {
            die('Piwigo extensions install/update/delete system is disabled');
        }

        if (isset($this->db_languages[$language_id])) {
            $crt_db_language = $this->db_languages[$language_id];
        }

        $errors = [];

        switch ($action) {
            case 'activate':
                if (isset($crt_db_language)) {
                    $errors[] = 'CANNOT ACTIVATE - LANGUAGE IS ALREADY ACTIVATED';
                    break;
                }

                // 'version' and 'name' are populated by get_fs_languages()
                // via array_map(htmlspecialchars(...), ...), so both are
                // strings there; narrow the mixed read-back from the
                // array<string, mixed>-typed $fs_languages property before
                // interpolating into SQL.
                $fs_version = $this->fs_languages[$language_id]['version'] ?? null;
                $fs_version = is_scalar($fs_version) ? (string) $fs_version : '0';
                $fs_name = $this->fs_languages[$language_id]['name'] ?? null;
                $fs_name = is_scalar($fs_name) ? (string) $fs_name : '';

                $query = '
INSERT INTO ' . Tables::languages() . '
  (id, version, name)
  VALUES(\'' . $language_id . '\',
         \'' . $fs_version . '\',
         \'' . $fs_name . '\')
;';
                pwg_query($query);
                break;

            case 'deactivate':
                if (! isset($crt_db_language)) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED';
                    break;
                }

                if ($language_id == (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultLanguage()) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE';
                    break;
                }

                $query = '
DELETE
  FROM ' . Tables::languages() . '
  WHERE id= \'' . $language_id . '\'
;';
                pwg_query($query);
                break;

            case 'delete':
                if (! empty($crt_db_language)) {
                    $errors[] = 'CANNOT DELETE - LANGUAGE IS ACTIVATED';
                    break;
                }
                if (! isset($this->fs_languages[$language_id])) {
                    $errors[] = 'CANNOT DELETE - LANGUAGE DOES NOT EXIST';
                    break;
                }

                // Set default language to user who are using this language
                $query = '
UPDATE ' . Tables::userInfos() . '
  SET language = \'' . (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultLanguage() . '\'
  WHERE language = \'' . $language_id . '\'
;';
                pwg_query($query);

                deltree(PHPWG_ROOT_PATH . 'language/' . $language_id, PHPWG_ROOT_PATH . 'language/trash');
                break;

            case 'set_default':
                // $conf['default_user_id']/'guest_id' are always ints (see
                // include/config_default.inc.php); same narrowing as
                // admin/include/functions_upgrade.php and profile.php.
                $default_user_id = is_numeric($conf['default_user_id']) ? (int) $conf['default_user_id'] : 0;
                $guest_id = is_numeric($conf['guest_id']) ? (int) $conf['guest_id'] : 0;
                $query = '
UPDATE ' . Tables::userInfos() . '
  SET language = \'' . $language_id . '\'
  WHERE user_id IN (' . $default_user_id . ', ' . $guest_id . ')
;';
                pwg_query($query);
                break;
        }
        return $errors;
    }

    /**
     *  Get languages defined in the language directory
     */
    public function get_fs_languages(?string $target_charset = null): void
    {
        if (empty($target_charset)) {
            $target_charset = get_pwg_charset();
        }
        $target_charset = strtolower($target_charset);

        $dir = opendir(PHPWG_ROOT_PATH . 'language');
        if ($dir === false) {
            return;
        }
        while ((bool) ($file = readdir($dir))) {
            if ($file != '.' and $file != '..') {
                $path = PHPWG_ROOT_PATH . 'language/' . $file;
                if (is_dir($path) and ! is_link($path)
                    and (bool) preg_match('/^[a-zA-Z0-9-_]+$/', $file)
                    // This rewrite migrated every language/ locale from the
                    // legacy common.lang.php marker to gettext common.po;
                    // zero common.lang.php files exist anywhere on disk, so
                    // the old check silently found no languages at all
                    // (same marker-file mismatch already fixed in
                    // ExtensionScanner::scanLanguage() for the new
                    // service-layer admin pages -- this is the old
                    // god-class's own copy of that scan, still used
                    // directly by install.php).
                    and file_exists($path . '/common.po')
                ) {
                    $language = [
                        'name' => $file,
                        'code' => $file,
                        // Bundled core languages aren't independently-versioned
                        // PEM packages the way plugins/themes are, and the PO
                        // format carries no version/author/URI header at all
                        // -- default to the current app version (matching
                        // ExtensionScanner::scanLanguage()) rather than '0',
                        // which would falsely flag every bundled language as
                        // outdated against the PEM server.
                        'version' => AppInfo::VERSION,
                        'uri' => '',
                        'author' => '',
                    ];
                    $plg_data_lines = file($path . '/common.po');
                    if ($plg_data_lines === false) {
                        continue;
                    }
                    $plg_data = implode('', $plg_data_lines);

                    if ((bool) preg_match('/"X-Piwigo-Language-Name:\\s*(.+?)\\\\n"/', $plg_data, $val)) {
                        $language['name'] = trim($val[1]);
                        $converted_name = convert_charset($language['name'], 'utf-8', $target_charset);
                        if ($converted_name !== false) {
                            $language['name'] = $converted_name;
                        }
                    }
                    // The old common.lang.php convention crammed regional
                    // disambiguation directly into the name ("English [UK]",
                    // "Français [FR]", "Brasil [BR]" -- inconsistent, since
                    // that last one names the country twice instead of the
                    // language). The .po migration correctly split this into
                    // separate X-Piwigo-Language-Name/X-Piwigo-Country
                    // headers, but nothing recombined them for display,
                    // silently losing the regional disambiguation admins
                    // need to tell e.g. en_UK from en_US apart in the
                    // language list. Restore it, using the cleaner
                    // structured data instead of a bracketed code.
                    if ((bool) preg_match('/"X-Piwigo-Country:\\s*(.+?)\\\\n"/', $plg_data, $val)) {
                        $country = trim($val[1]);
                        $converted_country = convert_charset($country, 'utf-8', $target_charset);
                        if ($converted_country !== false) {
                            $country = $converted_country;
                        }
                        if ($country !== '') {
                            $language['name'] .= ' (' . $country . ')';
                        }
                    }

                    // IMPORTANT SECURITY !
                    $language = array_map(htmlspecialchars(...), $language);
                    $this->fs_languages[$file] = $language;
                }
            }
        }
        closedir($dir);
        @uasort($this->fs_languages, name_compare(...));
    }

    public function get_db_languages(): void
    {
        $query = '
  SELECT id, name
    FROM ' . Tables::languages() . '
    ORDER BY name ASC
  ;';
        $result = pwg_query($query);

        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            // 'id' is the languages table's primary key (NOT NULL); guard it
            // anyway since pwg_db_fetch_assoc() types every column string|null.
            $id = $row['id'];
            if (! is_string($id)) {
                continue;
            }
            $this->db_languages[$id] = $row['name'];
        }
    }

    /**
     * Retrieve PEM server datas to $server_languages
     */
    public function get_server_languages(bool $new = false): bool
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
            'category_id' => $conf['pem_languages_category'],
            'format' => 'php',
        ];

        // Retrieve PEM versions
        $version = AppInfo::VERSION;
        $versions_to_check = [];
        $url = $pem_base_url . '/api/get_version_list.php';
        // $result is never a resource here: no fopen() handle is passed to
        // fetchRemote() above.
        if (fetchRemote($url, $result, $get_data) and is_string($result) and (bool) ($pem_versions = @unserialize($result))) {
            // unserialize() of a remote PEM response is genuinely untyped —
            // validate it's an array of arrays before indexing into it
            // below, rather than trusting the external payload (see the
            // identical narrowing in plugins.class.php::get_versions_to_check()).
            if (is_array($pem_versions)) {
                if (! (bool) preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                    $first_pem_version = $pem_versions[0] ?? null;
                    if (is_array($first_pem_version)) {
                        $first_pem_version_name = $first_pem_version['name'] ?? null;
                        if (is_string($first_pem_version_name)) {
                            $version = $first_pem_version_name;
                        }
                    }
                }
                $branch = get_branch_from_version($version);
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
        }
        if (empty($versions_to_check)) {
            return false;
        }

        // Languages to check
        $languages_to_check = [];
        foreach ($this->fs_languages as $fs_language) {
            // 'extension' is only ever set by get_fs_languages() to the
            // numeric-string PEM extension id it parsed from common.lang.php.
            if (isset($fs_language['extension']) and is_scalar($fs_language['extension'])) {
                $languages_to_check[] = (string) $fs_language['extension'];
            }
        }

        // Retrieve PEM languages infos
        $url = $pem_base_url . '/api/get_revision_list.php';
        $get_data = array_merge(
            $get_data,
            [
                'last_revision_only' => 'true',
                'version' => implode(',', $versions_to_check),
                'lang' => $user['language'],
                'get_nb_downloads' => 'true',
            ]
        );
        if (! empty($languages_to_check)) {
            if ($new) {
                $get_data['extension_exclude'] = implode(',', $languages_to_check);
            } else {
                $get_data['extension_include'] = implode(',', $languages_to_check);
            }
        }

        // $result is never a resource here: no fopen() handle is passed to
        // fetchRemote() above.
        if (fetchRemote($url, $result, $get_data) and is_string($result)) {
            $pem_languages = @unserialize($result);
            if (! is_array($pem_languages)) {
                return false;
            }
            foreach ($pem_languages as $language) {
                if (! is_array($language) || ! isset($language['extension_id'])) {
                    continue;
                }
                /** @var array<string, mixed> $language */
                $extension_id = $language['extension_id'];
                if (! is_string($extension_id) && ! is_int($extension_id)) {
                    continue;
                }
                $extension_name = $language['extension_name'] ?? null;
                if (is_string($extension_name) and (bool) preg_match('/^.*? \[[A-Z]{2}\]$/', $extension_name)) {
                    $this->server_languages[$extension_id] = $language;
                }
            }
            @uasort($this->server_languages, $this->extension_name_compare(...));
            return true;
        }
        return false;
    }

    /**
     * Extract language files from archive
     *
     * @param string $action - install or upgrade
     * @param string $revision - remote revision identifier (numeric)
     * @param string $dest - language id or extension id
     * @return mixed
     */
    public function extract_language_files($action, $revision, $dest = '')
    {
        /** @var Logger $logger */
        global $logger;

        // PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url']) in
        // one branch of include/common.inc.php, so PHPStan can't prove it's a
        // string across that file boundary — narrow it once here (same
        // pattern as updates.class.php::get_server_extensions()).
        $pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

        if ($archive = tempnam(PHPWG_ROOT_PATH . 'language', 'zip')) {
            $url = $pem_base_url . '/download.php';
            $get_data = [
                'rid' => $revision,
                'origin' => 'piwigo_' . $action,
            ];

            if ((bool) ($handle = @fopen($archive, 'wb')) and fetchRemote($url, $handle, $get_data)) {
                // fetchRemote()'s &$dest out-param could in principle reset
                // to a string, but only when the value passed in wasn't
                // already a resource — $handle always is here (just opened
                // above), so it's still a resource after the call.
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
                        // we search common.lang.php in archive
                        if (basename($filename) == 'common.lang.php'
                          and ($main_filepath === null
                          or strlen($filename) < strlen($main_filepath))) {
                            $main_filepath = $filename;
                        }
                    }

                    if ($main_filepath !== null) {
                        $logger->debug(__FUNCTION__ . ', $main_filepath = ' . $main_filepath);

                        $root = basename(dirname($main_filepath)); // common.lang.php path in archive
                        if ((bool) preg_match('/^[a-z]{2}_[A-Z]{2}$/', $root)) {
                            if ($action == 'install') {
                                $dest = $root;
                            }
                            $extract_path = PHPWG_ROOT_PATH . 'language/' . $dest;

                            $logger->debug(__FUNCTION__ . ', $extract_path = ' . $extract_path);

                            if (($result = $zip_extractor->extract($archive, $extract_path, $root)) !== null) {
                                // extraction succeeded; 'ok' if the extracted result
                                // list doesn't happen to include the main file itself
                                $status = 'ok';
                                foreach ($result as $file) {
                                    if ($file['stored_filename'] == $main_filepath) {
                                        $status = $file['status'];
                                        break;
                                    }
                                }
                                if ($status == 'ok') {
                                    $this->get_fs_languages();
                                    if ($action == 'install') {
                                        $this->perform_action('activate', $dest);
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
                                    // obsolete-file cleanup rather than risk
                                    // the traversal check below against a
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
                                            deltree($path, PHPWG_ROOT_PATH . 'language/trash');
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
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function extension_name_compare(array $a, array $b): int
    {
        // 'extension_name' comes from an untyped unserialize() of a remote
        // PEM payload (see get_server_languages()); only cast scalars
        // actually safe to stringify, treat anything else as empty for
        // comparison (same pattern as plugins.class.php::extension_name_compare()).
        $a_name = $a['extension_name'] ?? null;
        $b_name = $b['extension_name'] ?? null;
        $a_name = is_scalar($a_name) ? (string) $a_name : '';
        $b_name = is_scalar($b_name) ? (string) $b_name : '';
        return strcmp(strtolower($a_name), strtolower($b_name));
    }
}
