<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Activity\ActivityService;
use Piwigo\Admin\DummyPluginMaintain;
use Piwigo\Admin\DummyThemeMaintain;
use Piwigo\Admin\PluginMaintain;
use Piwigo\Admin\ThemeMaintain;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Users\UserService;

/**
 * Install/activate/deactivate/uninstall/delete state machine, replacing
 * plugins.class.php/themes.class.php/languages.class.php's perform_action()
 * methods. These are genuinely NOT interchangeable behind one shape:
 * confirmed via direct read (and cross-checked against
 * install/piwigo_structure-mysql.sql) that:
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
 * row via $pluginMigrationRepo -- a minimal, auto-recorded history ledger
 * (plugin_id, version, timestamp), not a real migration runner. Written
 * via an upsert (see PluginMigrationRepository::record()) because
 * 'restore' (uninstall then re-activate) can legitimately re-run 'install'
 * at the same version the plugin was already at.
 */
/**
 * `$fsEntry` throughout this class is `array<string, mixed>|null` even
 * though {@see ExtensionScanner}'s own scanPlugin()/scanTheme()/
 * scanLanguage() each return a real, precise, per-type array shape --
 * {@see ExtensionScanner::scan()} (the only real producer reachable from
 * here) deliberately returns the generic `array<string, array<string,
 * mixed>>` dispatch shape rather than a 3-way conditional return type (see
 * that method's own docblock), and this class's own `performAction()`
 * dispatches on the SAME `ExtensionType` to 3 sibling methods that each
 * only ever receive their own type's real entry. Every real read below
 * already narrows defensively (`?? null` + an is_*() check or
 * stringOrDefault()).
 */
