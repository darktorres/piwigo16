<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Extensions\Projection\LanguageScanRow;
use Piwigo\Admin\Extensions\Projection\PluginScanRow;
use Piwigo\Admin\Extensions\Projection\ThemeScanRow;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\VersionHelper;
use Piwigo\Http\HttpClientService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;

/**
 * Cross-type "which of my installed extensions have a newer PEM revision
 * available" concern, replacing updates.class.php's get_server_extensions()/
 * check_extensions()/check_updated_extensions()/check_missing_extensions()/
 * get_merged_extensions(string $version). Built on ExtensionScanner (fs
 * scan) + PemCatalog (PEM communication) rather than reimplementing PEM
 * calls, unlike the legacy updates.class.php, which had its own separate
 * (near-duplicate) fetchRemote() calls instead of delegating to plugins.
 * class.php/themes.class.php/languages.class.php.
 *
 * Note: updates.class.php::get_merged_extensions() (remote merged-extensions
 * URL, gated by a version) is a different concept from plugins.class.php's
 * own get_merged_extensions() (local file, no version gate) -- see
 * PemCatalog::getLocallyMergedExtensions()'s docblock. This class only
 * covers the former.
 */
final readonly class ExtensionUpdateChecker
{
    public function __construct(
        private Lang $lang,
        private ExtensionScanner $scanner,
        private PemCatalog $pemCatalog,
        private UrlServiceInterface $urlService,
        private ExtensionIgnoredUpdateRepository $ignoredUpdateRepo,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
        private ExtensionUpdateCachePool $extensionUpdateCachePool,
    ) {}

    /**
     * @return list<string>
     */
    public function findIgnoredIdsByType(ExtensionType $type): array
    {
        return $this->ignoredUpdateRepo->findIgnoredIdsByType($type);
    }

    public function ignoreUpdate(ExtensionType $type, string $extensionId): void
    {
        $this->ignoredUpdateRepo->ignore($type, $extensionId, Env::now()->format('Y-m-d H:i:s'));
    }

    public function resetIgnoredForType(ExtensionType $type): void
    {
        $this->ignoredUpdateRepo->resetType($type);
    }

    public function resetAllIgnored(): void
    {
        $this->ignoredUpdateRepo->resetAll();
    }

    /**
     * Extension ids (fs directory names) for $type whose installed version
     * is older than the latest PEM revision compatible with $version, or
     * null if the PEM server couldn't be reached.
     *
     * 'fs' is one entry from ExtensionScanner::scan()'s own by-design
     * generic dispatch shape (real per-type precision documented on
     * scanPlugin()/scanTheme()/scanLanguage() themselves); 'server' is one
     * entry from PemCatalog::getServerExtensions()'s own genuinely
     * arbitrary (unserialize()'d remote PEM response) row.
     *
     * @return array<string, array{fs: PluginScanRow|ThemeScanRow|LanguageScanRow, server: array<string, mixed>}>|null
     */
    public function getPendingUpdates(ExtensionType $type, string $version = AppInfo::VERSION): ?array
    {
        $fsExtensions = $this->scanner->scan($type, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);

        $fsExtensionIds = [];
        foreach ($fsExtensions as $fsExtension) {
            if ($fsExtension->extension !== null) {
                $fsExtensionIds[] = $fsExtension->extension;
            }
        }

        $serverExtensions = $this->pemCatalog->getServerExtensions($type, $fsExtensionIds, false, false, $version);
        if ($serverExtensions === null) {
            return null;
        }

        $pending = [];
        foreach ($fsExtensions as $extId => $fsExt) {
            $extensionKey = $fsExt->extension;
            if ($extensionKey === null) {
                continue;
            }
            if (! isset($serverExtensions[$extensionKey])) {
                continue;
            }

            $fsVersion = $fsExt->version;
            if ($fsVersion === 'auto') {
                // In dev mode, do not show update actions.
                continue;
            }

            $serverInfo = $serverExtensions[$extensionKey];
            $revisionNameRaw = $serverInfo['revision_name'] ?? null;
            $revisionName = is_string($revisionNameRaw) ? $revisionNameRaw : '';

            if (! (bool) VersionHelper::safeVersionCompare($fsVersion, $revisionName, '>=')) {
                $pending[$extId] = [
                    'fs' => $fsExt,
                    'server' => $serverInfo,
                ];
            }
        }

        return $pending;
    }

    /**
     * Computes pending updates for every ExtensionType, stores the result
     * in ExtensionUpdateCachePool under the 'extensions_need_update' key
     * (consumed by Controller\Api\Extensions\CheckUpdatesController), and
     * syncs the ignore-list in extension_ignored_updates via $ignoredUpdateRepo
     * -- mirrors updates.class.php::check_extensions()'s own behavior,
     * including its "an ignored id no longer pending silently
     * un-ignores" side effect (see the loop's own comment below).
     */
    public function checkExtensions(): void
    {
        // Built up locally (instead of writing straight into the cache
        // item) because PHPStan cannot keep a precise array shape for a
        // value mutated across loop iterations that also make impure
        // calls (findIgnoredIdsByType()/unignore() below invalidate any
        // narrowing PHPStan could otherwise track) -- the whole array is
        // committed to the cache once, after every ExtensionType's been
        // processed.
        /** @var array<string, array<string, string>> $extensionsNeedUpdate */
        $extensionsNeedUpdate = [];

        foreach (ExtensionType::cases() as $type) {
            $pending = $this->getPendingUpdates($type);
            if ($pending === null) {
                continue;
            }

            $ignoredForType = $this->ignoredUpdateRepo->findIgnoredIdsByType($type);
            $stillPendingIgnoredIds = [];
            $typeNeedUpdate = [];

            foreach ($pending as $extId => $data) {
                if (in_array($extId, $ignoredForType, true)) {
                    $stillPendingIgnoredIds[] = $extId;
                    continue;
                }

                $revisionNameRaw = $data['server']['revision_name'] ?? null;
                $typeNeedUpdate[$extId] = is_string($revisionNameRaw) ? $revisionNameRaw : '';
            }

            // Only set once genuinely non-empty -- matches updates.class.php::
            // check_extensions()'s own behavior, where $_SESSION['extensions_need_update'][$type]
            // is only ever auto-vivified by an actual assignment inside the
            // pending-update branch above, never pre-seeded to an empty array.
            // CheckUpdatesController's own `$extNeedUpdate !== []` check (mirroring
            // the legacy `!empty($_SESSION['extensions_need_update'])`) depends on
            // this: an unconditional per-type assignment here would make the
            // cached structure carry a key for every reachable type regardless of
            // whether anything is actually pending, permanently tripping that
            // check the moment the PEM server is reachable at all.
            if ($typeNeedUpdate !== []) {
                $extensionsNeedUpdate[$type->value] = $typeNeedUpdate;
            }

            // Matches the original blob-based behavior exactly: an ignored
            // id that's no longer pending (the extension caught up, or was
            // removed from disk) silently drops out of the ignore set
            // instead of lingering forever.
            foreach ($ignoredForType as $extId) {
                if (! in_array($extId, $stillPendingIgnoredIds, true)) {
                    $this->ignoredUpdateRepo->unignore($type, $extId);
                }
            }
        }

        $item = $this->extensionUpdateCachePool->getItem('extensions_need_update');
        $item->set($extensionsNeedUpdate);
        $this->extensionUpdateCachePool->save($item);
    }

    /**
     * Re-runs checkExtensions() if any extension already flagged as
     * needing an update in the cache has since caught up on disk --
     * mirrors updates.class.php::check_updated_extensions().
     */
    public function checkUpdatedExtensions(): void
    {
        $item = $this->extensionUpdateCachePool->getItem('extensions_need_update');
        $extensionsNeedUpdateRaw = $item->isHit() ? $item->get() : null;
        $extensionsNeedUpdate = is_array($extensionsNeedUpdateRaw) ? $extensionsNeedUpdateRaw : [];

        foreach (ExtensionType::cases() as $type) {
            $typeUpdatesRaw = $extensionsNeedUpdate[$type->value] ?? null;
            if (! is_array($typeUpdatesRaw) || $typeUpdatesRaw === []) {
                continue;
            }

            $fsExtensions = $this->scanner->scan($type, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);
            foreach ($fsExtensions as $extId => $fsExt) {
                $neededVersion = $typeUpdatesRaw[$extId] ?? null;
                $fsVersion = $fsExt->version;

                if (is_string($neededVersion) and (bool) VersionHelper::safeVersionCompare($fsVersion, $neededVersion, '>=')) {
                    $this->checkExtensions();

                    return;
                }
            }
        }
    }

    /**
     * updates.class.php's own get_merged_extensions($version): fetches a
     * remote "extensions merged into core as of branch X.Y" list, distinct
     * from PemCatalog::getLocallyMergedExtensions() (see this class's own
     * docblock).
     *
     * @return list<string>
     */
    public function getMergedExtensions(string $version): array
    {
        $mergedExtensionUrl = 'https://upstream.example.invalid/merged_extensions.txt';
        $merged = [];

        if (is_string($result = HttpClientService::fetch($mergedExtensionUrl, $this->currentConfig))) {
            $rows = explode("\n", $result);
            foreach ($rows as $row) {
                if ((bool) preg_match('/^(\d+\.\d+): *(.*)$/', $row, $match)) {
                    if (version_compare($version, $match[1], '>=')) {
                        $extensions = explode(',', trim($match[2]));
                        $merged = array_merge($merged, $extensions);
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * Installed extensions with no known-compatible PEM revision for
     * $version, excluding core-bundled defaults (ExtensionType::defaultIds())
     * and extensions merged into core by $version (getMergedExtensions()) --
     * mirrors updates.class.php::check_missing_extensions(), only ever used
     * by the core-upgrade "different branch" step (updates_pwg.php step 3).
     *
     * Each entry is one row from ExtensionScanner::scan()'s own by-design
     * generic dispatch shape -- see getPendingUpdates()'s own docblock.
     *
     * @return array<string, list<PluginScanRow|ThemeScanRow|LanguageScanRow>> keyed by
     *   ExtensionType::value
     */
    public function getMissingExtensions(string $version): array
    {
        $mergedExtensions = $this->getMergedExtensions($version);
        $missing = [];

        foreach (ExtensionType::cases() as $type) {
            $fsExtensions = $this->scanner->scan($type, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);

            $fsExtensionIds = [];
            foreach ($fsExtensions as $fsExtension) {
                if ($fsExtension->extension !== null) {
                    $fsExtensionIds[] = $fsExtension->extension;
                }
            }
            if ($fsExtensionIds === []) {
                continue;
            }

            $serverExtensions = $this->pemCatalog->getServerExtensions($type, $fsExtensionIds, false, false, $version) ?? [];

            foreach ($fsExtensions as $extId => $fsExt) {
                $extensionKey = $fsExt->extension;
                if ($extensionKey === null) {
                    continue;
                }

                if (! isset($serverExtensions[$extensionKey])
                  and ! in_array($extId, $type->defaultIds(), true)
                  and ! in_array($extensionKey, $mergedExtensions, true)) {
                    $missing[$type->value][] = $fsExt;
                }
            }
        }

        return $missing;
    }
}
