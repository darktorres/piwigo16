<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Http\HttpClientService;
use Piwigo\Mail\MailService;
use Piwigo\Template\Template;

class updates
{
    /**
     * @var string[]
     */
    public $types = [];

    public ?plugins $plugins = null;

    public ?themes $themes = null;

    public ?languages $languages = null;

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    public $missing = [];

    /**
     * @var string[]
     */
    public $default_plugins = [];

    /**
     * @var string[]
     */
    public $default_themes = [];

    /**
     * @var string[]
     */
    public $default_languages = [];

    /**
     * @var string[]
     */
    public $merged_extensions = [];

    /**
     * @var string
     */
    public $merged_extension_url = 'https://upstream.example.invalid/merged_extensions.txt';

    public function __construct(string $page = 'updates')
    {
        $this->types = ['plugins', 'themes', 'languages'];

        if (in_array($page, $this->types)) {
            $this->types = [$page];
        }
        $this->default_themes = ['modus', 'elegant', 'smartpocket'];
        $this->default_plugins = ['AdminTools', 'TakeATour', 'language_switch', 'LocalFilesEditor'];

        foreach ($this->types as $type) {
            // Assigns directly to each named property (rather than a single
            // dynamic `$this->{$type} = match(...)`) so PHPStan can verify
            // each match arm against its own property's real type instead of
            // the union of all three -- $this->{$type} = match(...) would
            // otherwise require every property to accept
            // plugins|themes|languages.
            match ($type) {
                'plugins' => $this->plugins = new plugins(),
                'themes' => $this->themes = new themes(),
                // 'languages' is the only value that can still reach here:
                // $type is already restricted to plugins/themes/languages by
                // the literal array above (same pattern as
                // ws_functions/pwg.extensions.php's identical match()).
                default => $this->languages = new languages(),
            };
        }
    }

    public static function check_piwigo_upgrade(): void
    {
        $_SESSION['need_update' . AppInfo::VERSION] = null;

        if ((bool) preg_match('/(\d+\.\d+)\.(\d+)/', AppInfo::VERSION, $matches)
          and is_string($result = @HttpClientService::fetch(PHPWG_URL . '/download/all_versions.php?rand=' . md5(uniqid((string) mt_rand(), true))))) {
            $all_versions = @explode("\n", $result);
            $new_version = trim($all_versions[0]);
            $_SESSION['need_update' . AppInfo::VERSION] = version_compare(AppInfo::VERSION, $new_version, '<');
        }
    }

    /**
     * finds new versions of Piwigo on Piwigo.org.
     *
     * @since 2.9
     * @return array (
     *   'piwigo.org-checked' => has piwigo.org been checked?,
     *   'is_dev' => are we on a dev version?,
     *   'minor_version' => new minor version available,
     *   'major_version' => new major version available,
     * )
     * @return array<string, mixed>
     */
    public function get_piwigo_new_versions(): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $new_versions = [
            'piwigo.org-checked' => false,
            'is_dev' => true,
        ];

