<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Extensions\ExtensionAction;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Paths;
use Piwigo\Core\ZipExtractor;
use Piwigo\Event\Lifecycle\PluginInstallErrors;
use Piwigo\Html\HtmlService;
use Piwigo\Plugin\PluginDependencyException;
use Piwigo\Plugin\PluginRecord;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Plugin\PluginValidationException;
use Piwigo\Users\CurrentUser;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class Plugins
{
    /** @var array<string, array<string,mixed>> */
    public array $fs_plugins = [];
    /** @var array<string, PluginRecord> */
    public array $db_plugins_by_id = [];
    /** @var array<int|string, array<mixed>> */
    public array $server_plugins = [];
    /** @var string[] */
    public array $default_plugins = ['LocalFilesEditor', 'language_switch', 'TakeATour', 'AdminTools'];

    /**
     * Initialize $fs_plugins and $db_plugins_by_id
     */
    public function __construct(
        private readonly AdminService $adminService,
        private readonly HtmlService $htmlService,
        private readonly PluginRepository $pluginRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly PluginRegistry $pluginRegistry,
        private readonly PemUrlResolver $pemUrlResolver,
        private readonly Paths $paths,
        private readonly CacheItemPoolInterface $pool,
    ) {
        $this->getFsPlugins();

        foreach ($this->pluginRepository->findAll() as $db_plugin) {
            $this->db_plugins_by_id[$db_plugin->id] = $db_plugin;
        }
    }

    /**
     * Resolve plugin.json `hasSettings` (true|false|"webmaster") to the
     * boolean the legacy fs_plugins shape expects. Webmaster-only
     * settings are visible to webmaster sessions and hidden otherwise.
     */
    private function resolveHasSettings(bool|string $hasSettings): bool
    {
        if (is_bool($hasSettings)) {
            return $hasSettings;
        }
        if ($hasSettings === 'webmaster') {
            return CurrentUser::isInitialized() && CurrentUser::get()->status === 'webmaster';
        }
        return false;
    }

    /**
     * Perform requested actions.
     *
     * `$revision` is the PEM revision identifier consumed by the `update`
     * action when fetching the upgrade archive; ignored for every other
     * action.
     */
    public function performAction(ExtensionAction $action, string $plugin_id, ?string $revision = null): mixed
    {
        if (!Config::enableExtensionsInstall() and ExtensionAction::Delete === $action) {
            die('Piwigo extensions install/update/delete system is disabled');
        }

        $crt_db_plugin = $this->db_plugins_by_id[$plugin_id] ?? null;

        $activity_details = ['plugin_id' => $plugin_id];

        $errors = [];

        switch ($action) {
            case ExtensionAction::Install:
                if ($crt_db_plugin !== null or !isset($this->fs_plugins[$plugin_id])) {
                    break;
                }

                $installVersion = $this->fs_plugins[$plugin_id]['version'];
                $installVersionStr = is_string($installVersion) ? $installVersion : '';
                $activity_details['version'] = $installVersionStr;

                try {
                    $this->pluginRegistry->install($plugin_id);
                } catch (PluginValidationException | PluginDependencyException $e) {
                    $errors[] = $e->getMessage();
                }

                $installErrorsEvent = new PluginInstallErrors($errors);
                $this->dispatcher->dispatch($installErrorsEvent);
                $errors = $installErrorsEvent->errors;

                if (!empty($errors)) {
                    $activity_details['result'] = 'error';
                }
                break;

            case ExtensionAction::Update:
                $prevVersionRaw = $this->fs_plugins[$plugin_id]['version'] ?? '';
                $previous_version = is_string($prevVersionRaw) ? $prevVersionRaw : '';
                $activity_details['from_version'] = $previous_version;
                $errors[0] = $this->extractPluginFiles('upgrade', $revision ?? '', $plugin_id);

                if ($errors[0] === 'ok') {
                    $this->getFsPlugin($plugin_id); // refresh plugins list
                    $newVersionRaw = $this->fs_plugins[$plugin_id]['version'] ?? '';
                    $new_version = is_string($newVersionRaw) ? $newVersionRaw : '';
                    $activity_details['to_version'] = $new_version;

                    try {
                        $this->pluginRegistry->update($plugin_id);
                    } catch (PluginValidationException | PluginDependencyException $e) {
                        $errors[] = $e->getMessage();
                    }
                } else {
                    $activity_details['result'] = 'error';
                }


                break;

            case ExtensionAction::Activate:
                if ($crt_db_plugin === null) {
                    $installResult = $this->performAction(ExtensionAction::Install, $plugin_id);
                    $errors = is_array($installResult) ? $installResult : [];
                    $crt_db_plugin = $this->pluginRepository->findAll(null, $plugin_id)[0] ?? null;
                    ConfigService::loadConfFromDb();
                } elseif ($crt_db_plugin->state === 'active') {
                    break;
                }

                if (count($errors) === 0) {
                    $activity_details['version'] = $crt_db_plugin !== null ? $crt_db_plugin->version : '';

                    try {
                        $this->pluginRegistry->activate($plugin_id);
                    } catch (PluginValidationException | PluginDependencyException $e) {
                        $errors[] = $e->getMessage();
                    }
                }

                if (count($errors) !== 0) {
                    $activity_details['result'] = 'error';
                }
                break;

            case ExtensionAction::Deactivate:
                if ($crt_db_plugin === null or $crt_db_plugin->state !== 'active') {
                    $activity_details['result'] = 'error';
                    break;
                }

                $activity_details['version'] = $crt_db_plugin->version;

                $this->pluginRegistry->deactivate($plugin_id);
                break;

            case ExtensionAction::Uninstall:
                if ($crt_db_plugin === null) {
                    $activity_details['result'] = 'error';
                    $activity_details['error'] = 'plugin not installed';
                    break;
                }

                $activity_details['version'] = $crt_db_plugin->version;

                if ($crt_db_plugin->state === 'active') {
                    $this->performAction(ExtensionAction::Deactivate, $plugin_id);
                }

                $this->pluginRegistry->uninstall($plugin_id);
                break;

            case ExtensionAction::Restore:
                $this->performAction(ExtensionAction::Uninstall, $plugin_id);
                unset($this->db_plugins_by_id[$plugin_id]);
                $errors = $this->performAction(ExtensionAction::Activate, $plugin_id);
                break;

            case ExtensionAction::Delete:
                if ($crt_db_plugin !== null) {
                    $activity_details['db_version'] = $crt_db_plugin->version;
                    $this->performAction(ExtensionAction::Uninstall, $plugin_id);
                }
                if (!isset($this->fs_plugins[$plugin_id])) {
                    break;
                } else {
                    $activity_details['fs_version'] = $this->fs_plugins[$plugin_id]['version'];
                }

                $this->adminService->deltree(Config::pluginsPath() . $plugin_id, Config::pluginsPath() . 'trash');
                break;

            case ExtensionAction::SetDefault:
                // Plugins do not support set_default — silently no-op.
                break;
        }

        $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Plugin, ActivityAction::from($action->value), $activity_details));

        return $errors;
    }

    /**
     * Get plugins defined in the plugin directory
     */
    public function getFsPlugins(): void
    {
        $dir = opendir(Config::pluginsPath());
        if ($dir === false) {
            return;
        }
        while ($file = readdir($dir)) {
            if ($file != '.' and $file != '..') {
                if (preg_match('/^[a-zA-Z0-9-_]+$/', $file)) {
                    $this->getFsPlugin($file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * Load metadata of a plugin in `fs_plugins` array. Source of truth is
     * the validated plugin.json manifest exposed by PluginRegistry; plugins
     * without a manifest are invisible to the admin UX (v17 broke pre-17
     * extensions by design).
     *
     * @return array<string,mixed>|false
     */
    public function getFsPlugin(string $plugin_id): array|false
    {
        $manifest = $this->pluginRegistry->getManifest($plugin_id);
        if ($manifest === null) {
            return false;
        }
        $plugin = [
            'name'        => htmlspecialchars($manifest->name),
            'version'     => htmlspecialchars($manifest->version),
            'uri'         => $manifest->homepage !== null ? htmlspecialchars($manifest->homepage) : '',
            'description' => htmlspecialchars($manifest->description),
            'author'      => $manifest->author !== null ? htmlspecialchars($manifest->author) : '',
            'hasSettings' => $this->resolveHasSettings($manifest->hasSettings),
        ];
        if ($manifest->authorUri !== null) {
            $plugin['author uri'] = htmlspecialchars($manifest->authorUri);
        }
        $this->fs_plugins[$plugin_id] = $plugin;
        return $plugin;
    }

    /**
     * Sort fs_plugins
     */
    public function sortFsPlugins(string $order = 'name'): void
    {
        switch ($order) {
            case 'name':
                uasort($this->fs_plugins, $this->htmlService->nameCompare(...));
                break;
            case 'status':
                $this->sortPluginsByState();
                break;
            case 'author':
                uasort($this->fs_plugins, $this->pluginAuthorCompare(...));
                break;
            case 'id':
                uksort($this->fs_plugins, strcasecmp(...));
                break;
        }
    }

    /**
     * Return the PEM version id matching the current Piwigo branch, or empty if not on PEM yet.
     *
     * @return string[]
     */
    public function getVersionsToCheck(): array
    {
        $versions_to_check = [];
        $url = $this->pemUrlResolver->url() . '/api/get_version_list.php?category_id='. Config::pemPluginsCategory();
        $result = '';
        if ($this->adminService->fetchRemote($url, $result) and is_string($result) and ($pem_versions = json_decode($result, associative: true)) !== null and is_array($pem_versions)) {
            foreach ($pem_versions as $entry) {
                if (!is_array($entry) || !isset($entry['name'], $entry['id'])) {
                    continue;
                }
                if (AppInfo::branchFromVersion(is_scalar($entry['name']) ? (string) $entry['name'] : '') == AppInfo::branchFromVersion(AppInfo::VERSION)) {
                    $versions_to_check[] = is_scalar($entry['id']) ? (string) $entry['id'] : '';
                    break;
                }
            }
        }
        return $versions_to_check;
    }

    /**
     * Retrieve PEM server datas to $server_plugins.
     */
    public function getServerPlugins(bool $new = false): bool
    {
        $versions_to_check = $this->getVersionsToCheck();
        if (empty($versions_to_check)) {
            return true;
        }

        // Plugins to check
        $plugins_to_check = [];
        foreach ($this->fs_plugins as $fs_plugin) {
            if (isset($fs_plugin['extension'])) {
                $plugins_to_check[] = is_string($fs_plugin['extension']) ? $fs_plugin['extension'] : '';
            }
        }

        // Retrieve PEM plugins infos
        $url = $this->pemUrlResolver->url() . '/api/get_revision_list-next.php';
        $get_data = [
          'category_id' => Config::pemPluginsCategory(),
          'last_revision_only' => 'true',
          'version' => implode(',', $versions_to_check),
          'lang' => substr(CurrentUser::get()->language, 0, 2),
          'get_nb_downloads' => 'true',
        ];

        if (!empty($plugins_to_check)) {
            if ($new) {
                $get_data['extension_exclude'] = implode(',', $plugins_to_check);
            } else {
                $get_data['extension_include'] = implode(',', $plugins_to_check);
            }
        }
        $result = '';
        if ($this->adminService->fetchRemote($url, $result, $get_data) && is_string($result)) {
            $decoded     = json_decode($result, associative: true);
            $pem_plugins = is_array($decoded) ? $decoded : [];
            if ($pem_plugins === []) {
                return false;
            }
            foreach ($pem_plugins as $plugin) {
                if (is_array($plugin) && isset($plugin['extension_id']) && (is_string($plugin['extension_id']) || is_int($plugin['extension_id']))) {
                    $this->server_plugins[$plugin['extension_id']] = $plugin;
                }
            }
            return true;
        }
        return false;
    }

    private const INCOMPAT_CACHE_KEY = 'plugins.incompatible';

    /**
     * Map of installed plugin id → required version for plugins whose
     * currently-installed version is not in the PEM compatibility list
     * for the running Piwigo. Cached in the shared pool (5-minute TTL).
     *
     * Returns false when the remote PEM call fails — callers must treat
     * that as "unknown", not "empty".
     *
     * @return array<string, string>|false
     */
    public function getIncompatiblePlugins(bool $actualize = false): array|false
    {
        $item = $this->pool->getItem(self::INCOMPAT_CACHE_KEY);
        if ($item->isHit() && !$actualize) {
            $cached = $item->get();
            if (is_array($cached)) {
                /** @var array<string, string> $cached */
                return $cached;
            }
        }

        $versions_to_check = $this->getVersionsToCheck();
        if (empty($versions_to_check)) {
            return false;
        }

        // Plugins to check
        $plugins_to_check = [];
        foreach ($this->fs_plugins as $fs_plugin) {
            if (isset($fs_plugin['extension'])) {
                $plugins_to_check[] = is_string($fs_plugin['extension']) ? $fs_plugin['extension'] : '';
            }
        }

        // Retrieve PEM plugins infos
        $url = $this->pemUrlResolver->url() . '/api/get_revision_list.php';
        $get_data = [
          'category_id' => Config::pemPluginsCategory(),
          'version' => implode(',', $versions_to_check),
          'extension_include' => implode(',', $plugins_to_check),
        ];

        $result = '';
        if (!$this->adminService->fetchRemote($url, $result, $get_data) || !is_string($result)) {
            return false;
        }
        $decoded     = json_decode($result, associative: true);
        $pem_plugins = is_array($decoded) ? $decoded : [];
        if ($pem_plugins === []) {
            return false;
        }

        $server_plugins = [];
        foreach ($pem_plugins as $plugin) {
            if (!is_array($plugin) || !isset($plugin['extension_id'], $plugin['revision_name'])) {
                continue;
            }
            $eid = $plugin['extension_id'];
            if (!(is_string($eid) || is_int($eid))) {
                continue;
            }
            if (!isset($server_plugins[$eid])) {
                $server_plugins[$eid] = [];
            }
            $server_plugins[$eid][] = is_string($plugin['revision_name']) ? $plugin['revision_name'] : '';
        }

        $incompatible = [];
        foreach ($this->fs_plugins as $plugin_id => $fs_plugin) {
            $extIdPlug = $fs_plugin['extension'] ?? null;
            if (!is_string($extIdPlug) && !is_int($extIdPlug)) {
                continue;
            }
            $version = is_string($fs_plugin['version'] ?? null) ? $fs_plugin['version'] : '';
            if (!in_array($plugin_id, $this->default_plugins)
              and $version !== 'auto'
              and (!isset($server_plugins[$extIdPlug]) or !in_array($version, $server_plugins[$extIdPlug]))) {
                $incompatible[$plugin_id] = $version;
            }
        }

        $item->set($incompatible);
        $item->expiresAfter(300);
        $this->pool->save($item);
        return $incompatible;
    }

    /**
     * Drop the cached incompatible-plugins map so the next
     * `getIncompatiblePlugins()` call refetches from PEM. Used by callers
     * that detect the cache is stale (e.g. a plugin version changed
     * on disk since the last fetch).
     */
    public function invalidateIncompatibleCache(): void
    {
        $this->pool->deleteItem(self::INCOMPAT_CACHE_KEY);
    }

    /**
     * Sort $server_plugins
     */
    public function sortServerPlugins(string $order = 'date'): void
    {
        switch ($order) {
            case 'date':
                krsort($this->server_plugins);
                break;
            case 'revision':
                usort($this->server_plugins, fn (array $a, array $b): int => $this->extensionRevisionCompare($a, $b));
                break;
            case 'name':
                uasort($this->server_plugins, fn (array $a, array $b): int => $this->extensionNameCompare($a, $b));
                break;
            case 'author':
                uasort($this->server_plugins, fn (array $a, array $b): int => $this->extensionAuthorCompare($a, $b));
                break;
            case 'downloads':
                usort($this->server_plugins, fn (array $a, array $b): int => $this->extensionDownloadsCompare($a, $b));
                break;
        }
    }

    /**
     * Extract plugin files from archive
     * @param string $action install or upgrade
     * @param string $revision remote revision identifier
     * @param string $dest plugin id or extension id
     */
    public function extractPluginFiles(string $action, string $revision, string $dest, ?string &$plugin_id = null): string
    {
        $logger = LoggerRegistry::current();

        if (($archive = tempnam(Config::pluginsPath(), 'zip')) !== false) {
            $url = $this->pemUrlResolver->url() . '/download.php';
            $get_data = [
              'rid' => $revision,
              'origin' => 'piwigo_'.$action,
            ];

            $handle = Filesystem::tryFopen($archive, 'wb');
            if (is_resource($handle)) {
                $fh = $handle;
                if ($this->adminService->fetchRemote($url, $handle, $get_data)) {
                    fclose($fh);
                    $names = ZipExtractor::listNames($archive);
                    if ($names !== []) {
                        $manifest_filepath = null;
                        $status = 'ok';
                        foreach ($names as $filename) {
                            // plugin.json — track the shallowest path so a stray
                            // plugin.json deeper in vendor/ doesn't win.
                            if (basename($filename) === 'plugin.json'
                              and ($manifest_filepath === null
                              or strlen($filename) < strlen($manifest_filepath))) {
                                $manifest_filepath = $filename;
                            }
                        }

                        $logger->debug(__FUNCTION__.', $manifest_filepath = '.(string) $manifest_filepath);

                        if (isset($manifest_filepath)) {
                            $root = dirname($manifest_filepath); // path to plugin's root inside archive
                            if ($action == 'upgrade') {
                                $plugin_id = $dest;
                            } else {
                                $plugin_id = ($root == '.' ? 'extension_' . $dest : basename($root));
                            }
                            $extract_path = Config::pluginsPath() . $plugin_id;
                            $logger->debug(__FUNCTION__.', $extract_path = '.$extract_path);

                            $result = ZipExtractor::extract($archive, $extract_path, $root === '.' ? '' : $root);
                            if ($result !== []) {
                                foreach ($result as $file) {
                                    if ($file['stored_filename'] === $manifest_filepath) {
                                        $status = $file['status'];
                                        break;
                                    }
                                }
                                if ($status === 'ok') {
                                    // Refresh the registry so the freshly-extracted
                                    // plugin.json is validated immediately. A
                                    // PluginValidationException here means the ZIP
                                    // shipped a malformed manifest — surface that
                                    // as the extraction status.
                                    $this->pluginRegistry->reload();
                                    if ($this->pluginRegistry->getManifest($plugin_id) === null) {
                                        $status = 'manifest_invalid';
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
                                            $this->adminService->deltree($path, Config::pluginsPath() . 'trash');
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
            Filesystem::tryUnlink($archive);
        }
        return $status;
    }

    /**
     * @return string[]
     */
    /** @return array<mixed> */
    public function getMergedExtensions(string $version = AppInfo::VERSION): array
    {
        $file = $this->paths->root . 'install/obsolete_extensions.list';
        $merged_extensions = [];

        if (file_exists($file) and ($obsolete_ext = file($file, FILE_IGNORE_NEW_LINES)) !== false) {
            foreach ($obsolete_ext as $ext) {
                if (preg_match('/^(\d+) ?: ?(.*?)$/', $ext, $matches)) {
                    $merged_extensions[$matches[1]] = $matches[2];
                }
            }
        }
        return $merged_extensions;
    }

    /**
     * Sort functions
     */
    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extensionRevisionCompare(array $a, array $b): int
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
    public function extensionNameCompare(array $a, array $b): int
    {
        $na = $a['extension_name'] ?? null;
        $nb = $b['extension_name'] ?? null;
        return strcmp(strtolower(is_scalar($na) ? (string) $na : ''), strtolower(is_scalar($nb) ? (string) $nb : ''));
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extensionAuthorCompare(array $a, array $b): int
    {
        $na = $a['author_name'] ?? null;
        $nb = $b['author_name'] ?? null;
        $r = strcasecmp(is_scalar($na) ? (string) $na : '', is_scalar($nb) ? (string) $nb : '');
        if ($r == 0) {
            return $this->extensionNameCompare($a, $b);
        } else {
            return $r;
        }
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function pluginAuthorCompare(array $a, array $b): int
    {
        $na = $a['author'] ?? null;
        $nb = $b['author'] ?? null;
        $r = strcasecmp(is_scalar($na) ? (string) $na : '', is_scalar($nb) ? (string) $nb : '');
        if ($r == 0) {
            return $this->htmlService->nameCompare($a, $b);
        } else {
            return $r;
        }
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function extensionDownloadsCompare(array $a, array $b): int
    {
        if ($a['extension_nb_downloads'] < $b['extension_nb_downloads']) {
            return 1;
        } else {
            return -1;
        }
    }

    public function sortPluginsByState(): void
    {
        uasort($this->fs_plugins, $this->htmlService->nameCompare(...));

        $active_plugins = [];
        $inactive_plugins = [];
        $not_installed = [];

        foreach ($this->fs_plugins as $plugin_id => $plugin) {
            if (isset($this->db_plugins_by_id[$plugin_id])) {
                $this->db_plugins_by_id[$plugin_id]->state === 'active' ?
                  $active_plugins[$plugin_id] = $plugin : $inactive_plugins[$plugin_id] = $plugin;
            } else {
                $not_installed[$plugin_id] = $plugin;
            }
        }
        $this->fs_plugins = $active_plugins + $inactive_plugins + $not_installed;
    }
}
