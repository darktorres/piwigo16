<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionAction;
use Piwigo\Admin\Extensions\UpgradeStatus;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ZipExtractor;
use Piwigo\Html\HtmlService;
use Piwigo\Language\LanguageRepository;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

final class Languages
{
    /** @var array<string, array<string,mixed>> */
    public array $fs_languages = [];
    /** @var array<string, array<string,mixed>> */
    public array $db_languages = [];
    /** @var array<int|string, array<mixed>> */
    public array $server_languages = [];

    /**
     * Initialize $fs_languages and $db_languages. Callers that want a specific
     * target charset call getFsLanguages($charset) afterward.
     */
    public function __construct(
        private readonly AdminService $adminService,
        private readonly HtmlService $htmlService,
        private readonly LanguageRepository $languageRepository,
        private readonly UserService $userService,
        private readonly Paths $paths,
        private readonly PemUrlResolver $pemUrlResolver,
    ) {
        $this->getFsLanguages();
    }

    /**
     * Perform requested actions
     * @return list<('CANNOT ACTIVATE - LANGUAGE IS ALREADY ACTIVATED' | 'CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED' | 'CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE' | 'CANNOT DELETE - LANGUAGE DOES NOT EXIST' | 'CANNOT DELETE - LANGUAGE IS ACTIVATED')>
     */
    public function performAction(ExtensionAction $action, string $language_id): array
    {
        if (!Config::enableExtensionsInstall() and ExtensionAction::Delete === $action) {
            die('Piwigo extensions install/update/delete system is disabled');
        }

        if (isset($this->db_languages[$language_id])) {
            $crt_db_language = $this->db_languages[$language_id];
        }

        $errors = [];

        switch ($action) {
            case ExtensionAction::Activate:
                if (isset($crt_db_language)) {
                    $errors[] = 'CANNOT ACTIVATE - LANGUAGE IS ALREADY ACTIVATED';
                    break;
                }

                $fsLangVersion = $this->fs_languages[$language_id]['version'] ?? null;
                $langVersion = is_scalar($fsLangVersion) ? (string) $fsLangVersion : '';
                $fsLangName = $this->fs_languages[$language_id]['name'] ?? null;
                $langName = is_scalar($fsLangName) ? (string) $fsLangName : '';
                $this->languageRepository->activate($language_id, $langVersion, $langName);
                break;

            case ExtensionAction::Deactivate:
                if (!isset($crt_db_language)) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED';
                    break;
                }

                if ($language_id == $this->userService->getDefaultLanguage()) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE';
                    break;
                }

                $this->languageRepository->deactivate($language_id);
                break;

            case ExtensionAction::Delete:
                if (!empty($crt_db_language)) {
                    $errors[] = 'CANNOT DELETE - LANGUAGE IS ACTIVATED';
                    break;
                }
                if (!isset($this->fs_languages[$language_id])) {
                    $errors[] = 'CANNOT DELETE - LANGUAGE DOES NOT EXIST';
                    break;
                }

                // Set default language to users who are using this language
                $this->languageRepository->reassignUsers($language_id, $this->userService->getDefaultLanguage());

                $this->adminService->deltree($this->paths->root . 'language/' . $language_id, $this->paths->root . 'language/trash');
                break;

            case ExtensionAction::SetDefault:
                $this->languageRepository->setDefaultForSystemUsers(
                    $language_id,
                    [Config::defaultUserId(), Config::guestId()]
                );
                break;

