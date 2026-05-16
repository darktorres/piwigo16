<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ZipExtractor;
use Piwigo\Event\Lifecycle\ThemeActivateErrors;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangService;
use Piwigo\Theme\ThemeDependencyException;
use Piwigo\Theme\ThemeRegistry;
use Piwigo\Theme\ThemeRepository;
use Piwigo\Theme\ThemeValidationException;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use Psr\EventDispatcher\EventDispatcherInterface;

final class Themes
{
    /** @var array<string, array<string,mixed>> */
    public array $fs_themes = [];
    /** @var array<string, array<string,mixed>> */
    public array $db_themes_by_id = [];
    /** @var array<int|string, array<mixed>> */
    public array $server_themes = [];

    /**
     * Initialize $fs_themes and $db_themes_by_id
     */
    public function __construct(
        private readonly AdminService $adminService,
        private readonly ConfigService $configService,
        private readonly HtmlService $htmlService,
        private readonly LangService $langService,
        private readonly PreferencesService $preferencesService,
        private readonly ThemeRegistry $themeRegistry,
        private readonly ThemeRepository $themeRepository,
        private readonly UrlGenerator $urlGenerator,
        private readonly UserService $userService,
        private readonly ActivityLogger $activityLogger,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
        $this->getFsThemes();

        foreach ($this->getDbThemes() as $db_theme) {
            if (isset($db_theme['id'])) {
                $themeIdVal = $db_theme['id'];
                $this->db_themes_by_id[is_string($themeIdVal) ? $themeIdVal : ''] = $db_theme;
            }
        }
    }

    /**
     * Perform requested actions
     * @return list<mixed>
     */
    public function performAction(string $action, string $theme_id): array
    {
        if (!Config::enableExtensionsInstall() and 'delete' == $action) {
            die('Piwigo extensions install/update/delete system is disabled');
        }

        if (isset($this->db_themes_by_id[$theme_id])) {
            $crt_db_theme = $this->db_themes_by_id[$theme_id];
        }

        /** @var list<mixed> $errors */
        $errors = [];
        $activity_details = ['theme_id' => $theme_id];

        switch ($action) {
            case 'activate':
                if (isset($crt_db_theme)) {
                    // the theme is already active
                    break;
                }

                if ('_base' == $theme_id) {
                    // you can't activate the "_base" theme
                    break;
                }

                $missing_parent = $this->missingParentTheme($theme_id);
                if (isset($missing_parent)) {
                    $errors[] = Lang::t(
                        'Impossible to activate this theme, the parent theme is missing: %s',
                        $missing_parent
                    );

                    break;
                }

                if ($this->fs_themes[$theme_id]['mobile']
                    and !empty(Config::mobilTheme())
                    and Config::mobilTheme() != $theme_id) {
                    $errors[] = Lang::t('You can activate only one mobile theme.');
                    break;
                }

                try {
                    $this->themeRegistry->activate($theme_id);
                } catch (ThemeValidationException | ThemeDependencyException $e) {
                    $errors[] = $e->getMessage();
                }

                $activateErrorsEvent = new ThemeActivateErrors($errors);
                $this->dispatcher->dispatch($activateErrorsEvent);
                $errors = $activateErrorsEvent->errors;

                if (empty($errors)) {
                    $tvRaw = $this->fs_themes[$theme_id]['version'] ?? null;
                    $activity_details['version'] = is_scalar($tvRaw) ? (string) $tvRaw : '';

                    if ($this->fs_themes[$theme_id]['mobile']) {
                        $this->configService->confUpdateParam('mobile_theme', $theme_id);
                    }
                }
                break;

            case 'deactivate':
                if (!isset($crt_db_theme)) {
                    // the theme is already inactive
                    break;
                }

                // you can't deactivate the last theme
                if (count($this->db_themes_by_id) <= 1) {
                    $errors[] = Lang::t('Impossible to deactivate this theme, you need at least one theme.');
                    break;
                }

                if ($theme_id == $this->userService->getDefaultTheme()) {
                    $themeRepo = $this->themeRepository;
                    $new_theme = $themeRepo->findAnyOtherThemeId($theme_id) ?? '_base';
                    $this->setDefaultTheme($new_theme);
                }

                $this->themeRegistry->deactivate($theme_id);

                if ($this->fs_themes[$theme_id]['mobile']) {
                    $this->configService->confUpdateParam('mobile_theme', '');
                }
                break;

            case 'delete':
                if (!empty($crt_db_theme)) {
                    $errors[] = 'CANNOT DELETE - THEME IS INSTALLED';
                    break;
                }
                if (!isset($this->fs_themes[$theme_id])) {
                    // nothing to do here
                    break;
                }

                $children = $this->getChildrenThemes($theme_id);
                if (count($children) > 0) {
                    $errors[] = Lang::t(
                        'Impossible to delete this theme. Other themes depends on it: %s',
                        implode(', ', $children)
                    );
                    break;
                }

                try {
                    $this->themeRegistry->uninstall($theme_id);
                } catch (ThemeValidationException $e) {
                    $errors[] = $e->getMessage();
                }

                $this->adminService->deltree(Config::themesPath().$theme_id, Config::themesPath() . 'trash');
                break;

            case 'set_default':
                // first we need to know which users are using the current default theme
                $this->setDefaultTheme($theme_id);
                break;
        }

        $this->activityLogger->log(new ActivityEvent(ActivityObject::System, ActivitySystem::Theme, $action, $activity_details));

        return array_values($errors);
    }

