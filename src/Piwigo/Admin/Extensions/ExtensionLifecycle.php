<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\Projection\LanguageScanRow;
use Piwigo\Admin\Extensions\Projection\PluginScanRow;
use Piwigo\Admin\Extensions\Projection\ThemeScanRow;
use Piwigo\Admin\PluginLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginManifest;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeManifest;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use RuntimeException;

/**
 * Install/activate/deactivate/uninstall/delete state machine, replacing
 * plugins.class.php/themes.class.php/languages.class.php's perform_action()
 * methods. These are genuinely NOT interchangeable behind one shape
 * (cross-checked against install/piwigo_structure-mysql.sql):
 *
 * - Plugins alone have a persisted `state` enum('inactive','active') column
 *   -- a plugin row's mere existence means "installed" (active or not).
 *   Only plugins support install/update/restore in addition to activate/
 *   deactivate/uninstall/delete, and only plugins have
 *   activity_details['result']='error' + numbered from/to-version fields.
 * - Themes/languages have no `state` column at all -- a row's existence
 *   alone means active; deactivating deletes the row outright. Themes push
 *   real l10n() error messages onto the returned $errors array directly
 *   (parent-theme-missing, mobile-theme-uniqueness, last-theme, has-
 *   dependents), which plugins never do.
 * - Only plugins/themes call pwg_activity() at all -- languages.class.php's
 *   perform_action() never does, preserved here via
 *   ExtensionType::activityType() returning null for Language.
 * - Plugin's restore/delete/uninstall/activate recursively re-invoke this
 *   same state machine (e.g. restore = uninstall then activate), each
 *   nested call logging its OWN activity entry -- reproduced here via
 *   private per-type methods calling each other directly (not through the
 *   public entry point, so the delete-disabled guard below -- which only
 *   ever gates a top-level 'delete' call -- doesn't re-run pointlessly).
 *
 * A successful plugin 'install'/'update' also records a plugin_migrations
 * row -- a minimal, auto-recorded history ledger (plugin_id, version,
 * timestamp), not a real migration runner. This class does not do the
 * recording itself: PluginConfig\PluginRegistry::install()/update() both
 * already call their own injected PluginMigrationRepository::record()
 * internally (an upsert, since 'restore' -- uninstall then re-activate --
 * can legitimately re-run 'install' at the same version the plugin was
 * already at), so a second, ExtensionLifecycle-owned repository/call site
 * would just double-write the same ledger entry.
 *
 * The business-rule layer (existence checks, last-theme/mobile-theme/
 * parent-theme guards, default-theme reassignment, activity logging)
 * stays here; only the innermost mechanical step retargets onto
 * PluginConfig\PluginRegistry/ThemeRegistry. Every registry call is
 * wrapped in a `catch (RuntimeException)`, since PluginValidationException/
 * PluginDependencyException/ThemeValidationException/
 * ThemeDependencyException are how the registries report failure --
 * `$errors`/`activityDetails['result']` is this class's own contract,
 * the registries know nothing about either. A caught
 * *ValidationException ("no validated manifest") is the expected,
 * common outcome for a legacy (main.inc.php/themeconf.inc.php-only)
 * target that nothing has booted, so this class making its own admin
 * actions fail loudly instead of quietly no-op'ing is the intentional,
 * accepted consequence, not a bug to route around.
 */