            case ExtensionAction::Install:
            case ExtensionAction::Update:
            case ExtensionAction::Uninstall:
            case ExtensionAction::Restore:
                // Languages do not support these actions directly — silently no-op.
                break;
        }
        return $errors;
    }

    /**
    *  Get languages defined in the language directory
    */
    public function getFsLanguages(string $target_charset = ''): void
    {
        $charset = strtolower(
            $target_charset !== ''
                ? $target_charset
                : StringUtil::getPwgCharset()
        );

        $dir = opendir($this->paths->root . 'language');
        if ($dir === false) {
            return;
        }
        while ($file = readdir($dir)) {
            if ($file != '.' and $file != '..') {
                $path = $this->paths->root . 'language/' . $file;
                if (is_dir($path) and !is_link($path)
                    and preg_match('/^[a-zA-Z0-9-_]+$/', $file)
                    and file_exists($path.'/common.po')
                ) {
                    $language = [
                        'name' => $file,
                        'code' => $file,
                        'version' => '0',
                        'uri' => '',
                        'author' => '',
                      ];
                    $plg_data_lines = file($path.'/common.po');
                    $plg_data = implode('', $plg_data_lines !== false ? $plg_data_lines : []);

                    if (preg_match('|X-Piwigo-Language-Name:\\s*(.+?)\\\\n|', $plg_data, $val)) {
                        $language['name'] = trim($val[1]);
                        $language['name'] = StringUtil::convertCharset($language['name'], 'utf-8', $charset);
                    }

                    // IMPORTANT SECURITY !
                    $language = array_map(htmlspecialchars(...), $language);
                    $this->fs_languages[$file] = $language;
                }
            }
        }
        closedir($dir);
        uasort($this->fs_languages, $this->htmlService->nameCompare(...));
    }

    public function getDbLanguages(): void
    {
        foreach ($this->languageRepository->findAllOrdered() as $row) {
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
    public function getServerLanguages(bool $new = false): bool
    {
        $get_data = [
          'category_id' => Config::pemLanguagesCategory(),
        ];

        // Retrieve PEM versions
        $version = AppInfo::VERSION;
        $versions_to_check = [];
        $url = $this->pemUrlResolver->url() . '/api/get_version_list.php';
        $result = '';
        if ($this->adminService->fetchRemote($url, $result, $get_data) and is_string($result) and ($pem_versions = json_decode($result, associative: true)) !== null and is_array($pem_versions)) {
            if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $pem_ver0 = $pem_versions[0] ?? null;
                $pem_ver0_name = is_array($pem_ver0) && isset($pem_ver0['name']) ? $pem_ver0['name'] : null;
                $version = is_scalar($pem_ver0_name) ? (string) $pem_ver0_name : $version;
            }
            $branch = AppInfo::branchFromVersion($version);
            foreach ($pem_versions as $pem_version) {
                if (!is_array($pem_version) || !isset($pem_version['name'], $pem_version['id'])) {
                    continue;
                }
                $pemVerName = is_string($pem_version['name']) ? $pem_version['name'] : '';
                $pemVerId = is_string($pem_version['id']) ? $pem_version['id'] : '';
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
                $languages_to_check[] = is_string($fs_language['extension']) ? $fs_language['extension'] : '';
            }
        }

        // Retrieve PEM languages infos
        $url = $this->pemUrlResolver->url() . '/api/get_revision_list.php';
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

        if ($this->adminService->fetchRemote($url, $result, $get_data) && is_string($result)) {
            $decoded       = json_decode($result, associative: true);
            $pem_languages = is_array($decoded) ? $decoded : [];
            if ($pem_languages === []) {
                return false;
            }
            foreach ($pem_languages as $language) {
                if (!is_array($language) || !isset($language['extension_name'], $language['extension_id'])) {
                    continue;
                }
                $langExtName = is_string($language['extension_name']) ? $language['extension_name'] : '';
                $langExtId = $language['extension_id'];
                if (preg_match('/^.*? \[[A-Z]{2}\]$/', $langExtName) && (is_string($langExtId) || is_int($langExtId))) {
                    $this->server_languages[$langExtId] = $language;
                }
            }
            uasort($this->server_languages, fn (mixed $a, mixed $b): int => $this->extensionNameCompare($a, $b));
            return true;
        }
        return false;
    }

    /**
     * Extract language files from archive
     *
     */
    public function extractLanguageFiles(string $action, string $revision, string $dest = ''): UpgradeStatus
    {
        $logger = LoggerRegistry::current();

        if (($archive = tempnam($this->paths->root . 'language', 'zip')) !== false) {
            $url = $this->pemUrlResolver->url() . '/download.php';
            $get_data = [
              'rid' => $revision,
              'origin' => 'piwigo_'.$action,
            ];

            $handle = Filesystem::tryFopen($archive, 'wb');
            $fh = $handle;
            /** @var resource|string $handle */
            if (is_resource($fh) && $this->adminService->fetchRemote($url, $handle, $get_data)) {
                fclose($fh);
                $names = ZipExtractor::listNames($archive);
                if ($names !== []) {
                    $main_filepath = null;
                    $status = UpgradeStatus::Ok;
                    foreach ($names as $filename) {
                        // we search common.lang.php in archive
                        if (basename($filename) == 'common.lang.php'
                          and ($main_filepath === null
                          or strlen($filename) < strlen($main_filepath))) {
                            $main_filepath = $filename;
                        }
                    }

                    $logger->debug(__FUNCTION__.', $main_filepath = '.(string) $main_filepath);

                    if (isset($main_filepath)) {
                        $root = basename(dirname($main_filepath)); // common.lang.php path in archive
                        if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $root)) {
                            if ($action == 'install') {
                                $dest = $root;
                            }
                            $extract_path = $this->paths->root . 'language/' . $dest;

                            $logger->debug(__FUNCTION__.', $extract_path = '.$extract_path);

                            $result = ZipExtractor::extract($archive, $extract_path, $root === '.' ? '' : $root);
                            if ($result !== []) {
                                foreach ($result as $file) {
                                    if ($file['stored_filename'] === $main_filepath) {
                                        if ($file['status'] !== ZipExtractor::STATUS_OK) {
                                            $status = UpgradeStatus::ExtractError;
                                        }
                                        break;
                                    }
                                }
                                if ($status === UpgradeStatus::Ok) {
                                    $this->getFsLanguages();
                                    if ($action == 'install') {
                                        $this->performAction(ExtensionAction::Activate, $dest);
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
                                            $this->adminService->deltree($path, $this->paths->root . 'language/trash');
                                        }
                                    }
                                }
                            } else {
                                $status = UpgradeStatus::ExtractError;
                            }
                        } else {
                            $status = UpgradeStatus::ArchiveError;
                        }
                    } else {
                        $status = UpgradeStatus::ArchiveError;
                    }
                } else {
                    $status = UpgradeStatus::ArchiveError;
                }
            } else {
                $status = UpgradeStatus::DlArchiveError;
            }
        } else {
            $status = UpgradeStatus::TempPathError;
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
    public function extensionNameCompare(array $a, array $b): int
    {
        $na = is_scalar($a['extension_name'] ?? null) ? (string) $a['extension_name'] : '';
        $nb = is_scalar($b['extension_name'] ?? null) ? (string) $b['extension_name'] : '';
        return strcmp(strtolower($na), strtolower($nb));
    }
}
