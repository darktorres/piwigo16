<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use PclZip;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_user;

class languages
{
    use ExtensionFunctionUpdater;

    public array $fs_languages = [];

    public array $db_languages = [];

    public array $server_languages = [];

    /**
     * Initialize $fs_languages and $db_languages
     */
    public function __construct(
        ?string $target_charset = null
    ) {
        $this->get_fs_languages($target_charset);
    }

    /**
     * Perform requested actions
     * @return array - errors
     */
    public function perform_action(
        string $action,
        string $language_id
    ): array {
        global $conf;

        if (! $conf['enable_extensions_install'] and
            $action == 'delete'
        ) {
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

                $query = <<<SQL
                    INSERT INTO languages
                        (id, version, name)
                    VALUES
                        ('{$language_id}', '{$this->fs_languages[$language_id]['version']}', '{$this->fs_languages[$language_id]['name']}');
                    SQL;
                functions_mysqli::pwg_query($query);
                break;

            case 'deactivate':
                if (! isset($crt_db_language)) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED';
                    break;
                }

                if ($language_id == functions_user::get_default_language()) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE';
                    break;
                }

                $query = <<<SQL
                    DELETE FROM languages
                    WHERE id = '{$language_id}';
                    SQL;
                functions_mysqli::pwg_query($query);
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

                // Set the default language to user who is using this language
                $default_language = functions_user::get_default_language();
                $query = <<<SQL
                    UPDATE user_infos
                    SET language = '{$default_language}'
                    WHERE language = '{$language_id}';
                    SQL;
                functions_mysqli::pwg_query($query);

                functions_admin::deltree(PHPWG_ROOT_PATH . 'language/' . $language_id, PHPWG_ROOT_PATH . 'language/trash');
                break;

