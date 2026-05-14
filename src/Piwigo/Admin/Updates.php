<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Mail\MailService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

final class Updates
{
    /** @var string[] */
    public $types = [];
    /** @var array<string, array<mixed>> */
    public array $missing = [];
    /** @var string[] */
    public $default_plugins = [];
    /** @var string[] */
    public $default_themes = [];
    /** @var string[] */
    public array $default_languages = [];
    /** @var array<mixed> */
    public array $merged_extensions = [];
    public string $merged_extension_url = 'http://piwigo.org/download/merged_extensions.txt';

    public function __construct(
        public Plugins $plugins,
        public Themes $themes,
        public Languages $languages,
        private readonly AdminService $adminService,
        private readonly ConfigService $configService,
        private readonly MailService $mailService,
        private readonly StringUtil $stringUtil,
        private readonly UrlGenerator $urlGenerator,
        private readonly UserAdminService $userAdminService,
        private readonly UserService $userService,
        private readonly Util $util,
    ) {
        $this->types = ['plugins', 'themes', 'languages'];
        $this->default_themes = ['modus', 'elegant', 'smartpocket'];
        $this->default_plugins = ['AdminTools', 'TakeATour', 'language_switch', 'LocalFilesEditor'];
    }

    /**
     * Narrows the types this Updates instance operates on to a single category
     * (must be one of 'plugins', 'themes', 'languages'). Anything else is a no-op.
     */
    public function setPage(string $page): self
    {
        if (in_array($page, $this->types, true)) {
            $this->types = [$page];
        }
        return $this;
    }

    /** @return array<string, array<mixed>> */
    public function getFsByType(string $type): array
    {
        return match($type) {
            'plugins'   => $this->plugins->fs_plugins,
            'themes'    => $this->themes->fs_themes,
            'languages' => $this->languages->fs_languages,
            default     => [],
        };
    }

    /** @return array<int|string, array<mixed>> */
    public function getServerByType(string $type): array
    {
        return match($type) {
            'plugins'   => $this->plugins->server_plugins,
            'themes'    => $this->themes->server_themes,
            'languages' => $this->languages->server_languages,
            default     => [],
        };
    }

    /** @param array<int|string, array<mixed>> $data */
    public function setServerByType(string $type, array $data): void
    {
        match($type) {
            'plugins'   => ($this->plugins->server_plugins = $data),
            'themes'    => ($this->themes->server_themes = $data),
            'languages' => ($this->languages->server_languages = $data),
            default     => null,
        };
    }

    /** @return string[] */
    public function getDefaultsByType(string $type): array
    {
        return match($type) {
            'plugins'   => $this->default_plugins,
            'themes'    => $this->default_themes,
            'languages' => $this->default_languages,
            default     => [],
        };
    }

