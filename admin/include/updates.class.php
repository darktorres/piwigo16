<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

include_once PHPWG_ROOT_PATH . 'include/functions.inc.php';

class updates
{
    /**
     * @var string[]
     */
    public $types = [];

    public $plugins;

    public $themes;

    public $languages;

    public $missing = [];

    /**
     * @var string[]
     */
    public $default_plugins = [];

    /**
     * @var string[]
     */
    public $default_themes = [];

    public $default_languages = [];

    public $merged_extensions = [];

    public $merged_extension_url = 'https://upstream.example.invalid/merged_extensions.txt';

    public function __construct($page = 'updates')
    {
        $this->types = ['plugins', 'themes', 'languages'];

        if (in_array($page, $this->types)) {
            $this->types = [$page];
        }
        $this->default_themes = ['modus', 'elegant', 'smartpocket'];
        $this->default_plugins = ['AdminTools', 'TakeATour', 'language_switch', 'LocalFilesEditor'];

        foreach ($this->types as $type) {
            include_once PHPWG_ROOT_PATH . 'admin/include/' . $type . '.class.php';
            $this->{$type} = new $type();
        }
    }

    public static function check_piwigo_upgrade(): void
    {
        $_SESSION['need_update' . PHPWG_VERSION] = null;

        if (preg_match('/(\d+\.\d+)\.(\d+)/', PHPWG_VERSION, $matches)
          and @fetchRemote(PHPWG_URL . '/download/all_versions.php?rand=' . md5(uniqid((string) mt_rand(), true)), $result)) {
            $all_versions = @explode("\n", $result);
            $new_version = trim($all_versions[0]);
            $_SESSION['need_update' . PHPWG_VERSION] = version_compare(PHPWG_VERSION, $new_version, '<');
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
     */
    public function get_piwigo_new_versions(): array
    {
        global $conf;

        $new_versions = [
            'piwigo.org-checked' => false,
            'is_dev' => true,
        ];

        [$env, $build_version] = get_container_info();
        if (preg_match('/^(\d+\.\d+)\.(\d+)$/', PHPWG_VERSION)) {
            $new_versions['is_dev'] = false;
            $actual_branch = get_branch_from_version(
                ($env === 'Official')
        ? substr((string) $build_version, 0, -1)
        : PHPWG_VERSION
            );

            $url = PHPWG_URL . '/download/all_versions.php';
            $url .= '?rand=' . md5(uniqid((string) mt_rand(), true)); // Avoid server cache
            $url .= ($env === 'Official') ? '&docker' : '&show_requirements'; // Check docker version if in container
            $url .= '&origin_hash=' . sha1($conf['secret_key'] . get_absolute_root_url());

            if (@fetchRemote($url, $result)) {
                $all_versions = explode("\n", $result);
                $new_versions['piwigo.org-checked'] = true;
                $last_version = trim($all_versions[0]);
                if ($env === 'Official') {
                    // Check if build_version is lower than the latest version
                    if ($this->container_version_compare($build_version, $last_version) == '-1') {
                        $last_branch = get_branch_from_version(substr($last_version, 0, -1));
                        if ($last_branch == $actual_branch) {
                            $new_versions['minor'] = $last_version;
                        } else {
                            $new_versions['major'] = $last_version;
                            foreach ($all_versions as $version) {
                                $branch = get_branch_from_version(substr($version, 0, -1));
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

                    if (version_compare(PHPWG_VERSION, $last_version_number, '<')) {
                        $last_branch = get_branch_from_version($last_version_number);

                        if ($last_branch == $actual_branch) {
                            $new_versions['minor'] = $last_version_number;
                            $new_versions['minor_php'] = $last_version_php;
                        } else {
                            $new_versions['major'] = $last_version_number;
                            $new_versions['major_php'] = $last_version_php;

                            // Check if new version exists in same branch
                            foreach ($all_versions as $version) {
                                [$version_number, $version_php] = explode('/', trim($version));
                                $branch = get_branch_from_version($version_number);

                                if ($branch == $actual_branch) {
                                    if (version_compare(PHPWG_VERSION, $version_number, '<')) {
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
        global $conf;

        if (! pwg_is_dbconf_writeable()) {
            return;
        }

        $new_versions = $this->get_piwigo_new_versions();
        conf_update_param('update_notify_last_check', date('c'));

        if ($new_versions['is_dev']) {
            return;
        }

        $new_versions_string = join(
            ' & ',
            array_intersect_key(
                $new_versions,
                array_fill_keys(['minor', 'major'], 1)
            )
        );

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
            $conf['update_notify_last_notification'] = safe_unserialize($conf['update_notify_last_notification']);
            $last_notification = $conf['update_notify_last_notification']['notified_on'];

            if ($new_versions_string != $conf['update_notify_last_notification']['version']) {
                $notify = true;
            } elseif (
                $conf['update_notify_reminder_period'] > 0
                and strtotime((string) $last_notification) < strtotime($conf['update_notify_reminder_period'] . ' seconds ago')
            ) {
                $notify = true;
            }
        }

        if ($notify) {
            // send email
            include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

            switch_lang_to(get_default_language());

            $content = l10n('Hello,');
            $content .= "\n\n" . l10n(
                'Time has come to update your Piwigo with version %s, go to %s',
                $new_versions_string,
                get_absolute_root_url() . 'admin.php?page=updates'
            );
            $content .= "\n\n" . l10n('It only takes a few clicks.');
            $content .= "\n\n" . l10n('Running on an up-to-date Piwigo is important for security.');

            pwg_mail_admins(
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

            switch_lang_back();

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

    public function get_server_extensions($version = PHPWG_VERSION): bool
    {
        global $user;

        $get_data = [
            'format' => 'php',
        ];

        // Retrieve PEM versions
        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php';
        if (fetchRemote($url, $result, $get_data) and $pem_versions = @unserialize($result)) {
            if (! preg_match('/^\d+\.\d+\.\d+$/', (string) $version)) {
                $version = $pem_versions[0]['name'];
            }
            $branch = get_branch_from_version($version);
            foreach ($pem_versions as $pem_version) {
                if (str_starts_with((string) $pem_version['name'], $branch)) {
                    $versions_to_check[] = $pem_version['id'];
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
            foreach ($this->{$type}->{$fs} as $ext) {
                if (isset($ext['extension'])) {
                    $ext_to_check[$ext['extension']] = $type;
                }
            }
        }

        // Retrieve PEM plugins infos
        $url = PEM_URL . '/api/get_revision_list.php';
        $get_data = array_merge(
            $get_data,
            [
                'last_revision_only' => 'true',
                'version' => implode(',', $versions_to_check),
                'lang' => substr((string) $user['language'], 0, 2),
                'get_nb_downloads' => 'true',
            ]
        );

        $post_data = [];
        if (! empty($ext_to_check)) {
            $post_data['extension_include'] = implode(',', array_keys($ext_to_check));
        }

        if (fetchRemote($url, $result, $get_data, $post_data)) {
            $pem_exts = @unserialize($result);
            if (! is_array($pem_exts)) {
                return false;
            }

            $servers = [];

            foreach ($pem_exts as $ext) {
                if (isset($ext_to_check[$ext['extension_id']])) {
                    $type = $ext_to_check[$ext['extension_id']];

                    if (! isset($servers[$type])) {
                        $servers[$type] = [];
                    }

                    $servers[$type][$ext['extension_id']] = $ext;

                    unset($ext_to_check[$ext['extension_id']]);
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
    public function check_extensions()
    {
        global $conf;

        if (! $this->get_server_extensions()) {
            return false;
        }

        $_SESSION['extensions_need_update'] = [];

        foreach ($this->types as $type) {
            $fs = 'fs_' . $type;
            $server = 'server_' . $type;
            $server_ext = $this->{$type}->{$server};
            $fs_ext = $this->{$type}->{$fs};

            $ignore_list = [];
            $need_upgrade = [];

            foreach ($fs_ext as $ext_id => $fs_ext) {
                if (isset($fs_ext['extension']) and isset($server_ext[$fs_ext['extension']])) {
                    $ext_info = $server_ext[$fs_ext['extension']];

                    if (! safe_version_compare($fs_ext['version'], $ext_info['revision_name'], '>=')) {
                        if (in_array($ext_id, $conf['updates_ignored'][$type])) {
                            $ignore_list[] = $ext_id;
                        } else {
                            $_SESSION['extensions_need_update'][$type][$ext_id] = $ext_info['revision_name'];
                        }
                    }
                }
            }
            $conf['updates_ignored'][$type] = $ignore_list;
        }
        conf_update_param('updates_ignored', pwg_db_real_escape_string(serialize($conf['updates_ignored'])));
    }

    // Check if extension have been upgraded since last check
    public function check_updated_extensions(): void
    {
        foreach ($this->types as $type) {
            if (! empty($_SESSION['extensions_need_update'][$type])) {
                $fs = 'fs_' . $type;
                foreach ($this->{$type}->{$fs} as $ext_id => $fs_ext) {
                    if (isset($_SESSION['extensions_need_update'][$type][$ext_id])
                      and safe_version_compare($fs_ext['version'], $_SESSION['extensions_need_update'][$type][$ext_id], '>=')) {
                        // Extension have been upgraded
                        $this->check_extensions();
                        break;
                    }
                }
            }
        }
    }

    public function check_missing_extensions($missing): void
    {
        foreach ($missing as $id => $type) {
            $fs = 'fs_' . $type;
            $default = 'default_' . $type;
            foreach ($this->{$type}->{$fs} as $ext_id => $ext) {
                if (isset($ext['extension']) and $id == $ext['extension']
                  and ! in_array($ext_id, $this->{$default})
                  and ! in_array($ext['extension'], $this->merged_extensions)) {
                    $this->missing[$type][] = $ext;
                    break;
                }
            }
        }
    }

    public function get_merged_extensions($version): void
    {
        if (fetchRemote($this->merged_extension_url, $result)) {
            $rows = explode("\n", $result);
            foreach ($rows as $row) {
                if (preg_match('/^(\d+\.\d+): *(.*)$/', $row, $match)) {
                    if (version_compare($version, $match[1], '>=')) {
                        $extensions = explode(',', trim($match[2]));
                        $this->merged_extensions = array_merge($this->merged_extensions, $extensions);
                    }
                }
            }
        }
    }

    public static function process_obsolete_list($file): void
    {
        if (file_exists(PHPWG_ROOT_PATH . $file)
          and $old_files = file(PHPWG_ROOT_PATH . $file, FILE_IGNORE_NEW_LINES)) {
            $old_files[] = $file;
            foreach ($old_files as $old_file) {
                $path = PHPWG_ROOT_PATH . $old_file;
                if (is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    deltree($path, PHPWG_ROOT_PATH . '_trash');
                }
            }
        }
    }

    public static function upgrade_to($upgrade_to, &$step, $check_current_version = true): void
    {
        global $page, $conf, $template;

        if ($check_current_version and ! version_compare($upgrade_to, PHPWG_VERSION, '>')) {
            // TODO why redirect to a plugin page? maybe a remaining code from when
            // the update system was provided as a plugin?
            redirect(get_root_url() . 'admin.php?page=plugin-' . basename(__DIR__));
        }

        $obsolete_list = null;

        if ($step == 2) {
            $code = get_branch_from_version(PHPWG_VERSION) . '.x_to_' . $upgrade_to;
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
            $path = PHPWG_ROOT_PATH . $conf['data_location'] . 'update';
            $filename = $path . '/' . $code . '.zip';
            @mkgetdir($path);

            $chunk_num = 0;
            $end = false;
            $zip = @fopen($filename, 'w');

            while (! $end) {
                $chunk_num++;
                if (@fetchRemote(PHPWG_URL . '/download/dlcounter.php?code=' . $dl_code . '&chunk_num=' . $chunk_num, $result)
                  and $input = @unserialize($result)) {
                    if ($input['remaining'] == 0) {
                        $end = true;
                    }
                    @fwrite($zip, base64_decode((string) $input['data']));
                } else {
                    $end = true;
                }
            }
            @fclose($zip);

            if (@filesize($filename)) {
                include_once PHPWG_ROOT_PATH . 'admin/include/functions_zip.inc.php';
                if ($result = zip_extract($filename, PHPWG_ROOT_PATH, $remove_path, 0755)) {
                    // Check if all files were extracted
                    $error = '';
                    foreach ($result as $extract) {
                        if (! in_array($extract['status'], ['ok', 'filtered', 'already_a_directory'])) {
                            // Try to change chmod and extract
                            if (@chmod(PHPWG_ROOT_PATH . $extract['filename'], 0777)
                              and ($res = zip_extract(
                                  $filename,
                                  PHPWG_ROOT_PATH,
                                  $remove_path,
                                  0755,
                                  $remove_path . '/' . $extract['filename']
                              ))
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

                        deltree(PHPWG_ROOT_PATH . $conf['data_location'] . 'update');
                        invalidate_user_cache(true);
                        conf_update_param('piwigo_installed_version', $upgrade_to);
                        pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'update', [
                            'from_version' => PHPWG_VERSION,
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
                        file_put_contents(PHPWG_ROOT_PATH . $conf['data_location'] . 'update/log_error.txt', $error);

                        $page['errors'][] = l10n(
                            'An error has occured during extract. Please check files permissions of your piwigo installation.<br><a href="%s">Click here to show log error</a>.',
                            get_root_url() . $conf['data_location'] . 'update/log_error.txt'
                        );
                    }
                } else {
                    deltree(PHPWG_ROOT_PATH . $conf['data_location'] . 'update');
                    $page['errors'][] = l10n('An error has occured during upgrade.');
                }
            } else {
                $page['errors'][] = l10n('Piwigo cannot retrieve upgrade file from server');
            }
        }
    }

    // Compare version number with a letter suffix
    // Similar to version_compare with "<" sign
    public function container_version_compare($v1, $v2): bool|int|null
    {
        // Split 16.2.0d into "16.2.0" as semantic_ver and "d" as sub_ver
        $v1_semantic_ver = substr((string) $v1, 0, -1);
        $v1_sub_ver = substr((string) $v1, -1);
        $v2_semantic_ver = substr((string) $v2, 0, -1);
        $v2_sub_ver = substr((string) $v2, -1);

        $res = version_compare($v1_semantic_ver, $v2_semantic_ver);

        // Return for any
        if ($res === 0) {
            return strcmp($v1_sub_ver, $v2_sub_ver) < 0;
        }

        return $res;
    }
}