    public function missingParentTheme(string $theme_id): ?string
    {
        if (!isset($this->fs_themes[$theme_id]['parent'])) {
            return null;
        }

        $parent = $this->fs_themes[$theme_id]['parent'];
        $parent = is_scalar($parent) ? (string) $parent : '';

        if ('_base' == $parent) {
            return null;
        }

        if (!isset($this->fs_themes[$parent])) {
            return $parent;
        }

        return $this->missingParentTheme($parent);
    }

    /**
     * @return string[]
     */
    public function getChildrenThemes(string $theme_id): array
    {
        $children = [];

        foreach ($this->fs_themes as $test_child) {
            if (isset($test_child['parent']) and $test_child['parent'] == $theme_id) {
                $cn = $test_child['name'] ?? null;
                $children[] = is_scalar($cn) ? (string) $cn : '';
            }
        }

        return $children;
    }

    public function setDefaultTheme(string $theme_id): void
    {
        // first we need to know which users are using the current default theme
        $default_theme = $this->userService->getDefaultTheme();

        $themeRepo = $this->themeRepository;
        $user_ids = array_unique(
            array_merge(
                $themeRepo->findUsersByTheme($default_theme),
                [Config::guestId(), Config::defaultUserId()]
            )
        );

        // $user_ids can't be empty, at least the default user has the default theme
        $themeRepo->setThemeForUsers($user_ids, $theme_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDbThemes(?string $id = ''): array
    {
        return $this->themeRepository->findAll($id);
    }

    /**
     * Scan `themes/` for installable themes.
     *
     * Reads each directory's `theme.json` manifest (the format ThemeRegistry
     * validates against `docs/schemas/theme.schema.json`). The legacy
     * regex-parsing of `themeconf.inc.php` headers was retired in B14e —
     * all bundled themes ship `theme.json`, and v17.0 PEM-style themes
     * also use the new manifest format.
     *
     * Per-theme fields populated for the admin listing:
     *  - `id`, `name`, `version`, `parent`, `use_standard_pages`
     *    sourced from `theme.json`.
     *  - `description` overridden by `description.txt` translation file
     *    when present (lets translators ship a localized blurb without
     *    editing the manifest).
     *  - `screenshot` / `admin_uri` derived from filesystem checks.
     */
    public function getFsThemes(): void
    {
        $dir = opendir(Config::themesPath());
        if ($dir === false) {
            return;
        }

        while ($file = readdir($dir)) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = Config::themesPath() . $file;
            if (!is_dir($path) || preg_match('/^[a-zA-Z0-9-_]+$/', $file) === 0) {
                continue;
            }
            $manifestPath = $path . '/theme.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $raw = file_get_contents($manifestPath);
            if ($raw === false) {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($decoded)) {
                continue;
            }

            $theme = [
                'id'          => $file,
                'name'        => is_string($decoded['name'] ?? null) ? $decoded['name'] : $file,
                'version'     => is_string($decoded['version'] ?? null) ? $decoded['version'] : '0',
                'uri'         => is_string($decoded['homepage'] ?? null) ? $decoded['homepage'] : '',
                'description' => '',
                'author'      => is_string($decoded['author'] ?? null) ? $decoded['author'] : '',
                'mobile'      => false,
            ];
            if (is_string($decoded['parent'] ?? null)) {
                $theme['parent'] = $decoded['parent'];
            }
            if (isset($decoded['useStandardPages']) && is_bool($decoded['useStandardPages'])) {
                $theme['use_standard_pages'] = $decoded['useStandardPages'];
            }

            // description.txt (localized blurb) wins over any prose in the manifest.
            if (is_string($desc = $this->langService->loadLanguage('description.txt', $path . '/', ['return' => true]))) {
                $theme['description'] = trim($desc);
            }

            // screenshot fallback when the theme didn't ship one.
            $screenshot_path = $path . '/screenshot.png';
            if (file_exists($screenshot_path)) {
                $theme['screenshot'] = $screenshot_path;
            } else {
                $admin_theme = $this->preferencesService->userprefsGetParam('admin_theme', 'dark');
                $admin_theme = is_scalar($admin_theme) ? (string) $admin_theme : 'dark';
                $theme['screenshot'] = PHPWG_ROOT_PATH . 'themes/admin/' . $admin_theme . '/images/missing_screenshot.png';
            }

            $admin_file = $path . '/admin/admin.inc.php';
            if (file_exists($admin_file)) {
                $theme['admin_uri'] = $this->urlGenerator->admin('theme') . '&theme=' . $file;
            }

            // IMPORTANT SECURITY ! escape user-supplied strings before the template renders them.
            $theme = array_map(fn (bool|string $v): string|bool => is_string($v) ? htmlspecialchars($v) : $v, $theme);
            $this->fs_themes[$file] = $theme;
        }
        closedir($dir);
    }

    /**
     * Sort fs_themes
     */
    public function sortFsThemes(string $order = 'name'): void
    {
        switch ($order) {
            case 'name':
                uasort($this->fs_themes, $this->htmlService->nameCompare(...));
                break;
            case 'status':
                $this->sortThemesByState();
                break;
            case 'author':
                uasort($this->fs_themes, $this->themeAuthorCompare(...));
                break;
            case 'id':
                uksort($this->fs_themes, strcasecmp(...));
                break;
        }
    }

    /**
     * Retrieve PEM server datas to $server_themes
     */
    public function getServerThemes(bool $new = false): bool
    {
        $get_data = [
          'category_id' => Config::pemThemesCategory(),
          'format' => 'php',
        ];

        // Retrieve PEM versions
        $version = AppInfo::VERSION;
        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php';
        $result = '';
        if ($this->adminService->fetchRemote($url, $result, $get_data) and is_string($result) and $pem_versions = StringUtil::safeUnserialize($result)) {
            if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $pv0 = $pem_versions[0] ?? null;
                $pv0name = is_array($pv0) && isset($pv0['name']) ? $pv0['name'] : null;
                $version = is_scalar($pv0name) ? (string) $pv0name : $version;
            }
            $branch = AppInfo::branchFromVersion($version);
            foreach ($pem_versions as $pem_version) {
                if (!is_array($pem_version) || !isset($pem_version['name'], $pem_version['id'])) {
                    continue;
                }
                $pvName = $pem_version['name'];
                $pvId = $pem_version['id'];
                if (str_starts_with(is_scalar($pvName) ? (string) $pvName : '', $branch)) {
                    $versions_to_check[] = is_scalar($pvId) ? (string) $pvId : '';
                }
            }
        }
        if (empty($versions_to_check)) {
            return false;
        }

        // Themes to check
        $themes_to_check = [];
        foreach ($this->fs_themes as $fs_theme) {
            if (isset($fs_theme['extension'])) {
                $themes_to_check[] = is_string($fs_theme['extension']) ? $fs_theme['extension'] : '';
            }
        }

        // Retrieve PEM themes infos
        $url = PEM_URL . '/api/get_revision_list-next.php';
        $get_data = array_merge(
            $get_data,
            [
      'last_revision_only' => 'true',
      'version' => implode(',', $versions_to_check),
      'lang' => substr(CurrentUser::get()->language, 0, 2),
      'get_nb_downloads' => 'true',
      ]
        );

        if (!empty($themes_to_check)) {
            if ($new) {
                $get_data['extension_exclude'] = implode(',', $themes_to_check);
            } else {
                $get_data['extension_include'] = implode(',', $themes_to_check);
            }
        }
        if ($this->adminService->fetchRemote($url, $result, $get_data) && is_string($result)) {
            $pem_themes = StringUtil::safeUnserialize($result);
            if ($pem_themes === []) {
                return false;
            }
            foreach ($pem_themes as $theme) {
                if (is_array($theme) && isset($theme['extension_id']) && (is_string($theme['extension_id']) || is_int($theme['extension_id']))) {
                    $this->server_themes[$theme['extension_id']] = $theme;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Sort $server_themes
     */
    public function sortServerThemes(string $order = 'date'): void
    {
        switch ($order) {
            case 'date':
                krsort($this->server_themes);
                break;
            case 'revision':
                usort($this->server_themes, fn (array $a, array $b): int => $this->extensionRevisionCompare($a, $b));
                break;
            case 'name':
                uasort($this->server_themes, fn (array $a, array $b): int => $this->extensionNameCompare($a, $b));
                break;
            case 'author':
                uasort($this->server_themes, fn (array $a, array $b): int => $this->extensionAuthorCompare($a, $b));
                break;
            case 'downloads':
                usort($this->server_themes, fn (array $a, array $b): int => $this->extensionDownloadsCompare($a, $b));
                break;
        }
    }

    /**
     * Extract theme files from archive
     *
     */
    public function extractThemeFiles(string $action, string $revision, string $dest, ?string &$theme_id = null): string
    {
        $logger = LoggerRegistry::current();

        if (($archive = tempnam(Config::themesPath(), 'zip')) !== false) {
            $url = PEM_URL . '/download.php';
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
                            // theme.json — track the shallowest path so a stray
                            // theme.json deeper in the archive doesn't win.
                            if (basename($filename) == 'theme.json'
                              and ($manifest_filepath === null
                              or strlen($filename) < strlen($manifest_filepath))) {
                                $manifest_filepath = $filename;
                            }
                        }

                        $logger->debug(__FUNCTION__.', $manifest_filepath = '.(string) $manifest_filepath);

                        if (isset($manifest_filepath)) {
                            $root = dirname($manifest_filepath); // theme root path in archive
                            if ($action == 'upgrade') {
                                $theme_id = $dest;
                            } else {
                                $theme_id = ($root == '.' ? 'extension_' . $dest : basename($root));
                            }
                            $extract_path = Config::themesPath() . $theme_id;
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
                                    // Refresh ThemeRegistry so the freshly-extracted
                                    // theme.json is validated immediately. A missing
                                    // manifest after reload means the ZIP shipped a
                                    // malformed file — surface that as a status.
                                    $this->themeRegistry->reload();
                                    if ($this->themeRegistry->getManifest($theme_id) === null) {
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
                                            $this->adminService->deltree($path, Config::themesPath() . 'trash');
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
    public function themeAuthorCompare(array $a, array $b): int
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

    public function sortThemesByState(): void
    {
        uasort($this->fs_themes, $this->htmlService->nameCompare(...));

        $active_themes = [];
        $inactive_themes = [];
        $not_installed = [];

        foreach ($this->fs_themes as $theme_id => $theme) {
            if (isset($this->db_themes_by_id[$theme_id])) {
                $this->db_themes_by_id[$theme_id]['state'] == 'active' ?
                  $active_themes[$theme_id] = $theme : $inactive_themes[$theme_id] = $theme;
            } else {
                $not_installed[$theme_id] = $theme;
            }
        }
        $this->fs_themes = $active_themes + $inactive_themes + $not_installed;
    }

}