            case 'set_default':
                $query = <<<SQL
                    UPDATE user_infos
                    SET language = '{$language_id}'
                    WHERE user_id IN ({$conf['default_user_id']}, {$conf['guest_id']});
                    SQL;
                functions_mysqli::pwg_query($query);
                break;
        }

        return $errors;
    }

    /**
     *  Get languages defined in the language directory
     */
    public function get_fs_languages(
        ?string $target_charset = null
    ): void {
        if (empty($target_charset)) {
            $target_charset = functions::get_pwg_charset();
        }

        $target_charset = strtolower($target_charset);

        $dir = opendir(PHPWG_ROOT_PATH . 'language');

        while ($file = readdir($dir)) {
            if ($file != '.' and
                $file != '..'
            ) {
                $path = PHPWG_ROOT_PATH . 'language/' . $file;

                if (is_dir($path) and
                    ! is_link($path) and
                    preg_match('/^[a-zA-Z0-9-_]+$/', $file) and
                    file_exists($path . '/common.lang.php')
                ) {
                    $language = [
                        'name' => $file,
                        'code' => $file,
                        'version' => '0',
                        'uri' => '',
                        'author' => '',
                    ];
                    $plg_data = implode('', file($path . '/common.lang.php'));

                    if (preg_match('|Language Name:\\s*(.+)|', $plg_data, $val)) {
                        $language['name'] = trim($val[1]);
                        $language['name'] = functions::convert_charset($language['name'], 'utf-8', $target_charset);
                    }

                    if (preg_match('|Version:\\s*([\\w.-]+)|', $plg_data, $val)) {
                        $language['version'] = trim($val[1]);
                    }

                    if (preg_match('|Language URI:\\s*(https?:\\/\\/.+)|', $plg_data, $val)) {
                        $language['uri'] = trim($val[1]);
                    }

                    if (preg_match('|Author:\\s*(.+)|', $plg_data, $val)) {
                        $language['author'] = trim($val[1]);
                    }

                    if (preg_match('|Author URI:\\s*(https?:\\/\\/.+)|', $plg_data, $val)) {
                        $language['author uri'] = trim($val[1]);
                    }

                    if (! empty($language['uri']) and
                        strpos($language['uri'], 'extension_view.php?eid=')
                    ) {
                        list(, $extension) = explode('extension_view.php?eid=', $language['uri']);

                        if (is_numeric($extension)) {
                            $language['extension'] = $extension;
                        }
                    }

                    // IMPORTANT SECURITY !
                    $language = array_map('htmlspecialchars', $language);
                    $this->fs_languages[$file] = $language;
                }
            }
        }

        closedir($dir);
        uasort($this->fs_languages, functions_html::name_compare(...));
    }

    public function get_db_languages(): void
    {
        $query = <<<SQL
            SELECT id, name
            FROM languages
            ORDER BY name ASC;
            SQL;
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $this->db_languages[$row['id']] = $row['name'];
        }
    }

    /**
     * Retrieve PEM server datas to $server_languages
     */
    public function get_server_languages(
        bool $new = false
    ): bool {
        global $user, $conf;

        $get_data = [
            'category_id' => $conf['pem_languages_category'],
            'format' => 'php',
        ];

        // Retrieve PEM versions
        $version = PHPWG_VERSION;
        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php';

        if (functions_admin::fetchRemote($url, $result, $get_data) and
            $pem_versions = functions::safe_unserialize($result)
        ) {
            if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $version = $pem_versions[0]['name'];
            }

            $branch = functions::get_branch_from_version($version);

            foreach ($pem_versions as $pem_version) {
                if (strpos($pem_version['name'], $branch) === 0) {
                    $versions_to_check[] = $pem_version['id'];
                }
            }
        }

        if (empty($versions_to_check)) {
            return false;
        }

        // Languages to check
        $languages_to_check = [];

        foreach ($this->fs_languages as $fs_language) {
            if (isset($fs_language['extension'])) {
                $languages_to_check[] = $fs_language['extension'];
            }
        }

        // Retrieve PEM languages infos
        $url = PEM_URL . '/api/get_revision_list.php';
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

        if (functions_admin::fetchRemote($url, $result, $get_data)) {
            $pem_languages = functions::safe_unserialize($result);

            if (! is_array($pem_languages)) {
                return false;
            }

            foreach ($pem_languages as $language) {
                if (preg_match('/^.*? \[[A-Z]{2}\]$/', $language['extension_name'])) {
                    $this->server_languages[$language['extension_id']] = $language;
                }
            }

            uasort($this->server_languages, $this->extension_name_compare(...));
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
     */
    public function extract_language_files(
        string $action,
        string $revision,
        string $dest = ''
    ): string {
        global $logger;

        $archive = tempnam(PHPWG_ROOT_PATH . 'language', 'zip');

        if ($archive) {
            $url = PEM_URL . '/download.php';
            $get_data = [
                'rid' => $revision,
                'origin' => 'piwigo_' . $action,
            ];
            $handle = fopen($archive, 'wb');

            if ($handle and
                functions_admin::fetchRemote($url, $handle, $get_data)
            ) {
                fclose($handle);
                $zip = new PclZip($archive);
                $list = $zip->listContent();

                if ($list) {
                    foreach ($list as $file) {
                        // we search common.lang.php in archive
                        if (basename($file['filename']) == 'common.lang.php' and
                           (! isset($main_filepath) or strlen($file['filename']) < strlen($main_filepath))
                        ) {
                            $main_filepath = $file['filename'];
                        }
                    }

                    $logger->debug(__FUNCTION__ . ', $main_filepath = ' . $main_filepath);

                    if (isset($main_filepath)) {
                        $root = basename(dirname($main_filepath)); // common.lang.php path in archive

                        if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $root)) {
                            if ($action == 'install') {
                                $dest = $root;
                            }

                            $extract_path = PHPWG_ROOT_PATH . 'language/' . $dest;

                            $logger->debug(__FUNCTION__ . ', $extract_path = ' . $extract_path);

                            $result = $zip->extract(PCLZIP_OPT_PATH, $extract_path, PCLZIP_OPT_REMOVE_PATH, $root, PCLZIP_OPT_REPLACE_NEWER);

                            if ($result) {
                                $this->rename_files_and_folders($extract_path);
                                $this->update_function_calls($extract_path);

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

                                if (file_exists($extract_path . '/obsolete.list')) {
                                    $old_files = file($extract_path . '/obsolete.list', FILE_IGNORE_NEW_LINES);

                                    if ($old_files and
                                        ! empty($old_files)
                                    ) {
                                        $old_files[] = 'obsolete.list';
                                        $logger->debug(__FUNCTION__ . ', $old_files = {' . join('},{', $old_files) . '}');

                                        $extract_path_realpath = realpath($extract_path);

                                        foreach ($old_files as $old_file) {
                                            $old_file = trim($old_file);
                                            $old_file = trim($old_file, '/'); // prevent path starting with a "/"

                                            if (empty($old_file)) { // empty here means the extension itself
                                                continue;
                                            }

                                            $path = $extract_path . '/' . $old_file;

                                            // make sure the obsolete file is withing the extension directory, prevent traversal path
                                            $realpath = realpath($path);

                                            if ($realpath === false or
                                                strpos($realpath, $extract_path_realpath) !== 0
                                            ) {
                                                continue;
                                            }

                                            $logger->debug(__FUNCTION__ . ', to delete = ' . $path);

                                            if (is_file($path)) {
                                                unlink($path);
                                            } elseif (is_dir($path)) {
                                                functions_admin::deltree($path, PHPWG_ROOT_PATH . 'language/trash');
                                            }
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

        unlink($archive);
        return $status;
    }

    /**
     * Sort functions
     */
    public function extension_name_compare(
        array $a,
        array $b
    ): int {
        return strcmp(strtolower($a['extension_name']), strtolower($b['extension_name']));
    }
}