final readonly class ExtensionLifecycle
{
    public function __construct(
        private Lang $lang,
        private ExtensionRepository $repo,
        private PemCatalog $pemCatalog,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private ActivityService $activityService,
        private UserService $userService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private PluginRegistry $pluginRegistry,
        private ThemeRegistry $themeRegistry,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * $fsEntry is ExtensionScanner's own scanned entry for $id (null if
     * not present on disk) -- callers pass whichever of
     * PluginScanRow/ThemeScanRow/LanguageScanRow matches $type; the
     * `instanceof` narrowing below is a pure safety net (every real
     * caller already passes the matching pair, same as `ExtensionScanner::
     * scan()`'s own `match ($type)` dispatch), not a real branch any
     * caller exercises.
     *
     * @param array{revision?: string} $options
     * @return list<string> errors
     */
    public function performAction(
        ExtensionType $type,
        string $action,
        string $id,
        PluginScanRow|ThemeScanRow|LanguageScanRow|null $fsEntry,
        array $options = [],
    ): array {

        if (! $this->currentConfig->enableExtensionsInstall and $action === 'delete') {
            $this->htmlRenderer
                ->fatalError('Piwigo extensions install/update/delete system is disabled');
        }

        return match ($type) {
            ExtensionType::Plugin => $this->performPluginAction($action, $id, $fsEntry instanceof PluginScanRow ? $fsEntry : null, $options),
            ExtensionType::Theme => $this->performThemeAction($action, $id, $fsEntry instanceof ThemeScanRow ? $fsEntry : null),
            ExtensionType::Language => $this->performLanguageAction($action, $id, $fsEntry instanceof LanguageScanRow ? $fsEntry : null),
        };
    }

    /**
     * @param array{revision?: string} $options
     * @return list<string>
     */
    private function performPluginAction(string $action, string $id, ?PluginScanRow $fsEntry, array $options): array
    {
        $dbRow = $this->repo->find(ExtensionType::Plugin, $id);
        $errors = [];
        // Entity-agnostic accumulator handed to ActivityService::record()'s
        // own genuinely-arbitrary $details param -- same rationale as
        // Audit\AuditService's $before/$after.
        /** @var array<string, mixed> $activityDetails */
        $activityDetails = [
            'plugin_id' => $id,
        ];

        switch ($action) {
            case 'install':
                if ($dbRow !== null || ! $this->pluginExistsOnDisk($id, $fsEntry)) {
                    break;
                }

                try {
                    $this->pluginRegistry->install($id);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                    $activityDetails['result'] = 'error';
                    break;
                }

                $installedManifest = $this->pluginRegistry->getManifest($id);
                $activityDetails['version'] = $installedManifest instanceof PluginManifest ? $installedManifest->version : '0';
                break;

            case 'update':
                $previousVersion = $fsEntry->version ?? '0';
                $activityDetails['from_version'] = $previousVersion;
                if (! isset($options['revision'])) {
                    throw new LogicException("performPluginAction('update'): missing 'revision' option");
                }

                // $errors[0] deliberately holds the raw extraction status
                // string (e.g. 'ok'), not a message -- matches
                // plugins.class.php::perform_action()'s own exact shape:
                // even a fully successful update returns ['ok'] as its
                // "errors" (any real caller checks $errors[0] === 'ok',
                // never empty($errors)). Preserved as-is rather than
                // "fixed" -- changing it would silently change behavior
                // for the one existing caller
                // (Controller\Api\Extensions\ExtensionUpdateController).
                $extraction = $this->pemCatalog->extractArchive(ExtensionType::Plugin, 'upgrade', $options['revision'], $id);
                $errors[0] = $extraction->status;

                if ($errors[0] === 'ok') {
                    // ExtensionScanner only ever recognizes a plugin.json
                    // (the same marker PluginRegistry itself scans, just
                    // without its stricter opis/json-schema validation),
                    // so there is no real "legacy, no-plugin.json" case
                    // reachable here to branch on: PemCatalog::
                    // extractArchive()'s own marker search
                    // (ExtensionType::markerFilenames(), plugin.json-only)
                    // already can't even locate a main.inc.php-only
                    // archive's root in the first place, so $errors[0]
                    // would already be 'archive_error', never reaching
                    // this branch at all.
                    // Force a fresh manifest scan (PluginRegistry::load()
                    // is memoized per-request, so without reload() it would
                    // still see whatever it scanned before this extraction
                    // ever ran) then let PluginRegistry derive + persist
                    // its own version bump from the just-extracted
                    // plugin.json -- the one real path left.
                    $this->pluginRegistry->reload();
                    try {
                        $this->pluginRegistry->update($id);
                        $activityDetails['to_version'] = $this->pluginRegistry->getManifest($id)?->version;
                    } catch (RuntimeException $e) {
                        $errors[] = $e->getMessage();
                        $activityDetails['result'] = 'error';
                    }
                } else {
                    $activityDetails['result'] = 'error';
                }
                break;

            case 'activate':
                if ($dbRow === null) {
                    $errors = $this->performPluginAction('install', $id, $fsEntry, $options);
                    $dbRow = $this->repo->find(ExtensionType::Plugin, $id);
                    if ($dbRow === null) {
                        // The 'install' delegation above was itself a safe
                        // no-op (pluginExistsOnDisk() is false -- see that
                        // case's own guard), not a failure: $errors is
                        // already [] in that case. Nothing was ever
                        // installed, so there is nothing to activate --
                        // without this, activate() below would call
                        // PluginRegistry::activate($id), which throws
                        // (requireManifest() has no manifest for a plugin
                        // that was never scanned off disk).
                        break;
                    }
                } elseif ($dbRow['state'] === 'active') {
                    break;
                }

                if ($errors === []) {
                    try {
                        $this->pluginRegistry->activate($id);
                        $activatedManifest = $this->pluginRegistry->getManifest($id);
                        $activityDetails['version'] = $activatedManifest instanceof PluginManifest ? $activatedManifest->version : '0';
                    } catch (RuntimeException $e) {
                        $errors[] = $e->getMessage();
                    }
                }

                if ($errors !== []) {
                    $activityDetails['result'] = 'error';
                }
                break;

            case 'deactivate':
                if ($dbRow === null || ($dbRow['state'] ?? null) !== 'active') {
                    $activityDetails['result'] = 'error';
                    break;
                }

                try {
                    $this->pluginRegistry->deactivate($id);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                    $activityDetails['result'] = 'error';
                    break;
                }

                if (isset($dbRow['version'])) {
                    $activityDetails['version'] = $dbRow['version'];
                }
                break;

            case 'uninstall':
                if ($dbRow === null) {
                    $activityDetails['result'] = 'error';
                    $activityDetails['error'] = 'plugin not installed';
                    break;
                }

                if (isset($dbRow['version'])) {
                    $activityDetails['version'] = $dbRow['version'];
                }

                if (($dbRow['state'] ?? null) === 'active') {
                    $this->performPluginAction('deactivate', $id, $fsEntry, $options);
                }

                try {
                    $this->pluginRegistry->uninstall($id);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                    $activityDetails['result'] = 'error';
                }
                break;

            case 'restore':
                $this->performPluginAction('uninstall', $id, $fsEntry, $options);
                $errors = $this->performPluginAction('activate', $id, $fsEntry, $options);
                break;

            case 'delete':
                if ($dbRow !== null) {
                    if (isset($dbRow['version'])) {
                        $activityDetails['db_version'] = $dbRow['version'];
                    }
                    $this->performPluginAction('uninstall', $id, $fsEntry, $options);
                }
                if (! $this->pluginExistsOnDisk($id, $fsEntry)) {
                    break;
                }
                $activityDetails['fs_version'] = $fsEntry->version ?? $this->pluginRegistry->getManifest($id)?->version;

                FilesystemHelper::deltree(PluginLoader::pluginsPath($this->paths) . $id, PluginLoader::pluginsPath($this->paths) . 'trash');
                break;
        }

        $this->activityService->record('system', ActivitySystem::Plugin, $action, $activityDetails);

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function performThemeAction(string $action, string $id, ?ThemeScanRow $fsEntry): array
    {

        $dbRow = $this->repo->find(ExtensionType::Theme, $id);
        $errors = [];
        // Same rationale as performPluginAction()'s own $activityDetails.
        /** @var array<string, mixed> $activityDetails */
        $activityDetails = [
            'theme_id' => $id,
        ];

        switch ($action) {
            case 'activate':
                if ($dbRow !== null || $id === 'default') {
                    break;
                }

                $missingParent = $this->missingParentTheme($id, $fsEntry);
                if ($missingParent !== null) {
                    $errors[] = $this->lang->t('Impossible to activate this theme, the parent theme is missing: %s', $missingParent);
                    break;
                }

                $isMobile = $fsEntry->mobile ?? false;
                $currentMobileTheme = $this->currentConfig->mobileTheme;
                $hasOtherMobileTheme = $currentMobileTheme !== '' && $currentMobileTheme !== '0';
                if ($isMobile && $hasOtherMobileTheme && $currentMobileTheme !== $id) {
                    $errors[] = $this->lang->t('You can activate only one mobile theme.');
                    break;
                }

                try {
                    $this->themeRegistry->activate($id);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                    break;
                }

                $activatedThemeManifest = $this->themeRegistry->getManifest($id);
                $activityDetails['version'] = $activatedThemeManifest instanceof ThemeManifest ? $activatedThemeManifest->version : '0';

                if ($isMobile) {
                    $this->configService->confUpdateParam('mobile_theme', $id);
                }
                break;

            case 'deactivate':
                if ($dbRow === null) {
                    break;
                }

                if ($this->repo->count(ExtensionType::Theme) <= 1) {
                    $errors[] = $this->lang->t('Impossible to deactivate this theme, you need at least one theme.');
                    break;
                }

                // Computed before the real deactivate() call below (a pure
                // read, safe to run early), but only actually applied
                // after it succeeds -- ThemeRegistry::deactivate() can
                // genuinely throw (a legacy theme with no theme.json at
                // all), unlike the old buildThemeMaintain()'s always-safe
                // fallback, so reassigning the site's default theme first
                // would otherwise leave a real partial-state bug: the
                // default silently changed even though the old theme was
                // never actually removed.
                $wasDefault = $id === $this->userService->getDefaultTheme();
                $replacementTheme = $wasDefault ? $this->pickReplacementDefaultTheme($id) : null;

                try {
                    $this->themeRegistry->deactivate($id);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                    break;
                }

                if ($replacementTheme !== null) {
                    $this->setDefaultTheme($replacementTheme);
                }

                if ($fsEntry->mobile ?? false) {
                    $this->configService->confUpdateParam('mobile_theme', '');
                }
                break;

            case 'delete':
                if ($dbRow !== null) {
                    $errors[] = 'CANNOT DELETE - THEME IS INSTALLED';
                    break;
                }
                if ($fsEntry === null) {
                    break;
                }

                $children = $this->getChildrenThemes($id);
                if ($children !== []) {
                    $errors[] = $this->lang->t('Impossible to delete this theme. Other themes depends on it: %s', implode(', ', $children));
                    break;
                }

                try {
                    $this->themeRegistry->uninstall($id);
                } catch (RuntimeException) {
                    // No real theme.json manifest for this id (every real
                    // legacy/PEM-catalog theme today) -- nothing for the
                    // new contract to uninstall; the filesystem cleanup
                    // below still always runs regardless, same as today's
                    // buildThemeMaintain()-fallback behavior for a theme
                    // with no admin/maintain.inc.php.
                }

                FilesystemHelper::deltree($this->currentConfig->themesPath . $id, $this->currentConfig->themesPath . 'trash');
                break;

            case 'set_default':
                $this->setDefaultTheme($id);
                break;
        }

        $this->activityService->record('system', ActivitySystem::Theme, $action, $activityDetails);

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function performLanguageAction(string $action, string $id, ?LanguageScanRow $fsEntry): array
    {

        $dbRow = $this->repo->find(ExtensionType::Language, $id);
        $conn = DbConnection::build();
        $errors = [];

        switch ($action) {
            case 'activate':
                if ($dbRow !== null) {
                    $errors[] = 'CANNOT ACTIVATE - LANGUAGE IS ALREADY ACTIVATED';
                    break;
                }

                $fsVersion = $fsEntry->version ?? '0';
                $fsName = $fsEntry->name ?? '';
                $this->repo->insertNamed(ExtensionType::Language, $id, $fsVersion, $fsName);
                break;

            case 'deactivate':
                if ($dbRow === null) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS ALREADY DEACTIVATED';
                    break;
                }
                if ($id === $this->userService->getDefaultLanguage()) {
                    $errors[] = 'CANNOT DEACTIVATE - LANGUAGE IS DEFAULT LANGUAGE';
                    break;
                }

                $this->repo->delete(ExtensionType::Language, $id);
                break;

            case 'delete':
                if ($dbRow !== null) {
                    $errors[] = 'CANNOT DELETE - LANGUAGE IS ACTIVATED';
                    break;
                }
                if ($fsEntry === null) {
                    $errors[] = 'CANNOT DELETE - LANGUAGE DOES NOT EXIST';
                    break;
                }

                $this->repo->reassignUsersFromLanguage($id, $this->userService->getDefaultLanguage());

                $languagesDir = $this->paths->root . 'language/';
                FilesystemHelper::deltree($languagesDir . $id, $languagesDir . 'trash');
                break;

            case 'set_default':
                $defaultUserId = $this->currentConfig->defaultUserId;
                $guestId = $this->currentConfig->guestId;
                $this->repo->setLanguageForUserIds($id, $defaultUserId, $guestId);
                break;
        }

        return $errors;
    }

    /**
     * Also used read-only by the themes listing page (is this theme
     * activable) -- not just this class's own 'activate' action.
     */
    public function missingParentTheme(string $themeId, ?ThemeScanRow $fsEntry): ?string
    {
        $parent = $fsEntry?->parent;
        if ($parent === null || $parent === 'default') {
            return null;
        }

        $parentFsEntry = new ExtensionScanner()
            ->scanThemes($this->urlService, $this->paths, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->entityManager)[$parent] ?? null;
        if ($parentFsEntry === null) {
            return $parent;
        }

        return $this->missingParentTheme($parent, $parentFsEntry);
    }

    /**
     * Also used read-only by the themes listing page (is this theme
     * deletable) -- not just this class's own 'delete' action.
     *
     * @return list<string>
     */
    public function getChildrenThemes(string $themeId): array
    {
        $children = [];
        foreach (new ExtensionScanner()->scanThemes($this->urlService, $this->paths, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->entityManager) as $candidate) {
            if ($candidate->parent === $themeId) {
                $children[] = $candidate->name;
            }
        }

        return $children;
    }

    private function pickReplacementDefaultTheme(string $excludeId): string
    {
        return $this->repo->findAnyThemeIdExcluding($excludeId) ?? 'default';
    }

    private function setDefaultTheme(string $themeId): void
    {

        $defaultTheme = $this->userService->getDefaultTheme();
        $userIds = $this->repo->findUserIdsByTheme($defaultTheme);

        $defaultUserId = $this->currentConfig->defaultUserId;
        $guestId = $this->currentConfig->guestId;

        $userIds[] = (string) $defaultUserId;
        $userIds[] = (string) $guestId;
        $userIds = array_values(array_unique($userIds));

        $this->repo->setThemeForUsers($themeId, $userIds);
    }

    /**
     * "Does this plugin exist on disk at all" -- accepts either of
     * ExtensionScanner's own plugin.json fs scan ($fsEntry, passed in by
     * the caller from whatever it scanned earlier in the same request) or
     * a fresh PluginRegistry::getManifest() lookup here. Both ultimately
     * read the same plugin.json marker, but a bare
     * $fsEntry === null check alone would still wrongly no-op every
     * lifecycle action whenever the caller's own $fsEntry snapshot is
     * stale relative to a plugin.json that only started existing after
     * that scan ran (e.g. right after PemCatalog::extractArchive()
     * finishes, earlier in the very same 'install' call this method
     * itself gates).
     */
    private function pluginExistsOnDisk(string $id, ?PluginScanRow $fsEntry): bool
    {
        return $fsEntry !== null || $this->pluginRegistry->getManifest($id) instanceof PluginManifest;
    }
}
