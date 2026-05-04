<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Users\CurrentUser;

class Languages
{
    /** @var array<string, array<string,mixed>> */
    public array $fs_languages = [];
    /** @var array<string, array<string,mixed>> */
    public array $db_languages = [];
    /** @var array<int|string, array<string, mixed>> */
    public array $server_languages = [];

    /**
     * Initialize $fs_languages and $db_languages
    */
    public function __construct(?string $target_charset = null)
    {
        $this->get_fs_languages($target_charset);
    }

    /**
     * Perform requested actions
     * @param string $action
     * @return list<('CANNOT ACTIVATE - LANGUAGE IS ALREADY ACTIVATED' | 'CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED' | 'CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE' | 'CANNOT DELETE - LANGUAGE DOES NOT EXIST' | 'CANNOT DELETE - LANGUAGE IS ACTIVATED')>
     */
    public function perform_action($action, string $language_id): array
    {
        if (!Config::enableExtensionsInstall() and 'delete' == $action) {
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

                $fsLangVersion = $this->fs_languages[$language_id]['version'] ?? null;
                $langVersion = is_scalar($fsLangVersion) ? (string) $fsLangVersion : '';
                $fsLangName = $this->fs_languages[$language_id]['name'] ?? null;
                $langName = is_scalar($fsLangName) ? (string) $fsLangName : '';
                $query = '
INSERT INTO '.LANGUAGES_TABLE.'
  (id, version, name)
  VALUES(\''.$language_id.'\',
         \''.$langVersion.'\',
         \''.$langName.'\')
;';
                pwg_query($query);
                break;

            case 'deactivate':
                if (!isset($crt_db_language)) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED';
                    break;
                }

                if ($language_id == get_default_language()) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE';
                    break;
                }

                $query = '
DELETE
  FROM '.LANGUAGES_TABLE.'
  WHERE id= \''.$language_id.'\'
;';
                pwg_query($query);
                break;

            case 'delete':
                if (!empty($crt_db_language)) {
                    $errors[] = 'CANNOT DELETE - LANGUAGE IS ACTIVATED';
                    break;
                }
                if (!isset($this->fs_languages[$language_id])) {
                    $errors[] = 'CANNOT DELETE - LANGUAGE DOES NOT EXIST';
                    break;
                }

                // Set default language to user who are using this language
                $query = '
UPDATE '.USER_INFOS_TABLE.'
  SET language = \''.get_default_language().'\'
  WHERE language = \''.$language_id.'\'
;';
                pwg_query($query);

                deltree(PHPWG_ROOT_PATH.'language/'.$language_id, PHPWG_ROOT_PATH.'language/trash');
                break;

            case 'set_default':
                $query = '
UPDATE '.USER_INFOS_TABLE.'
  SET language = \''.$language_id.'\'
  WHERE user_id IN ('.Config::defaultUserId().', '.Config::guestId().')
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
        $target_charset = strtolower((string) $target_charset);

        $dir = opendir(PHPWG_ROOT_PATH.'language');
        if ($dir === false) {
            return;
        }
        while ($file = readdir($dir)) {
            if ($file != '.' and $file != '..') {
                $path = PHPWG_ROOT_PATH.'language/'.$file;
                if (is_dir($path) and !is_link($path)
                    and preg_match('/^[a-zA-Z0-9-_]+$/', $file)
                    and file_exists($path.'/common.lang.php')
                ) {
                    $language = [
                        'name' => $file,
                        'code' => $file,
                        'version' => '0',
                        'uri' => '',
                        'author' => '',
                      ];
                    $plg_data = implode('', file($path.'/common.lang.php') ?: []);

                    if (preg_match('|Language Name:\\s*(.+)|', $plg_data, $val)) {
                        $language['name'] = trim($val[1]);
                        $language['name'] = convert_charset($language['name'], 'utf-8', $target_charset);
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
                    if (!empty($language['uri']) and strpos($language['uri'], 'extension_view.php?eid=')) {
                        list(, $extension) = explode('extension_view.php?eid=', $language['uri']);
                        if (is_numeric($extension)) {
                            $language['extension'] = $extension;
                        }
                    }

                    // IMPORTANT SECURITY !
                    $language = array_map(htmlspecialchars(...), $language);
                    $this->fs_languages[$file] = $language;
                }
            }
        }
        closedir($dir);
        uasort($this->fs_languages, name_compare(...));
    }

    public function get_db_languages(): void
    {
        $query = '
  SELECT id, name
    FROM '.LANGUAGES_TABLE.'
    ORDER BY name ASC
  ;';
        $result = pwg_query($query);

        while ($row = pwg_db_fetch_assoc($result)) {
            $id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
            $name = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';
            if ($id !== '') {
                $this->db_languages[$id] = ['name' => $name];
            }
        }
    }

    /**
     * Retrieve PEM server datas to $server_languages
     */
    public function get_server_languages(bool $new = false): bool
    {
        $get_data = [
          'category_id' => Config::pemLanguagesCategory(),
          'format' => 'php',
        ];

        // Retrieve PEM versions
        $version = PHPWG_VERSION;
        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php';
        if (fetchRemote($url, $result, $get_data) and $pem_versions = safe_unserialize($result)) {
            if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $pem_ver0 = $pem_versions[0] ?? null;
                $pem_ver0_name = is_array($pem_ver0) && isset($pem_ver0['name']) ? $pem_ver0['name'] : null;
                $version = is_scalar($pem_ver0_name) ? (string) $pem_ver0_name : $version;
            }
            $branch = get_branch_from_version($version);
            foreach ($pem_versions as $pem_version) {
                if (!is_array($pem_version) || !isset($pem_version['name'], $pem_version['id'])) {
                    continue;
                }
                $pemVerName = is_scalar($pem_version['name']) ? (string) $pem_version['name'] : '';
                $pemVerId = is_scalar($pem_version['id']) ? (string) $pem_version['id'] : '';
                if (str_starts_with($pemVerName, $branch)) {
                    $versions_to_check[] = $pemVerId;
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
                $languages_to_check[] = is_scalar($fs_language['extension']) ? (string) $fs_language['extension'] : '';
            }
        }

        // Retrieve PEM languages infos
        $url = PEM_URL . '/api/get_revision_list.php';
        $get_data = array_merge(
            $get_data,
            [
      'last_revision_only' => 'true',
      'version' => implode(',', $versions_to_check),
      'lang' => CurrentUser::get()->language,
      'get_nb_downloads' => 'true',
      ]
        );
        if (!empty($languages_to_check)) {
            if ($new) {
                $get_data['extension_exclude'] = implode(',', $languages_to_check);
            } else {
                $get_data['extension_include'] = implode(',', $languages_to_check);
            }
        }

        if (fetchRemote($url, $result, $get_data)) {
            $pem_languages = safe_unserialize($result);
            if ($pem_languages === []) {
                return false;
            }
            foreach ($pem_languages as $language) {
                if (!is_array($language) || !isset($language['extension_name'], $language['extension_id'])) {
                    continue;
                }
                $langExtName = is_scalar($language['extension_name']) ? (string) $language['extension_name'] : '';
                $langExtId = $language['extension_id'];
                if (preg_match('/^.*? \[[A-Z]{2}\]$/', $langExtName) && (is_string($langExtId) || is_int($langExtId))) {
                    $this->server_languages[$langExtId] = $language;
                }
            }
            uasort($this->server_languages, fn (mixed $a, mixed $b): int => $this->extension_name_compare($a, $b));
            return true;
        }
        return false;
    }

    /**
     * Extract language files from archive
     *
     */
    public function extract_language_files(string $action, string $revision, string $dest = ''): string
    {
        $logger = LoggerRegistry::current();

        if ($archive = tempnam(PHPWG_ROOT_PATH.'language', 'zip')) {
            $url = PEM_URL . '/download.php';
            $get_data = [
              'rid' => $revision,
              'origin' => 'piwigo_'.$action,
            ];

            $handle = Filesystem::tryFopen($archive, 'wb');
            $fh = $handle;
            if (is_resource($fh) && fetchRemote($url, $handle, $get_data)) {
                fclose($fh);
                $zip = new \PclZip($archive);
                if ($list = $zip->listContent()) {
                    $main_filepath = null;
                    $status = 'ok';
                    foreach ($list as $file) {
                        // we search common.lang.php in archive
                        if (basename((string) $file['filename']) == 'common.lang.php'
                          and ($main_filepath === null
                          or strlen((string) $file['filename']) < strlen($main_filepath))) {
                            $main_filepath = $file['filename'];
                        }
                    }

                    $logger->debug(__FUNCTION__.', $main_filepath = '.$main_filepath);

                    if (isset($main_filepath)) {
                        $root = basename(dirname($main_filepath)); // common.lang.php path in archive
                        if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $root)) {
                            if ($action == 'install') {
                                $dest = $root;
                            }
                            $extract_path = PHPWG_ROOT_PATH.'language/'.$dest;

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
                                if ($status == 'ok') {
                                    $this->get_fs_languages();
                                    if ($action == 'install') {
                                        $this->perform_action('activate', $dest);
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
                                            deltree($path, PHPWG_ROOT_PATH.'language/trash');
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
            Filesystem::tryUnlink($archive);
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
    public function extension_name_compare(array $a, array $b): int
    {
        $na = is_scalar($a['extension_name'] ?? null) ? (string) $a['extension_name'] : '';
        $nb = is_scalar($b['extension_name'] ?? null) ? (string) $b['extension_name'] : '';
        return strcmp(strtolower($na), strtolower($nb));
    }
}
