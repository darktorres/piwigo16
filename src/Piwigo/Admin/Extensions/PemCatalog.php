<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Admin\Extensions\Projection\ExtractionResult;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\VersionHelper;
use Piwigo\Http\HttpClientService;

/**
 * Extension-catalog communication, generalized across plugins/themes: a
 * self-hosted replacement for the real PEM (piwigo extension market)
 * protocol, not a client of it (P27.9). Each sibling repo
 * (../piwigo16-plugins/../piwigo16-themes) already serves its own
 * manifest.json -- a plain static JSON file, no server-side PHP -- and
 * already-present .zip archives, directly over HTTP (RequestBootstrap::
 * pemUrl($type)). This class fetches that manifest and does every bit of
 * compatibility filtering/version-matching itself, in plain PHP, rather
 * than mimicking the real upstream protocol's serialize()-encoded,
 * multi-endpoint wire format (this fork never talks to the real
 * piwigo.org again -- v17 is a clean break from the external catalog,
 * see project_version_17_breaks_extensions).
 *
 * Archive download + extraction (extract_plugin_files()/extract_theme_files()
 * in the legacy classes) lives here too, since it's fundamentally the same
 * "resolve the archive, then handle what comes back" concern, just
 * followed by a local ZipExtractor call.
 *
 * `array<string, mixed>` rows throughout this class are genuinely arbitrary
 * by design -- they're a `json_decode(..., true)`d manifest.json entry
 * (same residual category as ConfigService's own json_decode()/
 * unserialize() params, see the mixed-elimination plan). The compare*()
 * methods below read only the 1-2 keys they need from that row,
 * defensively (`?? null` + `is_scalar()`), the same "cross-domain
 * generic-row-reader" pattern used for comparators elsewhere in the
 * codebase.
 */
