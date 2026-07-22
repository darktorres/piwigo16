<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Logger;
use Piwigo\Http\HttpClientService;

/**
 * PEM (piwigo extension market) remote-server communication: shared by the
 * plugins/themes/languages god-classes' get_versions_to_check()/
 * get_server_plugins()/get_server_themes()/get_server_languages()/
 * get_incompatible_plugins()/extension_*_compare() methods, which were
 * ~95% identical across the 3 legacy classes (confirmed by direct read --
 * only the PEM category conf key and a couple of URL-path nouns differ,
 * all of which ExtensionType now exposes).
 *
 * Archive download + extraction (extract_plugin_files()/extract_theme_files()/
 * extract_language_files() in the legacy classes) lives here too, since
 * it's fundamentally the same "talk to PEM, then handle what comes back"
 * concern, just followed by a local ZipExtractor call.
 */
final readonly class PemCatalog
{
    public function __construct(
        private ZipExtractor $zipExtractor,
    ) {}

    /**
     * Beta test: return the last PEM version if the current version isn't
     * known, otherwise the current version's PEM id (plus the next one
     * when $betaTest).
     *
     * @return list<string>
     */
    public function getVersionsToCheck(ExtensionType $type, bool $betaTest = false, string $version = AppInfo::VERSION): array
    {

        $pemBaseUrl = \Piwigo\Bootstrap\RequestBootstrap::pemUrl();
        $versionsToCheck = [];
        $url = $pemBaseUrl . '/api/get_version_list.php';
        $getData = [
            'category_id' => \Piwigo\Config\Config::all()[$type->configCategoryKey()],
            'format' => 'php',
        ];

        if (is_string($result = HttpClientService::fetch($url, $getData)) and (bool) ($pemVersions = @unserialize($result))) {
            if (! is_array($pemVersions)) {
                return $versionsToCheck;
            }

            $i = 0;
            while ($i < count($pemVersions) && count($versionsToCheck) === 0) {
                $pemVersion = $pemVersions[$i] ?? null;
                if (is_array($pemVersion)) {
                    $pemVersionName = $pemVersion['name'] ?? null;
                    if (is_string($pemVersionName) and \Piwigo\Core\VersionHelper::getBranchFromVersion($pemVersionName) === \Piwigo\Core\VersionHelper::getBranchFromVersion($version)) {
                        $pemVersionId = $pemVersion['id'] ?? null;
                        if (is_scalar($pemVersionId)) {
                            $versionsToCheck[] = (string) $pemVersionId;
                        }
                    }
                }
                $i++;
            }

            if ($betaTest) {
                if (count($versionsToCheck) === 0) {
                    $firstPemVersion = $pemVersions[0] ?? null;
                    if (is_array($firstPemVersion)) {
                        $firstPemVersionId = $firstPemVersion['id'] ?? null;
                        if (is_scalar($firstPemVersionId)) {
                            $versionsToCheck[] = (string) $firstPemVersionId;
                        }
                    }
                } else {
                    $hasFoundPreviousVersion = false;
                    while ($i < count($pemVersions) && ! $hasFoundPreviousVersion) {
                        $pemVersion = $pemVersions[$i] ?? null;
                        if (is_array($pemVersion)) {
                            $pemVersionId = $pemVersion['id'] ?? null;
                            if (is_scalar($pemVersionId) and (string) $pemVersionId !== $versionsToCheck[0]) {
                                $versionsToCheck[] = (string) $pemVersionId;
                                $hasFoundPreviousVersion = true;
                            }
                        }
                        $i++;
                    }
                }
            }
        }

        return $versionsToCheck;
    }

    /**
     * Fetches the PEM revision list for $type, keyed by extension_id.
     * $fsExtensionIds is the caller's already-scanned filesystem extension
     * ids (ExtensionScanner's 'extension' field per entry) -- kept as a
     * plain input here rather than this class depending on
     * ExtensionScanner directly.
     *
     * @param  list<string> $fsExtensionIds
     * @return array<int|string, array<string, mixed>>|null null on failure
     */
    public function getServerExtensions(ExtensionType $type, array $fsExtensionIds, bool $new = false, bool $betaTest = false, string $version = AppInfo::VERSION): ?array
    {

        $versionsToCheck = $this->getVersionsToCheck($type, $betaTest, $version);
        if ($versionsToCheck === []) {
            return null;
        }

        $pemBaseUrl = \Piwigo\Bootstrap\RequestBootstrap::pemUrl();
        $url = $pemBaseUrl . '/api/get_revision_list-next.php';
        $userLanguage = \Piwigo\Users\CurrentUser::get()->language;
        $getData = [
            'category_id' => \Piwigo\Config\Config::all()[$type->configCategoryKey()],
            'format' => 'php',
            'last_revision_only' => 'true',
            'version' => implode(',', $versionsToCheck),
            'lang' => substr($userLanguage, 0, 2),
            'get_nb_downloads' => 'true',
        ];

        if ($fsExtensionIds !== []) {
            if ($new) {
                $getData['extension_exclude'] = implode(',', $fsExtensionIds);
            } else {
                $getData['extension_include'] = implode(',', $fsExtensionIds);
            }
        }

        if (! is_string($result = HttpClientService::fetch($url, $getData))) {
            return null;
        }

        $pemExtensions = @unserialize($result);
        if (! is_array($pemExtensions)) {
            return null;
        }

        $byExtensionId = [];
        foreach ($pemExtensions as $extension) {
            if (! is_array($extension) || ! isset($extension['extension_id'])) {
                continue;
            }
            /** @var array<string, mixed> $extension */
            $extensionId = $extension['extension_id'];
            if (! is_string($extensionId) && ! is_int($extensionId)) {
                continue;
            }
            $byExtensionId[$extensionId] = $extension;
        }

        return $byExtensionId;
    }

    /**
     * Cross-references $fsExtensions (id => data, each possibly carrying
     * an 'extension' PEM id) against the current PEM revision list to find
     * fs extensions whose installed version isn't a known-compatible PEM
     * revision. Cached in $_SESSION for 5 minutes (mirrors
     * plugins.class.php::get_incompatible_plugins(), the only legacy
     * caller of this exact check -- generalized here since the underlying
     * logic never referenced anything plugin-specific).
     *
     * @param array<string, array<string, mixed>> $fsExtensions
     * @param list<string> $defaultIds
     * @return array<string, mixed>|false
     */
    public function getIncompatibleExtensions(
        ExtensionType $type,
        array $fsExtensions,
        array $defaultIds,
        bool $actualize = false,
    ): array|false {
        $sessionKey = 'incompatible_' . $type->value . 's';

        $cached = $_SESSION[$sessionKey] ?? null;
        if (is_array($cached) and ! $actualize) {
            $expire = $cached['~~expire~~'] ?? null;
            if (is_int($expire) and $expire > time()) {
                /** @var array<string, mixed> $cached */
                return $cached;
            }
        }

        $incompatible = [
            '~~expire~~' => time() + 300,
        ];
        $_SESSION[$sessionKey] = $incompatible;

        $versionsToCheck = $this->getVersionsToCheck($type);
        if ($versionsToCheck === []) {
            return false;
        }

        $extensionIds = [];
        foreach ($fsExtensions as $fsExtension) {
            $extension = $fsExtension['extension'] ?? null;
            if (is_scalar($extension)) {
                $extensionIds[] = (string) $extension;
            }
        }

        $pemBaseUrl = \Piwigo\Bootstrap\RequestBootstrap::pemUrl();
        $url = $pemBaseUrl . '/api/get_revision_list.php';
        $getData = [
            'category_id' => \Piwigo\Config\Config::all()[$type->configCategoryKey()],
            'format' => 'php',
            'version' => implode(',', $versionsToCheck),
            'extension_include' => implode(',', $extensionIds),
        ];

        if (! is_string($result = HttpClientService::fetch($url, $getData))) {
            return false;
        }

        $pemExtensions = @unserialize($result);
        if (! is_array($pemExtensions)) {
            return false;
        }

        $serverExtensions = [];
        foreach ($pemExtensions as $extension) {
            if (! is_array($extension) || ! isset($extension['extension_id'])) {
                continue;
            }
            /** @var array<string, mixed> $extension */
            $extensionId = $extension['extension_id'];
            if (! is_string($extensionId) && ! is_int($extensionId)) {
                continue;
            }
            if (! isset($serverExtensions[$extensionId])) {
                $serverExtensions[$extensionId] = [];
            }
            $serverExtensions[$extensionId][] = $extension['revision_name'] ?? null;
        }

        foreach ($fsExtensions as $extensionFsId => $fsExtension) {
            $extension = $fsExtension['extension'] ?? null;
            if (! is_string($extension)) {
                continue;
            }
            $fsVersion = $fsExtension['version'] ?? null;
            if (! in_array($extensionFsId, $defaultIds, true)
                and $fsVersion !== 'auto'
                and (! isset($serverExtensions[$extension]) or ! in_array($fsVersion, $serverExtensions[$extension], true))) {
                $incompatible[$extensionFsId] = $fsVersion;
            }
        }
        $_SESSION[$sessionKey] = $incompatible;

        return $incompatible;
    }

    /**
     * Downloads a PEM archive by revision id and extracts it under
     * $scanDirectory, locating the extension root by searching for
     * $type->markerFilename() inside the archive (mirrors extract_plugin_files()/
     * extract_theme_files()/extract_language_files()'s identical shape).
     *
     * @return array{status: string, id: string|null}
     */
    public function extractArchive(ExtensionType $type, string $action, string $revision, string $dest): array
    {
        $logger = \Piwigo\Core\CurrentLogger::get();

        $scanDirectory = $type->scanDirectory();
        $archive = tempnam($scanDirectory, 'zip');
        if ($archive === false) {
            return [
                'status' => 'temp_path_error',
                'id' => null,
            ];
        }

        $pemBaseUrl = \Piwigo\Bootstrap\RequestBootstrap::pemUrl();
        $url = $pemBaseUrl . '/download.php';
        $getData = [
            'rid' => $revision,
            'origin' => 'piwigo_' . $action,
        ];

        $status = 'dl_archive_error';
        $extensionId = null;

        $handle = @fopen($archive, 'wb');
        if ($handle !== false && HttpClientService::fetchToFile($handle, $url, $getData)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $status = 'archive_error';
            $list = $this->zipExtractor->listFilenames($archive);
            if ($list !== null) {
                $markerFilename = $type->markerFilename();
                $mainFilepath = null;
                foreach ($list as $filename) {
                    if (basename($filename) === $markerFilename
                        and ($mainFilepath === null or strlen($filename) < strlen($mainFilepath))) {
                        $mainFilepath = $filename;
                    }
                }

                if ($mainFilepath !== null) {
                    $logger->debug(__FUNCTION__ . ', $main_filepath = ' . $mainFilepath);

                    $root = $type === ExtensionType::Language
                        ? basename(dirname($mainFilepath))
                        : dirname($mainFilepath);

                    $rootIsValid = $type !== ExtensionType::Language
                        || (bool) preg_match('/^[a-z]{2}_[A-Z]{2}$/', $root);

                    if ($rootIsValid) {
                        if ($action === 'upgrade') {
                            $extensionId = $dest;
                        } elseif ($type === ExtensionType::Language) {
                            $extensionId = $action === 'install' ? $root : $dest;
                        } else {
                            $extensionId = ($root === '.' ? 'extension_' . $dest : basename($root));
                        }
                        $extractPath = $scanDirectory . $extensionId;
                        $logger->debug(__FUNCTION__ . ', $extract_path = ' . $extractPath);

                        $result = $this->zipExtractor->extract($archive, $extractPath, $root);
                        if ($result !== null) {
                            $status = 'ok';
                            foreach ($result as $file) {
                                if ($file['stored_filename'] === $mainFilepath) {
                                    $status = $file['status'];
                                    break;
                                }
                            }
                            $this->deleteObsoleteFiles($type, $extractPath, $logger);
                        } else {
                            $status = 'extract_error';
                        }
                    } else {
                        $status = 'archive_error';
                    }
                }
            }
        }

        @unlink($archive);

        return [
            'status' => $status,
            'id' => $extensionId,
        ];
    }

    private function deleteObsoleteFiles(ExtensionType $type, string $extractPath, Logger $logger): void
    {
        if (! file_exists($extractPath . '/obsolete.list')) {
            return;
        }
        $oldFiles = file($extractPath . '/obsolete.list', FILE_IGNORE_NEW_LINES);
        if ($oldFiles === false) {
            return;
        }
        $oldFiles[] = 'obsolete.list';
        $logger->debug(__FUNCTION__ . ', $old_files = {' . join('},{', $oldFiles) . '}');

        $extractPathRealpath = realpath($extractPath);
        if ($extractPathRealpath === false) {
            return;
        }

        $trashPath = $type->scanDirectory() . 'trash';

        foreach ($oldFiles as $oldFile) {
            $oldFile = trim($oldFile);
            $oldFile = trim($oldFile, '/');

            if ($oldFile === '') {
                continue;
            }

            $path = $extractPath . '/' . $oldFile;
            $realpath = realpath($path);
            if ($realpath === false or ! str_starts_with($realpath, $extractPathRealpath)) {
                continue;
            }

            $logger->debug(__FUNCTION__ . ', to delete = ' . $path);

            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                FilesystemHelper::deltree($path, $trashPath);
            }
        }
    }

    /**
     * plugins.class.php::get_merged_extensions()'s own concept (distinct
     * from, and unrelated to, updates.class.php::get_merged_extensions() --
     * confirmed by direct read, the two only share a name): reads a local
     * "extension id: description" list of extensions merged into Piwigo
     * core, only ever used by the plugins listing page to flag installed
     * plugins that are now redundant. themes.class.php/languages.class.php
     * have no equivalent concept.
     *
     * @return array<int|string, string> keyed by PEM extension id -- a
     *   numeric-string capture group, so PHP coerces the array key to int
     *   (same coercion the legacy method's identical assignment underwent)
     */
    public function getLocallyMergedExtensions(): array
    {
        $file = \Piwigo\Core\CurrentPaths::get()->root . 'install/obsolete_extensions.list';
        $mergedExtensions = [];

        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    if ((bool) preg_match('/^(\d+) ?: ?(.*?)$/', $line, $matches)) {
                        $mergedExtensions[$matches[1]] = $matches[2];
                    }
                }
            }
        }

        return $mergedExtensions;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compareByRevisionDate(array $a, array $b): int
    {
        return ($a['revision_date'] ?? null) < ($b['revision_date'] ?? null) ? 1 : -1;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compareByName(array $a, array $b): int
    {
        $aName = $a['extension_name'] ?? null;
        $bName = $b['extension_name'] ?? null;
        $aName = is_scalar($aName) ? (string) $aName : '';
        $bName = is_scalar($bName) ? (string) $bName : '';

        return strcmp(strtolower($aName), strtolower($bName));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compareByAuthor(array $a, array $b): int
    {
        $aAuthor = $a['author_name'] ?? null;
        $bAuthor = $b['author_name'] ?? null;
        $aAuthor = is_scalar($aAuthor) ? (string) $aAuthor : '';
        $bAuthor = is_scalar($bAuthor) ? (string) $bAuthor : '';
        $r = strcasecmp($aAuthor, $bAuthor);

        return $r === 0 ? self::compareByName($a, $b) : $r;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compareByDownloads(array $a, array $b): int
    {
        return ($a['extension_nb_downloads'] ?? null) < ($b['extension_nb_downloads'] ?? null) ? 1 : -1;
    }
}