        [$env, $build_version] = \Piwigo\Core\ContainerDetector::detect();
        if ((bool) preg_match('/^(\d+\.\d+)\.(\d+)$/', AppInfo::VERSION)) {
            $new_versions['is_dev'] = false;
            $actual_branch = \Piwigo\Core\VersionHelper::getBranchFromVersion(($env === 'Official')
    ? substr((string) $build_version, 0, -1)
    : AppInfo::VERSION);

            $url = PHPWG_URL . '/download/all_versions.php';
            $url .= '?rand=' . md5(uniqid((string) mt_rand(), true)); // Avoid server cache
            $url .= ($env === 'Official') ? '&docker' : '&show_requirements'; // Check docker version if in container
            $secret_key_raw = $conf['secret_key'] ?? null;
            $secret_key = is_string($secret_key_raw) ? $secret_key_raw : '';
            $url .= '&origin_hash=' . sha1($secret_key . get_absolute_root_url());

            if (is_string($result = @HttpClientService::fetch($url))) {
                $all_versions = explode("\n", $result);
                $new_versions['piwigo.org-checked'] = true;
                $last_version = trim($all_versions[0]);
                if ($env === 'Official') {
                    // Check if build_version is lower than the latest version
                    if ($this->container_version_compare($build_version, $last_version) == '-1') {
                        $last_branch = \Piwigo\Core\VersionHelper::getBranchFromVersion(substr($last_version, 0, -1));
                        if ($last_branch == $actual_branch) {
                            $new_versions['minor'] = $last_version;
                        } else {
                            $new_versions['major'] = $last_version;
                            foreach ($all_versions as $version) {
                                $branch = \Piwigo\Core\VersionHelper::getBranchFromVersion(substr($version, 0, -1));
                                if ($branch == $actual_branch) {
                                    if ($this->container_version_compare($build_version, $version) == '-1') {
                                        $new_versions['minor'] = $version;
                                    }
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    [$last_version_number, $last_version_php] = explode('/', trim($all_versions[0]));

                    if (version_compare(AppInfo::VERSION, $last_version_number, '<')) {
                        $last_branch = \Piwigo\Core\VersionHelper::getBranchFromVersion($last_version_number);

                        if ($last_branch == $actual_branch) {
                            $new_versions['minor'] = $last_version_number;
                            $new_versions['minor_php'] = $last_version_php;
                        } else {
                            $new_versions['major'] = $last_version_number;
                            $new_versions['major_php'] = $last_version_php;

                            // Check if new version exists in same branch
                            foreach ($all_versions as $version) {
                                [$version_number, $version_php] = explode('/', trim($version));
                                $branch = \Piwigo\Core\VersionHelper::getBranchFromVersion($version_number);

                                if ($branch == $actual_branch) {
                                    if (version_compare(AppInfo::VERSION, $version_number, '<')) {
                                        $new_versions['minor'] = $version_number;
                                        $new_versions['minor_php'] = $version_php;
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $new_versions;
    }

    /**
     * Checks for new versions of Piwigo. Notify webmasters if new versions are available, but not too often, see
     * $conf['update_notify_reminder_period'] parameter.
     *
     * @since 2.9
     */
    public function notify_piwigo_new_versions(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! pwg_is_dbconf_writeable()) {
            return;
        }

        $new_versions = $this->get_piwigo_new_versions();
        conf_update_param('update_notify_last_check', date('c'));

        if ((bool) $new_versions['is_dev']) {
            return;
        }

        // $new_versions is array<string, mixed> per get_piwigo_new_versions()'s
        // return type, but 'minor'/'major' are only ever set to trimmed
        // version strings above — filter to be certain before join().
        $matching_new_versions = array_intersect_key(
            $new_versions,
            array_fill_keys(['minor', 'major'], 1)
        );
        $new_versions_string = join(' & ', array_filter($matching_new_versions, is_string(...)));

        if (empty($new_versions_string)) {
            return;
        }

        // In which case should we notify?
        // 1. never notified
        // 2. new versions
        // 3. no new versions but reminder needed

        $notify = false;
        if (! isset($conf['update_notify_last_notification'])) {
            $notify = true;
        } else {
            // safe_unserialize() returns mixed (unserialize() of an
            // untyped conf value) — narrow to the array{version,
            // notified_on} shape this key is always written as further
            // down in this method. safe_unserialize()'s own parameter
            // requires array<int|string, mixed>|string, so validate the
            // raw conf value's shape before passing it in.
            // isset() above already guarantees this offset exists.
            $last_notification_setting = $conf['update_notify_last_notification'];
            $last_notification_raw = (is_array($last_notification_setting) || is_string($last_notification_setting))
                ? \Piwigo\Core\ArrayHelper::safeUnserialize($last_notification_setting)
                : false;
            $last_notification_data = is_array($last_notification_raw) ? $last_notification_raw : [];
            $conf['update_notify_last_notification'] = $last_notification_data;
            $last_notification = $last_notification_data['notified_on'] ?? null;
            $last_notification_version = $last_notification_data['version'] ?? null;

            $reminder_period_raw = $conf['update_notify_reminder_period'] ?? null;
            $reminder_period = is_numeric($reminder_period_raw) ? (int) $reminder_period_raw : 0;

            if ($new_versions_string != $last_notification_version) {
                $notify = true;
            } elseif (
                $reminder_period > 0
                and is_string($last_notification)
                and strtotime($last_notification) < strtotime($reminder_period . ' seconds ago')
            ) {
                $notify = true;
            }
        }

        if ($notify) {
            // send email
            new MailService()
                ->switchLangTo((new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultLanguage());

            $content = l10n('Hello,');
            $content .= "\n\n" . l10n(
                'Time has come to update your Piwigo with version %s, go to %s',
                $new_versions_string,
                get_absolute_root_url() . 'admin.php?page=updates'
            );
            $content .= "\n\n" . l10n('It only takes a few clicks.');
            $content .= "\n\n" . l10n('Running on an up-to-date Piwigo is important for security.');

            new MailService()
                ->mailAdmins(
                    [
                        'subject' => l10n('Piwigo %s is available, please update', $new_versions_string),
                        'content' => $content,
                        'content_format' => 'text/plain',
                    ],
                    [
                        'filename' => 'notification_admin',
                    ],
                    false, // do not exclude current user
                    true // only webmasters
                );

            new MailService()
                ->switchLangBack();

            // save notify
            conf_update_param(
                'update_notify_last_notification',
                [
                    'version' => $new_versions_string,
                    'notified_on' => date('c'),
                ]
            );
        }
    }

    public function get_server_extensions(string $version = AppInfo::VERSION): bool
    {
        /** @var array<string, mixed> $user */
        global $user;

        // PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url']) in
        // one branch of include/common.inc.php, so PHPStan can't prove it's a
        // string across that file boundary — narrow it once here.
        $pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

        $get_data = [
            'format' => 'php',
        ];

        // Retrieve PEM versions
        $versions_to_check = [];
        $url = $pem_base_url . '/api/get_version_list.php';
        if (is_string($result = HttpClientService::fetch($url, $get_data)) and (bool) ($pem_versions = @unserialize($result))) {
            // unserialize() of a remote PEM response is genuinely untyped —
            // validate it's an array of arrays before indexing into it
            // below, rather than trusting the external payload (see the
            // identical narrowing in plugins.class.php::get_versions_to_check()).
            if (is_array($pem_versions)) {
                if (! (bool) preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                    $first_pem_version = $pem_versions[0] ?? null;
                    if (is_array($first_pem_version)) {
                        $first_pem_version_name = $first_pem_version['name'] ?? null;
                        if (is_string($first_pem_version_name)) {
                            $version = $first_pem_version_name;
                        }
                    }
                }
                $branch = \Piwigo\Core\VersionHelper::getBranchFromVersion($version);
                foreach ($pem_versions as $pem_version) {
                    if (! is_array($pem_version)) {
                        continue;
                    }
                    $pem_version_name = $pem_version['name'] ?? null;
                    if (is_string($pem_version_name) and str_starts_with($pem_version_name, $branch)) {
                        $pem_version_id = $pem_version['id'] ?? null;
                        if (is_scalar($pem_version_id)) {
                            $versions_to_check[] = (string) $pem_version_id;
                        }
                    }
                }
            }
        }
        if (empty($versions_to_check)) {
            return false;
        }

        // Extensions to check
        $ext_to_check = [];
        foreach ($this->types as $type) {
            $fs = 'fs_' . $type;
            // $this->{$type}->{$fs} is a dynamic property access (the class
            // named by $type is one of plugins/themes/languages, all of
            // which declare fs_plugins/fs_themes/fs_languages consistently
            // as array<string, array<string, mixed>>); PHPStan can't
            // resolve the dynamic property name, so narrow explicitly.
            /** @var array<string, array<string, mixed>> $fs_extensions */
            $fs_extensions = $this->{$type}->{$fs};
            foreach ($fs_extensions as $ext) {
                $extension_key = $ext['extension'] ?? null;
                if (is_string($extension_key) || is_int($extension_key)) {
                    $ext_to_check[$extension_key] = $type;
                }
            }
        }

        // Retrieve PEM plugins infos
        $url = $pem_base_url . '/api/get_revision_list.php';
        $get_data = array_merge(
            $get_data,
            [
                'last_revision_only' => 'true',
                'version' => implode(',', $versions_to_check),
                'lang' => substr(is_string($user['language']) ? $user['language'] : (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getDefaultLanguage(), 0, 2),
                'get_nb_downloads' => 'true',
            ]
        );

        $post_data = [];
        if (! empty($ext_to_check)) {
            $post_data['extension_include'] = implode(',', array_keys($ext_to_check));
        }

        if (is_string($result = HttpClientService::fetch($url, $get_data, $post_data))) {
            $pem_exts = @unserialize($result);
            if (! is_array($pem_exts)) {
                return false;
            }

            $servers = [];

            foreach ($pem_exts as $ext) {
                if (! is_array($ext)) {
                    continue;
                }
                $extension_id = $ext['extension_id'] ?? null;
                if (! is_string($extension_id) && ! is_int($extension_id)) {
                    continue;
                }

                if (isset($ext_to_check[$extension_id])) {
                    $type = $ext_to_check[$extension_id];

                    if (! isset($servers[$type])) {
                        $servers[$type] = [];
                    }

                    $servers[$type][$extension_id] = $ext;

                    unset($ext_to_check[$extension_id]);
                }
            }

            foreach ($servers as $server_type => $extension_list) {
                $server_string = 'server_' . $server_type;

                $this->{$server_type}->{$server_string} = $extension_list;
            }

            $this->check_missing_extensions($ext_to_check);
            return true;
        }
        return false;
    }

    // Check all extensions upgrades
    public function check_extensions(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! $this->get_server_extensions()) {
            return;
        }

        $_SESSION['extensions_need_update'] = [];

        $updates_ignored_raw = $conf['updates_ignored'] ?? null;
        $updates_ignored = is_array($updates_ignored_raw) ? $updates_ignored_raw : [];

        foreach ($this->types as $type) {
            $fs = 'fs_' . $type;
            $server = 'server_' . $type;
            // Dynamic property access on plugins/themes/languages -- see the
            // identical narrowing (and rationale) in get_server_extensions().
            /** @var array<int|string, array<string, mixed>> $server_ext */
            $server_ext = $this->{$type}->{$server};
            /** @var array<string, array<string, mixed>> $fs_extensions */
            $fs_extensions = $this->{$type}->{$fs};

            $ignore_list = [];
            $need_upgrade = [];

            $ignored_for_type_raw = $updates_ignored[$type] ?? null;
            $ignored_for_type = is_array($ignored_for_type_raw) ? $ignored_for_type_raw : [];

            foreach ($fs_extensions as $ext_id => $fs_ext) {
                $extension_key = $fs_ext['extension'] ?? null;
                if (! is_string($extension_key) && ! is_int($extension_key)) {
                    continue;
                }
                if (! isset($server_ext[$extension_key])) {
                    continue;
                }
                $ext_info = $server_ext[$extension_key];

                $fs_version_raw = $fs_ext['version'] ?? null;
                $fs_version = is_string($fs_version_raw) ? $fs_version_raw : '';
                $revision_name_raw = $ext_info['revision_name'] ?? null;
                $revision_name = is_string($revision_name_raw) ? $revision_name_raw : '';

                if (! (bool) \Piwigo\Core\VersionHelper::safeVersionCompare($fs_version, $revision_name, '>=')) {
                    if (in_array($ext_id, $ignored_for_type)) {
                        $ignore_list[] = $ext_id;
                    } else {
                        $_SESSION['extensions_need_update'][$type][$ext_id] = $revision_name;
                    }
                }
            }
            $updates_ignored[$type] = $ignore_list;
        }
        $conf['updates_ignored'] = $updates_ignored;
        conf_update_param('updates_ignored', pwg_db_real_escape_string(serialize($conf['updates_ignored'])));
    }

    // Check if extension have been upgraded since last check
    public function check_updated_extensions(): void
    {
        // $_SESSION is a superglobal with no known value type, so PHPStan
        // sees $_SESSION['extensions_need_update'] as mixed; narrow it once
        // here instead of re-reading the raw superglobal offset below.
        $extensions_need_update_raw = $_SESSION['extensions_need_update'] ?? null;
        $extensions_need_update = is_array($extensions_need_update_raw) ? $extensions_need_update_raw : [];

        foreach ($this->types as $type) {
            $type_updates_raw = $extensions_need_update[$type] ?? null;
            if (empty($type_updates_raw) || ! is_array($type_updates_raw)) {
                continue;
            }

            $fs = 'fs_' . $type;
            // Dynamic property access on plugins/themes/languages -- see the
            // identical narrowing (and rationale) in get_server_extensions().
            /** @var array<string, array<string, mixed>> $fs_extensions */
            $fs_extensions = $this->{$type}->{$fs};
            foreach ($fs_extensions as $ext_id => $fs_ext) {
                $needed_version = $type_updates_raw[$ext_id] ?? null;
                $fs_version_raw = $fs_ext['version'] ?? null;
                $fs_version = is_string($fs_version_raw) ? $fs_version_raw : '';
                if (isset($needed_version)
                  and is_string($needed_version)
                  and (bool) \Piwigo\Core\VersionHelper::safeVersionCompare($fs_version, $needed_version, '>=')) {
                    // Extension have been upgraded
                    $this->check_extensions();
                    break;
                }
            }
        }
    }

    /**
     * $missing is built by get_server_extensions() from extension
     * identifiers used as array keys; a numeric-looking identifier would be
     * coerced to an int key by PHP, so the true key type is int|string, not
     * string alone (single caller, verified in this file).
     *
     * @param array<int|string, string> $missing
     */
    public function check_missing_extensions(array $missing): void
    {
        foreach ($missing as $id => $type) {
            $fs = 'fs_' . $type;
            $default = 'default_' . $type;
            // Dynamic property access on plugins/themes/languages -- see the
            // identical narrowing (and rationale) in get_server_extensions().
            /** @var array<string, array<string, mixed>> $fs_extensions */
            $fs_extensions = $this->{$type}->{$fs};
            /** @var string[] $default_list */
            $default_list = $this->{$default};
            foreach ($fs_extensions as $ext_id => $ext) {
                if (isset($ext['extension']) and $id == $ext['extension']
                  and ! in_array($ext_id, $default_list)
                  and ! in_array($ext['extension'], $this->merged_extensions)) {
                    $this->missing[$type][] = $ext;
                    break;
                }
            }
        }
    }

    public function get_merged_extensions(string $version): void
    {
        if (is_string($result = HttpClientService::fetch($this->merged_extension_url))) {
            $rows = explode("\n", $result);
            foreach ($rows as $row) {
                if ((bool) preg_match('/^(\d+\.\d+): *(.*)$/', $row, $match)) {
                    if (version_compare($version, $match[1], '>=')) {
                        $extensions = explode(',', trim($match[2]));
                        $this->merged_extensions = array_merge($this->merged_extensions, $extensions);
                    }
                }
            }
        }
    }

    public static function process_obsolete_list(string $file): void
    {
        if (file_exists(PHPWG_ROOT_PATH . $file)
          and (bool) ($old_files = file(PHPWG_ROOT_PATH . $file, FILE_IGNORE_NEW_LINES))) {
            $old_files[] = $file;
            foreach ($old_files as $old_file) {
                $path = PHPWG_ROOT_PATH . $old_file;
                if (is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    FilesystemHelper::deltree($path, PHPWG_ROOT_PATH . '_trash');
                }
            }
        }
    }

    public static function upgrade_to(string $upgrade_to, int|string &$step, bool $check_current_version = true): void
    {
        /**
         * @var array<string, mixed> $page
         * @var array<string, mixed> $conf
         * @var Template $template
         */
        global $page, $conf, $template;

        // $page['errors']/$page['infos'] are always initialized to an array by
        // common.inc.php, but that isn't visible across the include() boundary
        // -- narrow them once here so the appends below type-check.
        $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];
        $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];

        $data_location_raw = $conf['data_location'] ?? null;
        $data_location = is_string($data_location_raw) ? $data_location_raw : '';

        if ($check_current_version and ! version_compare($upgrade_to, AppInfo::VERSION, '>')) {
            // TODO why redirect to a plugin page? maybe a remaining code from when
            // the update system was provided as a plugin?
            redirect(get_root_url() . 'admin.php?page=plugin-' . basename(__DIR__));
        }

        $obsolete_list = null;

        if ($step == 2) {
            $code = \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.x_to_' . $upgrade_to;
            $dl_code = str_replace(['.', '_'], '', $code);
            $remove_path = $code;
            // no longer try to delete files on a minor upgrade
            // $obsolete_list = 'obsolete.list';
        } else {
            $code = $upgrade_to;
            $dl_code = $code;
            $remove_path = version_compare($code, '2.0.8', '>=') ? 'piwigo' : 'piwigo-' . $code;
            $obsolete_list = PHPWG_ROOT_PATH . 'install/obsolete.list';
        }

        if (empty($page['errors'])) {
            $path = PHPWG_ROOT_PATH . $data_location . 'update';
            $filename = $path . '/' . $code . '.zip';
            @\Piwigo\Core\FilesystemHelper::mkgetdir($path);

            $chunk_num = 0;
            $end = false;
            $zip = @fopen($filename, 'w');

            while (! $end) {
                $chunk_num++;
                if (is_string($result = @HttpClientService::fetch(PHPWG_URL . '/download/dlcounter.php?code=' . $dl_code . '&chunk_num=' . $chunk_num))
                  and (bool) ($input = @unserialize($result))) {
                    // unserialize() of a remote dlcounter response is
                    // genuinely untyped — validate it's an array before
                    // indexing into it below.
                    if (is_array($input)) {
                        $remaining = $input['remaining'] ?? null;
                        if ($remaining == 0) {
                            $end = true;
                        }
                        if ($zip !== false) {
                            $chunk_data = $input['data'] ?? null;
                            if (is_string($chunk_data)) {
                                @fwrite($zip, base64_decode($chunk_data));
                            }
                        }
                    } else {
                        $end = true;
                    }
                } else {
                    $end = true;
                }
            }
            if ($zip !== false) {
                @fclose($zip);
            }

            if ((bool) @filesize($filename)) {
                $zip_extractor = new ZipExtractor();
                if (($result = $zip_extractor->extract($filename, PHPWG_ROOT_PATH, $remove_path, 0755)) !== null) {
                    // Check if all files were extracted
                    $error = '';
                    foreach ($result as $extract) {
                        if (! in_array($extract['status'], ['ok', 'filtered', 'already_a_directory'])) {
                            // Try to change chmod and extract
                            if (@chmod(PHPWG_ROOT_PATH . $extract['filename'], 0777)
                              and ($res = $zip_extractor->extract(
                                  $filename,
                                  PHPWG_ROOT_PATH,
                                  $remove_path,
                                  0755,
                                  $remove_path . '/' . $extract['filename']
                              )) !== null
                              and isset($res[0]['status'])
                              and $res[0]['status'] == 'ok') {
                                continue;
                            } else {
                                $error .= $extract['filename'] . ': ' . $extract['status'] . "\n";
                            }
                        }
                    }

                    if (empty($error)) {
                        if (! empty($obsolete_list)) {
                            self::process_obsolete_list($obsolete_list);
                        }

                        FilesystemHelper::deltree(PHPWG_ROOT_PATH . $data_location . 'update');
                        invalidate_user_cache(true);
                        conf_update_param('piwigo_installed_version', $upgrade_to);
                        (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('system', ActivitySystem::Core, 'update', [
                            'from_version' => AppInfo::VERSION,
                            'to_version' => $upgrade_to,
                        ]);

                        if ($step == 2) {
                            // only delete compiled templates on minor update. Doing this on
                            // a major update might even encounter fatal error if Smarty
                            // changes. Anyway, a compiled template purge will be performed
                            // by upgrade.php
                            $template->delete_compiled_templates();
                            conf_delete_param('fs_quick_check_last_check');

                            $page['infos'][] = l10n('Update Complete');
                            $page['infos'][] = $upgrade_to;
                            $page['updated_version'] = $upgrade_to;
                            $step = -1;
                        } else {
                            redirect(PHPWG_ROOT_PATH . 'upgrade.php?now=');
                        }
                    } else {
                        file_put_contents(PHPWG_ROOT_PATH . $data_location . 'update/log_error.txt', $error);

                        $page['errors'][] = l10n(
                            'An error has occured during extract. Please check files permissions of your piwigo installation.<br><a href="%s">Click here to show log error</a>.',
                            get_root_url() . $data_location . 'update/log_error.txt'
                        );
                    }
                } else {
                    FilesystemHelper::deltree(PHPWG_ROOT_PATH . $data_location . 'update');
                    $page['errors'][] = l10n('An error has occured during upgrade.');
                }
            } else {
                $page['errors'][] = l10n('Piwigo cannot retrieve upgrade file from server');
            }
        }
    }

    // Compare version number with a letter suffix
    // Similar to version_compare with "<" sign
    public function container_version_compare(?string $v1, string $v2): bool|int|null
    {
        // Split 16.2.0d into "16.2.0" as semantic_ver and "d" as sub_ver
        $v1_semantic_ver = substr((string) $v1, 0, -1);
        $v1_sub_ver = substr((string) $v1, -1);
        $v2_semantic_ver = substr($v2, 0, -1);
        $v2_sub_ver = substr($v2, -1);

        $res = version_compare($v1_semantic_ver, $v2_semantic_ver);

        // Return for any
        if ($res === 0) {
            return strcmp($v1_sub_ver, $v2_sub_ver) < 0;
        }

        return $res;
    }
}