final readonly class PemCatalog
{
    public function __construct(
        private ZipExtractor $zipExtractor,
        private CurrentLogger $currentLogger,
        private Paths $paths,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * P27.9: no longer a real HTTP round trip. The real PEM protocol asked
     * the server which of its own opaque "PEM version" ids matches the
     * caller's branch, since extension compatibility was checked against
     * that id. The local manifest.json mirror has no such catalog --
     * only each extension's own `piwigo_compat` array, checked directly
     * against $version in getServerExtensions()/getIncompatibleExtensions()
     * via isCompatible() -- so this collapses to a 1-element list carrying
     * $version itself. $type/$betaTest are kept as real parameters purely
     * so the existing call sites' signatures don't need touching -- there
     * is no "next version" concept in a flat local manifest for $betaTest
     * to fall back to.
     *
     * @return list<string>
     */
    public function getVersionsToCheck(ExtensionType $type, bool $betaTest = false, string $version = AppInfo::VERSION): array
    {
        return [$version];
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

        $manifestExtensions = $this->fetchManifest($type);
        if ($manifestExtensions === null) {
            return null;
        }

        $byExtensionId = [];
        foreach ($manifestExtensions as $extension) {
            if (! is_array($extension) || ! isset($extension['extension_id'])) {
                continue;
            }
            /** @var array<string, mixed> $extension */
            $extensionId = $extension['extension_id'];
            if (! is_string($extensionId) && ! is_int($extensionId)) {
                continue;
            }
            if (! $this->isCompatible($extension, $versionsToCheck)) {
                continue;
            }
            if ($fsExtensionIds !== []) {
                $onDisk = in_array((string) $extensionId, $fsExtensionIds, true);
                if ($new ? $onDisk : ! $onDisk) {
                    continue;
                }
            }
            $byExtensionId[$extensionId] = $this->normalizeExtensionRecord($extension);
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

        $manifestExtensions = $this->fetchManifest($type);
        if ($manifestExtensions === null) {
            return false;
        }

        $serverExtensions = [];
        foreach ($manifestExtensions as $extension) {
            if (! is_array($extension) || ! isset($extension['extension_id'])) {
                continue;
            }
            /** @var array<string, mixed> $extension */
            $extensionId = $extension['extension_id'];
            if (! is_string($extensionId) && ! is_int($extensionId)) {
                continue;
            }
            if (! in_array((string) $extensionId, $extensionIds, true)) {
                continue;
            }
            if (! $this->isCompatible($extension, $versionsToCheck)) {
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
     * $scanDirectory, locating the extension root by searching for any of
     * $type->markerFilenames() inside the archive (mirrors extract_plugin_files()/
     * extract_theme_files()/extract_language_files()'s identical shape).
     */
    public function extractArchive(ExtensionType $type, string $action, string $revision, string $dest): ExtractionResult
    {
        $logger = $this->currentLogger->get();

        $scanDirectory = $type->scanDirectory($this->paths, $this->currentConfig);
        $archive = tempnam($scanDirectory, 'zip');
        if ($archive === false) {
            return new ExtractionResult('temp_path_error', null);
        }

        $status = 'dl_archive_error';
        $extensionId = null;

        // P27.9: no download.php?rid= indirection -- resolve the revision
        // to its manifest entry's own real filename and fetch the
        // already-present sibling-repo .zip directly.
        $archiveFilename = $this->resolveRevisionFilename($type, $revision);
        $handle = $archiveFilename !== null ? @fopen($archive, 'wb') : false;
        if ($archiveFilename !== null && $handle !== false && HttpClientService::fetchToFile($handle, RequestBootstrap::pemUrl($type) . '/' . $archiveFilename, $this->currentConfig)) {
            fclose($handle);

            $status = 'archive_error';
            $list = $this->zipExtractor->listFilenames($archive);
            if ($list !== null) {
                $markerFilenames = $type->markerFilenames();
                $mainFilepath = null;
                foreach ($list as $filename) {
                    if (in_array(basename($filename), $markerFilenames, true)
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

                        $result = $this->zipExtractor->extract($archive, $extractPath, $root, $this->currentConfig);
                        if ($result !== null) {
                            $status = 'ok';
                            foreach ($result as $file) {
                                if ($file->storedFilename === $mainFilepath) {
                                    $status = $file->status;
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

        return new ExtractionResult($status, $extensionId);
    }

    /**
     * Fetches and decodes this type's sibling-repo manifest.json (P27.9's
     * local extension-catalog mirror -- a plain static file, not a
     * serialize()-encoded PEM endpoint), returning its `extensions` map,
     * or null on a fetch/decode failure -- the same "can't connect to
     * server" failure contract every real caller already branches on.
     *
     * @return array<int|string, mixed>|null
     */
    private function fetchManifest(ExtensionType $type): ?array
    {
        $url = RequestBootstrap::pemUrl($type) . '/manifest.json';
        $result = HttpClientService::fetch($url, $this->currentConfig);
        if (! is_string($result)) {
            return null;
        }

        $decoded = json_decode($result, true);
        if (! is_array($decoded)) {
            return null;
        }

        $extensions = $decoded['extensions'] ?? null;

        return is_array($extensions) ? $extensions : null;
    }

    /**
     * True when any of $extension's own `piwigo_compat` entries branch-
     * matches any of $versionsToCheck -- the local-manifest equivalent of
     * the real PEM server's own server-side `version` GET-param filter.
     *
     * @param array<string, mixed> $extension
     * @param list<string> $versionsToCheck
     */
    private function isCompatible(array $extension, array $versionsToCheck): bool
    {
        $piwigoCompat = $extension['piwigo_compat'] ?? null;
        if (! is_array($piwigoCompat)) {
            return false;
        }

        foreach ($piwigoCompat as $compatVersion) {
            if (! is_string($compatVersion)) {
                continue;
            }
            foreach ($versionsToCheck as $version) {
                if (VersionHelper::getBranchFromVersion($compatVersion) === VersionHelper::getBranchFromVersion($version)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Backfills the fields the real PEM server used to supply that a
     * scraped local manifest doesn't (extension_nb_downloads/rating_score/
     * nb_ratings/tags -- PluginsNewPageRenderer/ThemesNewPageRenderer read
     * these as bare, unguarded array keys), and aliases `piwigo_compat` to
     * `compatible_with_versions`, the one field name those renderers still
     * read under that name.
     *
     * @param array<string, mixed> $extension
     * @return array<string, mixed>
     */
    private function normalizeExtensionRecord(array $extension): array
    {
        $extension['extension_nb_downloads'] ??= 0;
        $extension['rating_score'] ??= null;
        $extension['nb_ratings'] ??= 0;
        $extension['tags'] ??= [];
        $extension['compatible_with_versions'] = $extension['piwigo_compat'] ?? [];

        return $extension;
    }

    /**
     * Resolves a revision_id to its manifest entry's own real `filename`
     * -- P27.9's local mirror serves the already-present sibling-repo
     * .zip directly, no download.php?rid= indirection.
     */
    private function resolveRevisionFilename(ExtensionType $type, string $revision): ?string
    {
        $manifestExtensions = $this->fetchManifest($type);
        if ($manifestExtensions === null) {
            return null;
        }

        foreach ($manifestExtensions as $extension) {
            if (! is_array($extension)) {
                continue;
            }
            $revisionId = $extension['revision_id'] ?? null;
            if (! is_scalar($revisionId) || (string) $revisionId !== $revision) {
                continue;
            }
            $filename = $extension['filename'] ?? null;

            return is_string($filename) ? $filename : null;
        }

        return null;
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

        $trashPath = $type->scanDirectory($this->paths, $this->currentConfig) . 'trash';

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
     * the two only share a name): reads a local
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
        $file = $this->paths->root . 'install/obsolete_extensions.list';
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