final readonly class ExtensionLifecycle
{
    public function __construct(
        private Lang $lang,
        private ExtensionRepository $repo,
        private PemCatalog $pemCatalog,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private PluginMigrationRepository $pluginMigrationRepo,
        private ActivityService $activityService,
        private UserService $userService,
        private \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<string, mixed>|null $fsEntry ExtensionScanner's scanned
     *   entry for $id, or null if not present on disk
     * @param array{revision?: string} $options
     * @return list<string> errors
     */
    public function performAction(
        ExtensionType $type,
        string $action,
        string $id,
        ?array $fsEntry,
        array $options = [],
    ): array {

        if (! $this->currentConfig->enableExtensionsInstall() and $action === 'delete') {
            $this->htmlRenderer
                ->fatalError('Piwigo extensions install/update/delete system is disabled');
        }

        return match ($type) {
            ExtensionType::Plugin => $this->performPluginAction($action, $id, $fsEntry, $options),
            ExtensionType::Theme => $this->performThemeAction($action, $id, $fsEntry),
            ExtensionType::Language => $this->performLanguageAction($action, $id, $fsEntry),
        };
    }

    /**
     * @param array<string, mixed>|null $fsEntry
     * @param array{revision?: string} $options
     * @return list<string>
     */
    private function performPluginAction(string $action, string $id, ?array $fsEntry, array $options): array
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
                if ($dbRow !== null || $fsEntry === null) {
                    break;
                }

                $maintain = $this->buildPluginMaintain($id);
                $fsVersion = $this->stringOrDefault($fsEntry['version'] ?? null, '0');
                $maintain->install($fsVersion, $errors);
                $activityDetails['version'] = $fsVersion;

                if ($errors === []) {
                    $this->repo->insertPlugin($id, $fsVersion);
                    $this->pluginMigrationRepo->record($id, $fsVersion, \Piwigo\Core\Env::now()->format('Y-m-d H:i:s'));
                } else {
                    $activityDetails['result'] = 'error';
                }
                break;

            case 'update':
                $previousVersion = $this->stringOrDefault($fsEntry['version'] ?? null, '0');
                $activityDetails['from_version'] = $previousVersion;
                if (! isset($options['revision'])) {
                    throw new \LogicException("performPluginAction('update'): missing 'revision' option");
                }

                // $errors[0] deliberately holds the raw extraction status
                // string (e.g. 'ok'), not a message -- matches
                // plugins.class.php::perform_action()'s own exact shape:
                // even a fully successful update returns ['ok'] as its
                // "errors" (any real caller checks $errors[0] === 'ok',
                // never empty($errors)). Preserved as-is rather than
                // "fixed" -- changing it would silently change behavior
                // for the one existing caller (Piwigo\Ws\PwgExtensions::
                // update()), which is out of this phase's scope to also
                // migrate.
                $extraction = $this->pemCatalog->extractArchive(ExtensionType::Plugin, 'upgrade', $options['revision'], $id);
                $errors[0] = $extraction['status'];

                if ($errors[0] === 'ok') {
                    $newFsEntry = new ExtensionScanner()
                        ->scan(ExtensionType::Plugin, $this->urlService, $this->lang)[$id] ?? null;
                    $newVersion = $this->stringOrDefault($newFsEntry['version'] ?? null, '0');
                    $activityDetails['to_version'] = $newVersion;

                    $maintain = $this->buildPluginMaintain($id);
                    $maintain->update($previousVersion, $newVersion, $errors);

                    if ($newVersion !== 'auto') {
                        $this->repo->updateVersion(ExtensionType::Plugin, $id, $newVersion);
                        $this->pluginMigrationRepo->record($id, $newVersion, \Piwigo\Core\Env::now()->format('Y-m-d H:i:s'));
                    }
                } else {
                    $activityDetails['result'] = 'error';
                }
                break;

            case 'activate':
                if ($dbRow === null) {
                    $errors = $this->performPluginAction('install', $id, $fsEntry, $options);
                    $dbRow = $this->repo->find(ExtensionType::Plugin, $id);
                } elseif ($dbRow['state'] === 'active') {
                    break;
                }

                if ($errors === []) {
                    $maintain = $this->buildPluginMaintain($id);
                    $crtVersion = $this->stringOrDefault($dbRow['version'] ?? null, '0');
                    $maintain->activate($crtVersion, $errors);
                    $activityDetails['version'] = $crtVersion;
                }

                if ($errors === []) {
                    $this->repo->updatePluginState($id, 'active');
                } else {
                    $activityDetails['result'] = 'error';
                }
                break;

            case 'deactivate':
                if ($dbRow === null || ($dbRow['state'] ?? null) !== 'active') {
                    $activityDetails['result'] = 'error';
                    break;
                }

                $this->repo->updatePluginState($id, 'inactive');

                $maintain = $this->buildPluginMaintain($id);
                $maintain->deactivate();

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

                $this->repo->delete(ExtensionType::Plugin, $id);

                $maintain = $this->buildPluginMaintain($id);
                $maintain->uninstall();
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
                if ($fsEntry === null) {
                    break;
                }
                $activityDetails['fs_version'] = $fsEntry['version'] ?? null;

                FilesystemHelper::deltree(\Piwigo\Admin\PluginLoader::pluginsPath() . $id, \Piwigo\Admin\PluginLoader::pluginsPath() . 'trash');
                break;
        }

        $this->activityService->record('system', ActivitySystem::Plugin, $action, $activityDetails);

        return array_values($errors);
    }

    /**
     * @param array<string, mixed>|null $fsEntry
     * @return list<string>
     */
    private function performThemeAction(string $action, string $id, ?array $fsEntry): array
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

                $isMobile = (bool) ($fsEntry['mobile'] ?? false);
                $currentMobileTheme = $this->currentConfig->mobilTheme();
                $hasOtherMobileTheme = $currentMobileTheme !== '' && $currentMobileTheme !== '0';
                if ($isMobile && $hasOtherMobileTheme && $currentMobileTheme !== $id) {
                    $errors[] = $this->lang->t('You can activate only one mobile theme.');
                    break;
                }

                $maintain = $this->buildThemeMaintain($id);
                $fsVersion = $this->stringOrDefault($fsEntry['version'] ?? null, '0');
                $maintain->activate($fsVersion, $errors);

                if ($errors === []) {
                    $fsName = $this->stringOrDefault($fsEntry['name'] ?? null, $id);
                    $this->repo->insertNamed(ExtensionType::Theme, $id, $fsVersion, $fsName);
                    $activityDetails['version'] = $fsVersion;

                    if ($isMobile) {
                        $this->configService->confUpdateParam('mobile_theme', $id);
                    }
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

                if ($id === $this->userService->getDefaultTheme()) {
                    $newTheme = $this->pickReplacementDefaultTheme($id);
                    $this->setDefaultTheme($newTheme);
                }

                $maintain = $this->buildThemeMaintain($id);
                $maintain->deactivate();

                $this->repo->delete(ExtensionType::Theme, $id);

                if ((bool) ($fsEntry['mobile'] ?? false)) {
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

                $maintain = $this->buildThemeMaintain($id);
                $maintain->delete();

                FilesystemHelper::deltree($this->currentConfig->themesPath() . $id, $this->currentConfig->themesPath() . 'trash');
                break;

            case 'set_default':
                $this->setDefaultTheme($id);
                break;
        }

        $this->activityService->record('system', ActivitySystem::Theme, $action, $activityDetails);

        return array_values($errors);
    }

    /**
     * @param array<string, mixed>|null $fsEntry
     * @return list<string>
     */
    private function performLanguageAction(string $action, string $id, ?array $fsEntry): array
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

                $fsVersion = $this->stringOrDefault($fsEntry['version'] ?? null, '0');
                $fsName = $this->stringOrDefault($fsEntry['name'] ?? null, '');
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

                $languagesDir = \Piwigo\Core\CurrentPaths::get()->root . 'language/';
                FilesystemHelper::deltree($languagesDir . $id, $languagesDir . 'trash');
                break;

            case 'set_default':
                $defaultUserId = $this->currentConfig->defaultUserId();
                $guestId = $this->currentConfig->guestId();
                $this->repo->setLanguageForUserIds($id, $defaultUserId, $guestId);
                break;
        }

        return $errors;
    }

    /**
     * Also used read-only by the themes listing page (is this theme
     * activable) -- not just this class's own 'activate' action.
     *
     * @param array<string, mixed>|null $fsEntry
     */
    public function missingParentTheme(string $themeId, ?array $fsEntry): ?string
    {
        $parent = $fsEntry['parent'] ?? null;
        if (! is_string($parent) || $parent === 'default') {
            return null;
        }

        $parentFsEntry = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $this->urlService, $this->lang)[$parent] ?? null;
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
        foreach (new ExtensionScanner()->scan(ExtensionType::Theme, $this->urlService, $this->lang) as $candidate) {
            if (($candidate['parent'] ?? null) === $themeId) {
                $name = $candidate['name'] ?? null;
                if (is_string($name)) {
                    $children[] = $name;
                }
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

        $defaultUserId = $this->currentConfig->defaultUserId();
        $guestId = $this->currentConfig->guestId();

        $userIds[] = (string) $defaultUserId;
        $userIds[] = (string) $guestId;
        $userIds = array_values(array_unique($userIds));

        $this->repo->setThemeForUsers($themeId, $userIds);
    }

    private function buildPluginMaintain(string $pluginId): PluginMaintain
    {
        $fileToInclude = \Piwigo\Admin\PluginLoader::pluginsPath() . $pluginId . '/maintain';
        // piwigo-videojs and piwigo-openstreetmap have a "-" in their
        // folder name (=plugin_id); a class name can't have a "-".
        $classname = str_replace('-', '_', $pluginId . '_maintain');

        if (file_exists($fileToInclude . '.class.php')) {
            include_once $fileToInclude . '.class.php';
            $maintain = new $classname($pluginId);
            if (! $maintain instanceof PluginMaintain) {
                throw new \LogicException("buildPluginMaintain(): {$classname} does not extend PluginMaintain");
            }

            return $maintain;
        }

        if (file_exists($fileToInclude . '.inc.php')) {
            include_once $fileToInclude . '.inc.php';
            if (class_exists($classname)) {
                $maintain = new $classname($pluginId);
                if (! $maintain instanceof PluginMaintain) {
                    throw new \LogicException("buildPluginMaintain(): {$classname} does not extend PluginMaintain");
                }

                return $maintain;
            }
        }

        return new DummyPluginMaintain($pluginId);
    }

    private function buildThemeMaintain(string $themeId): ThemeMaintain
    {
        $fileToInclude = $this->currentConfig->themesPath() . '/' . $themeId . '/admin/maintain.inc.php';
        $classname = $themeId . '_maintain';

        if (file_exists($fileToInclude)) {
            include_once $fileToInclude;
            if (class_exists($classname)) {
                $maintain = new $classname($themeId);
                if (! $maintain instanceof ThemeMaintain) {
                    throw new \LogicException("buildThemeMaintain(): {$classname} does not extend ThemeMaintain");
                }

                return $maintain;
            }
        }

        return new DummyThemeMaintain($themeId);
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