    public function checkPiwigoUpgrade(): void
    {
        $_SESSION['need_update'.AppInfo::VERSION] = null;

        $result = '';
        if (preg_match('/(\d+\.\d+)\.(\d+)/', AppInfo::VERSION, $matches)
          and $this->adminService->fetchRemote(PHPWG_URL.'/download/all_versions.php?rand='.md5(uniqid((string) random_int(0, mt_getrandmax()), true)), $result)
          and is_string($result)) {
            $all_versions = explode("\n", $result);
            $new_version = trim($all_versions[0]);
            $_SESSION['need_update'.AppInfo::VERSION] = version_compare(AppInfo::VERSION, $new_version, '<');
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
    /** @return array<mixed> */
    public function getPiwigoNewVersions(): array
    {
        $new_versions = [
          'piwigo.org-checked' => false,
          'is_dev' => true,
          ];

        [$env, $build_version] = $this->stringUtil->getContainerInfo();
        $build_version = is_string($build_version) ? $build_version : '';
        if (preg_match('/^(\d+\.\d+)\.(\d+)$/', AppInfo::VERSION)) {
            $new_versions['is_dev'] = false;
            $actual_branch = AppInfo::branchFromVersion(
                ('Official' === $env)
        ? substr($build_version, 0, -1)
        : AppInfo::VERSION
            );

            $url = PHPWG_URL.'/download/all_versions.php';
            $url .= '?rand='.md5(uniqid((string) random_int(0, mt_getrandmax()), true)); // Avoid server cache
            $url .= ('Official' === $env) ? '&docker' : '&show_requirements'; // Check docker version if in container
            $url .= '&origin_hash='.sha1(Config::secretKey().UrlService::getAbsoluteRootUrl());

            $result = '';
            if ($this->adminService->fetchRemote($url, $result) && is_string($result)) {
                $all_versions = explode("\n", $result);
                $new_versions['piwigo.org-checked'] = true;
                $last_version = trim($all_versions[0]);
                if ('Official' === $env) {
                    // Check if build_version is lower than the latest version
                    if ($this->containerVersionCompare($build_version, $last_version) == '-1') {
                        $last_branch = AppInfo::branchFromVersion(substr($last_version, 0, -1));
                        if ($last_branch == $actual_branch) {
                            $new_versions['minor'] = $last_version;
                        } else {
                            $new_versions['major'] = $last_version;
                            foreach ($all_versions as $version) {
                                $branch = AppInfo::branchFromVersion(substr($version, 0, -1));
                                if ($branch == $actual_branch) {
                                    if ($this->containerVersionCompare($build_version, $version) == '-1') {
                                        $new_versions['minor'] = $version;
                                    }
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    $parts0 = explode('/', trim($all_versions[0]));
                    $last_version_number = $parts0[0];
                    $last_version_php = $parts0[1] ?? '';

                    if (version_compare(AppInfo::VERSION, $last_version_number, '<')) {
                        $last_branch = AppInfo::branchFromVersion($last_version_number);

                        if ($last_branch == $actual_branch) {
                            $new_versions['minor'] = $last_version_number;
                            $new_versions['minor_php'] = $last_version_php;
                        } else {
                            $new_versions['major'] = $last_version_number;
                            $new_versions['major_php'] = $last_version_php;

                            // Check if new version exists in same branch
                            foreach ($all_versions as $version) {
                                $vparts = explode('/', trim($version));
                                $version_number = $vparts[0];
                                $version_php = $vparts[1] ?? '';
                                $branch = AppInfo::branchFromVersion($version_number);

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
     * \Piwigo\Config\Config::updateNotifyReminderPeriod() parameter.
     *
     * @since 2.9
     */
    public function notifyPiwigoNewVersions(): void
    {
        if (!$this->configService->pwgIsDbconfWriteable()) {
            return;
        }

        $new_versions = $this->getPiwigoNewVersions();
        $this->configService->confUpdateParam('update_notify_last_check', date('c'));

        if ($new_versions['is_dev']) {
            return;
        }

        $new_versions_intersected = array_intersect_key(
            $new_versions,
            array_fill_keys(['minor', 'major'], 1)
        );
        $new_versions_strings = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $new_versions_intersected);
        $new_versions_string = join(' & ', $new_versions_strings);

        if (empty($new_versions_string)) {
            return;
        }

        // In which case should we notify?
        // 1. never notified
        // 2. new versions
        // 3. no new versions but reminder needed

        $notify = false;
        if (!Config::has('update_notify_last_notification')) {
            $notify = true;
        } else {
            $lastNotifArr = StringUtil::safeUnserialize(Config::updateNotifyLastNotification() ?? '');
            $last_notification = is_string($lastNotifArr['notified_on'] ?? null) ? $lastNotifArr['notified_on'] : '';
            $last_notif_version = is_string($lastNotifArr['version'] ?? null) ? $lastNotifArr['version'] : '';

            if ($new_versions_string != $last_notif_version) {
                $notify = true;
            } elseif (
                Config::updateNotifyReminderPeriod() > 0
                and strtotime($last_notification) < strtotime(Config::updateNotifyReminderPeriod().' seconds ago')
            ) {
                $notify = true;
            }
        }

        if ($notify) {
            // send email

            $this->mailService->switchLangTo($this->userService->getDefaultLanguage());

            $content = Lang::t('Hello,');
            $content .= "\n\n".Lang::t(
                'Time has come to update your Piwigo with version %s, go to %s',
                $new_versions_string,
                $this->urlGenerator->admin('updates')
            );
            $content .= "\n\n".Lang::t('It only takes a few clicks.');
            $content .= "\n\n".Lang::t('Running on an up-to-date Piwigo is important for security.');

            $this->mailService->pwgMailAdmins(
                [
                'subject' => Lang::t('Piwigo %s is available, please update', $new_versions_string),
                'content' => $content,
                'content_format' => 'text/plain',
                ],
                [
                'filename' => 'notification_admin',
                ],
                false, // do not exclude current user
                true // only webmasters
            );

            $this->mailService->switchLangBack();

            // save notify
            $this->configService->confUpdateParam(
                'update_notify_last_notification',
                [
                'version' => $new_versions_string,
                'notified_on' => date('c'),
                ]
            );
        }
    }

    public function getServerExtensions(string $version = AppInfo::VERSION): bool
    {
        $get_data = [
          'format' => 'php',
        ];

        // Retrieve PEM versions
        $versions_to_check = [];
        $url = PEM_URL . '/api/get_version_list.php';
        $result = '';
        if ($this->adminService->fetchRemote($url, $result, $get_data) and is_string($result) and $pem_versions = StringUtil::safeUnserialize($result)) {
            if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $pem_ver0 = $pem_versions[0] ?? null;
                $pem_ver0_name = is_array($pem_ver0) && isset($pem_ver0['name']) ? $pem_ver0['name'] : null;
                $version = is_string($pem_ver0_name) ? $pem_ver0_name : $version;
            }
            $branch = AppInfo::branchFromVersion($version);
            foreach ($pem_versions as $pem_version) {
                if (!is_array($pem_version) || !isset($pem_version['name'], $pem_version['id'])) {
                    continue;
                }
                $pemVersionName = is_string($pem_version['name']) ? $pem_version['name'] : '';
                $pemVersionId = is_string($pem_version['id']) ? $pem_version['id'] : '';
                if (str_starts_with($pemVersionName, $branch)) {
                    $versions_to_check[] = $pemVersionId;
                }
            }
        }
        if (empty($versions_to_check)) {
            return false;
        }

        // Extensions to check
        $ext_to_check = [];
        foreach ($this->types as $type) {
            $fs = 'fs_'.$type;
            foreach ($this->getFsByType($type) as $ext) {
                if (isset($ext['extension']) && is_string($ext['extension'])) {
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
      'lang' => substr(CurrentUser::get()->language, 0, 2),
      'get_nb_downloads' => 'true',
      ]
        );

        $post_data = [];
        if (!empty($ext_to_check)) {
            $post_data['extension_include'] = implode(',', array_keys($ext_to_check));
        }

        if ($this->adminService->fetchRemote($url, $result, $get_data, $post_data) && is_string($result)) {
            $pem_exts = StringUtil::safeUnserialize($result);
            if ($pem_exts === []) {
                return false;
            }

            $servers = [];

            foreach ($pem_exts as $ext) {
                if (!is_array($ext) || !isset($ext['extension_id'])) {
                    continue;
                }
                $extId = $ext['extension_id'];
                if (!is_string($extId) && !is_int($extId)) {
                    continue;
                }
                if (isset($ext_to_check[$extId])) {
                    $type = $ext_to_check[$extId];

                    if (!isset($servers[$type])) {
                        $servers[$type] = [];
                    }

                    $servers[$type][$extId] = $ext;

                    unset($ext_to_check[$extId]);
                }
            }

            foreach ($servers as $server_type => $extension_list) {
                $server_string = 'server_'.$server_type;

                $this->setServerByType($server_type, $extension_list);
            }

            $this->checkMissingExtensions($ext_to_check);
            return true;
        }
        return false;
    }

    // Check all extensions upgrades
    /** @return array<mixed>|false */
    public function checkExtensions(): array|false
    {
        $_SESSION['extensions_need_update'] = [];

        if (!$this->getServerExtensions()) {
            return false;
        }

        foreach ($this->types as $type) {
            $fs = 'fs_'.$type;
            $server = 'server_'.$type;
            $server_ext = $this->getServerByType($type);
            $fs_ext = $this->getFsByType($type);

            $ignore_list = [];

            $updatesIgnored = Config::raw('updates_ignored');
            $updatesIgnoredArr = is_array($updatesIgnored) ? $updatesIgnored : [];
            $typeIgnoreList = is_array($updatesIgnoredArr[$type] ?? null) ? $updatesIgnoredArr[$type] : [];

            foreach ($fs_ext as $ext_id => $fs_ext_item) {
                $extKey2 = is_string($fs_ext_item['extension'] ?? null) ? $fs_ext_item['extension'] : null;
                if ($extKey2 !== null && isset($server_ext[$extKey2])) {
                    $ext_info = $server_ext[$extKey2];

                    // Skip dev mode extensions (version='auto')
                    if ('auto' === $fs_ext_item['version']) {
                        continue;
                    }

                    $fsExtVersionRaw = $fs_ext_item['version'] ?? null;
                    $fsExtVersion    = is_string($fsExtVersionRaw) ? $fsExtVersionRaw : '';
                    $extRevNameRaw   = $ext_info['revision_name'] ?? null;
                    $extRevName      = is_string($extRevNameRaw) ? $extRevNameRaw : '';
                    if ($this->stringUtil->safeVersionCompare($fsExtVersion, $extRevName, '<') === true) {
                        if (in_array($ext_id, $typeIgnoreList)) {
                            $ignore_list[] = $ext_id;
                        } else {
                            $_SESSION['extensions_need_update'][$type][$ext_id] = is_string($ext_info['revision_name'] ?? null) ? $ext_info['revision_name'] : '';
                        }
                    }
                }
            }
            $updatesIgnoredArr[$type] = $ignore_list;
            Config::override('updates_ignored', $updatesIgnoredArr);
        }
        $this->configService->confUpdateParam('updates_ignored', serialize(Config::raw('updates_ignored')));
        return [];
    }

    // Check if extension have been upgraded since last check
    public function checkUpdatedExtensions(): void
    {
        $extensionsNeedUpdate = is_array($_SESSION['extensions_need_update'] ?? null) ? $_SESSION['extensions_need_update'] : [];
        foreach ($this->types as $type) {
            $typeUpdates = is_array($extensionsNeedUpdate[$type] ?? null) ? $extensionsNeedUpdate[$type] : [];
            if (count($typeUpdates) > 0) {
                $fs = 'fs_'.$type;
                foreach ($this->getFsByType($type) as $ext_id => $fs_ext) {
                    $need_update_version = is_string($typeUpdates[$ext_id] ?? null)
                        ? $typeUpdates[$ext_id]
                        : '';
                    if (isset($typeUpdates[$ext_id])
                      and $this->stringUtil->safeVersionCompare(is_string($fs_ext['version'] ?? null) ? $fs_ext['version'] : '', $need_update_version, '>=') === true) {
                        // Extension have been upgraded
                        $this->checkExtensions();
                        break;
                    }
                }
            }
        }
    }

    /** @param array<mixed> $missing */
    public function checkMissingExtensions(array $missing): void
    {
        foreach ($missing as $id => $type) {
            if (!is_string($type)) {
                continue;
            }
            $fs = 'fs_'.$type;
            $default = 'default_'.$type;
            $defaultList = $this->getDefaultsByType($type);
            foreach ($this->getFsByType($type) as $ext_id => $ext) {
                if (isset($ext['extension']) and $id == $ext['extension']
                  and !in_array($ext_id, $defaultList)
                  and !in_array($ext['extension'], $this->merged_extensions)) {
                    $this->missing[$type][] = $ext;
                    break;
                }
            }
        }
    }

    public function getMergedExtensions(string $version): void
    {
        $result = '';
        if ($this->adminService->fetchRemote($this->merged_extension_url, $result) && is_string($result)) {
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

    public function processObsoleteList(string $file): void
    {
        if (file_exists(PHPWG_ROOT_PATH.$file)
          and ($old_files = file(PHPWG_ROOT_PATH.$file, FILE_IGNORE_NEW_LINES)) !== false) {
            $old_files[] = $file;
            foreach ($old_files as $old_file) {
                $path = PHPWG_ROOT_PATH.$old_file;
                if (is_file($path)) {
                    Filesystem::tryUnlink($path);
                } elseif (is_dir($path)) {
                    $this->adminService->deltree($path, PHPWG_ROOT_PATH.'_trash');
                }
            }
        }
    }

    public function upgradeTo(string $upgrade_to, int &$step, bool $check_current_version = true): void
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $template = TemplateRegistry::current();

        if ($check_current_version and !version_compare($upgrade_to, AppInfo::VERSION, '>')) {
            $this->util->redirect($this->urlGenerator->admin('updates'));
        }

        $obsolete_list = null;

        if ($step == 2) {
            $code = AppInfo::branchFromVersion(AppInfo::VERSION).'.x_to_'.$upgrade_to;
            $dl_code = str_replace(['.', '_'], '', $code);
            $remove_path = $code;
            // no longer try to delete files on a minor upgrade
            // $obsolete_list = 'obsolete.list';
        } else {
            $code = $upgrade_to;
            $dl_code = $code;
            $remove_path = version_compare($code, '2.0.8', '>=') ? 'piwigo' : 'piwigo-'.$code;
            $obsolete_list = PHPWG_ROOT_PATH.'install/obsolete.list';
        }

        $pageErrRaw = $page['errors'] ?? null;
        $pageErrors = is_array($pageErrRaw) ? $pageErrRaw : (is_scalar($pageErrRaw) ? [$pageErrRaw] : []);
        if (empty($pageErrors)) {
            $path = PHPWG_ROOT_PATH.Config::dataLocation().'update';
            $filename = $path.'/'.$code.'.zip';
            Util::mkgetdir($path);

            $chunk_num = 0;
            $end = false;
            $zip = Filesystem::tryFopen($filename, 'w');

            while (!$end) {
                $chunk_num++;
                if ($this->adminService->fetchRemote(PHPWG_URL.'/download/dlcounter.php?code='.$dl_code.'&chunk_num='.$chunk_num, $result)
                  and is_string($result)
                  and $input = StringUtil::safeUnserialize($result)) {
                    if (0 == ($input['remaining'] ?? -1)) {
                        $end = true;
                    }
                    if (is_resource($zip)) {
                        $inputData = $input['data'] ?? '';
                        fwrite($zip, base64_decode(is_string($inputData) ? $inputData : ''));
                    }
                } else {
                    $end = true;
                }
            }
            if (is_resource($zip)) {
                fclose($zip);
            }

            $filesize = Filesystem::tryFilesize($filename);
            if ($filesize !== false && $filesize > 0) {
                $zip = new \PclZip($filename);
                $resultRaw = $zip->extract(
                    PCLZIP_OPT_PATH,
                    PHPWG_ROOT_PATH,
                    PCLZIP_OPT_REMOVE_PATH,
                    $remove_path,
                    PCLZIP_OPT_SET_CHMOD,
                    0755,
                    PCLZIP_OPT_REPLACE_NEWER
                );
                if (is_array($resultRaw) && $resultRaw) {
                    $result = $resultRaw;
                    //Check if all files were extracted
                    $error = '';
                    foreach ($result as $extract) {
                        if (!is_array($extract)) {
                            continue;
                        }
                        $extractStatus = is_string($extract['status'] ?? null) ? $extract['status'] : '';
                        $extractFilename = is_string($extract['filename'] ?? null) ? $extract['filename'] : '';
                        if (!in_array($extractStatus, ['ok', 'filtered', 'already_a_directory'])) {
                            // Try to change chmod and extract
                            if (Filesystem::tryChmod(PHPWG_ROOT_PATH.$extractFilename, Config::chmodValue())
                              and is_array($res = $zip->extract(
                                  PCLZIP_OPT_BY_NAME,
                                  $remove_path.'/'.$extractFilename,
                                  PCLZIP_OPT_PATH,
                                  PHPWG_ROOT_PATH,
                                  PCLZIP_OPT_REMOVE_PATH,
                                  $remove_path,
                                  PCLZIP_OPT_SET_CHMOD,
                                  0755,
                                  PCLZIP_OPT_REPLACE_NEWER
                              ))
                              and isset($res[0])
                              /** @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible */
                              and is_string($res[0]['status'] ?? null)
                              and $res[0]['status'] == 'ok') {
                                continue;
                            } else {
                                $error .= $extractFilename.': '.$extractStatus."\n";
                            }
                        }
                    }

                    if (empty($error)) {
                        if (!empty($obsolete_list)) {
                            $this->processObsoleteList($obsolete_list);
                        }

                        $this->adminService->deltree(PHPWG_ROOT_PATH.Config::dataLocation().'update');
                        $this->userAdminService->invalidateUserCache(true);
                        $this->configService->confUpdateParam('piwigo_installed_version', $upgrade_to);
                        $this->util->pwgActivity('system', ActivitySystem::Core, 'update', ['from_version' => AppInfo::VERSION, 'to_version' => $upgrade_to]);

                        if ($step == 2) {
                            // only purge the compiled-template cache on minor updates;
                            // upgrade.php handles the major-version case.
                            $template->deleteCompiledTemplates();
                            $this->configService->confDeleteParam('fs_quick_check_last_check');

                            PageState::current()->addInfo(Lang::t('Update Complete'));
                            PageState::current()->addInfo($upgrade_to);
                            $page['updated_version'] = $upgrade_to;
                            $step = -1;
                        } else {
                            $this->util->redirect(UrlService::getRootUrl() . 'index.php?/upgrade');
                        }
                    } else {
                        file_put_contents(PHPWG_ROOT_PATH.Config::dataLocation().'update/log_error.txt', $error);

                        PageState::current()->addError(Lang::t(
                            'An error has occured during extract. Please check files permissions of your piwigo installation.<br><a href="%s">Click here to show log error</a>.',
                            UrlService::getRootUrl().Config::dataLocation().'update/log_error.txt'
                        ));
                    }
                } else {
                    $this->adminService->deltree(PHPWG_ROOT_PATH.Config::dataLocation().'update');
                    PageState::current()->addError(Lang::t('An error has occured during upgrade.'));
                }
            } else {
                PageState::current()->addError(Lang::t('Piwigo cannot retrieve upgrade file from server'));
            }
        }
    }

    // Compare version number with a letter suffix
    // Similar to version_compare with "<" sign
    public function containerVersionCompare(string $v1, string $v2): bool|int
    {
        // Split 16.2.0d into "16.2.0" as semantic_ver and "d" as sub_ver
        $v1_semantic_ver = substr($v1, 0, -1);
        $v1_sub_ver = substr($v1, -1);
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
