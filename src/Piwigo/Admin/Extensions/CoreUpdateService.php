<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Html\HtmlService;
use Piwigo\Http\HttpClientService;
use Piwigo\Mail\MailService;

/**
 * Piwigo-core (not extension) self-update: checking piwigo.org for a newer
 * core version and downloading/applying it. This is a genuinely separate
 * concern from the PEM extension catalog (PemCatalog/ExtensionScanner/
 * ExtensionLifecycle) -- updates.class.php's own get_piwigo_new_versions()/
 * notify_piwigo_new_versions()/upgrade_to() never touch the plugins/themes/
 * languages tables or PEM_URL at all, they talk to PHPWG_URL directly.
 * Extracted here rather than folded into PemCatalog to keep that class's
 * "one generic PEM-communication concern, parameterized by ExtensionType"
 * shape clean.
 *
 * Note: updates.class.php (src/Piwigo/Admin/updates.php) is NOT deleted by
 * this batch -- upgrade.php, include/page_tail.php's new-version notifier
 * and src/Piwigo/Ws/PwgExtensions.php all still construct it directly, and
 * none of those are admin pages (P21's real scope, per
 * docs/PLAN-REPLAY.md's own "62 admin pages" framing). Only the
 * admin/updates_pwg.php page (this batch's real scope) is migrated to use
 * this new class instead.
 */
final readonly class CoreUpdateService
{
    public function __construct(
        private ZipExtractor $zipExtractor,
    ) {}

    public function checkPiwigoUpgrade(): void
    {
        $_SESSION['need_update' . AppInfo::VERSION] = null;

        if ((bool) preg_match('/(\d+\.\d+)\.(\d+)/', AppInfo::VERSION)
          and is_string($result = @HttpClientService::fetch(PHPWG_URL . '/download/all_versions.php?rand=' . md5(uniqid((string) mt_rand(), true))))) {
            $allVersions = @explode("\n", $result);
            $newVersion = trim($allVersions[0]);
            $_SESSION['need_update' . AppInfo::VERSION] = version_compare(AppInfo::VERSION, $newVersion, '<');
        }
    }

    /**
     * @return array<string, mixed> (
     *   'piwigo.org-checked' => has piwigo.org been checked?,
     *   'is_dev' => are we on a dev version?,
     *   'minor'/'major' => new version available on that branch,
     * )
     */
    public function getPiwigoNewVersions(): array
    {

        $newVersions = [
            'piwigo.org-checked' => false,
            'is_dev' => true,
        ];

        [$env, $buildVersion] = \Piwigo\Core\ContainerDetector::detect();
        if (! (bool) preg_match('/^(\d+\.\d+)\.(\d+)$/', AppInfo::VERSION)) {
            return $newVersions;
        }

        $newVersions['is_dev'] = false;
        $actualBranch = \Piwigo\Core\VersionHelper::getBranchFromVersion($env === 'Official' ? substr((string) $buildVersion, 0, -1) : AppInfo::VERSION);

        $url = PHPWG_URL . '/download/all_versions.php';
        $url .= '?rand=' . md5(uniqid((string) mt_rand(), true));
        $url .= $env === 'Official' ? '&docker' : '&show_requirements';
        $secretKeyRaw = \Piwigo\Config\Config::secretKey();
        $secretKey = $secretKeyRaw;
        $url .= '&origin_hash=' . sha1($secretKey . get_absolute_root_url());

        if (! is_string($result = @HttpClientService::fetch($url))) {
            return $newVersions;
        }

        $allVersions = explode("\n", $result);
        $newVersions['piwigo.org-checked'] = true;
        $lastVersion = trim($allVersions[0]);

        if ($env === 'Official') {
            if ($this->containerVersionCompare($buildVersion, $lastVersion) === -1) {
                $lastBranch = \Piwigo\Core\VersionHelper::getBranchFromVersion(substr($lastVersion, 0, -1));
                if ($lastBranch === $actualBranch) {
                    $newVersions['minor'] = $lastVersion;
                } else {
                    $newVersions['major'] = $lastVersion;
                    foreach ($allVersions as $version) {
                        $branch = \Piwigo\Core\VersionHelper::getBranchFromVersion(substr($version, 0, -1));
                        if ($branch === $actualBranch) {
                            if ($this->containerVersionCompare($buildVersion, $version) === -1) {
                                $newVersions['minor'] = $version;
                            }
                            break;
                        }
                    }
                }
            }

            return $newVersions;
        }

        [$lastVersionNumber, $lastVersionPhp] = explode('/', trim($allVersions[0]));

        if (version_compare(AppInfo::VERSION, $lastVersionNumber, '<')) {
            $lastBranch = \Piwigo\Core\VersionHelper::getBranchFromVersion($lastVersionNumber);

            if ($lastBranch === $actualBranch) {
                $newVersions['minor'] = $lastVersionNumber;
                $newVersions['minor_php'] = $lastVersionPhp;
            } else {
                $newVersions['major'] = $lastVersionNumber;
                $newVersions['major_php'] = $lastVersionPhp;

                foreach ($allVersions as $version) {
                    [$versionNumber, $versionPhp] = explode('/', trim($version));
                    $branch = \Piwigo\Core\VersionHelper::getBranchFromVersion($versionNumber);

                    if ($branch === $actualBranch) {
                        if (version_compare(AppInfo::VERSION, $versionNumber, '<')) {
                            $newVersions['minor'] = $versionNumber;
                            $newVersions['minor_php'] = $versionPhp;
                        }
                        break;
                    }
                }
            }
        }

        return $newVersions;
    }

    /**
     * Checks for new Piwigo versions and notifies webmasters by email, not
     * too often (see \Piwigo\Config\Config::updateNotifyReminderPeriod()).
     */
    public function notifyPiwigoNewVersions(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! \Piwigo\Config\ConfigDb::pwgIsDbconfWriteable()) {
            return;
        }

        $newVersions = $this->getPiwigoNewVersions();
        \Piwigo\Config\ConfigDb::confUpdateParam('update_notify_last_check', date('c'));

        if ((bool) $newVersions['is_dev']) {
            return;
        }

        $matchingNewVersions = array_intersect_key(
            $newVersions,
            array_fill_keys(['minor', 'major'], 1)
        );
        $newVersionsString = join(' & ', array_filter($matchingNewVersions, is_string(...)));

        if ($newVersionsString === '') {
            return;
        }

        $notify = false;
        if (! \Piwigo\Config\Config::has('update_notify_last_notification')) {
            $notify = true;
        } else {
            $lastNotificationSetting = \Piwigo\Config\Config::updateNotifyLastNotification();
            $lastNotificationRaw = (is_array($lastNotificationSetting) || is_string($lastNotificationSetting))
                ? \Piwigo\Core\ArrayHelper::safeUnserialize($lastNotificationSetting)
                : false;
            $lastNotificationData = is_array($lastNotificationRaw) ? $lastNotificationRaw : [];
            $conf['update_notify_last_notification'] = $lastNotificationData;
            \Piwigo\Config\Config::override('update_notify_last_notification', $lastNotificationData);
            $lastNotification = $lastNotificationData['notified_on'] ?? null;
            $lastNotificationVersion = $lastNotificationData['version'] ?? null;

            $reminderPeriodRaw = \Piwigo\Config\Config::updateNotifyReminderPeriod();
            $reminderPeriod = $reminderPeriodRaw;

            if ($newVersionsString !== $lastNotificationVersion) {
                $notify = true;
            } elseif (
                $reminderPeriod > 0
                and is_string($lastNotification)
                and strtotime($lastNotification) < strtotime($reminderPeriod . ' seconds ago')
            ) {
                $notify = true;
            }
        }

        if (! $notify) {
            return;
        }

        new MailService()
            ->switchLangTo(new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->getDefaultLanguage());

        $content = l10n('Hello,');
        $content .= "\n\n" . l10n(
            'Time has come to update your Piwigo with version %s, go to %s',
            $newVersionsString,
            get_absolute_root_url() . 'admin.php?page=updates'
        );
        $content .= "\n\n" . l10n('It only takes a few clicks.');
        $content .= "\n\n" . l10n('Running on an up-to-date Piwigo is important for security.');

        new MailService()
            ->mailAdmins(
                [
                    'subject' => l10n('Piwigo %s is available, please update', $newVersionsString),
                    'content' => $content,
                    'content_format' => 'text/plain',
                ],
                [
                    'filename' => 'notification_admin',
                ],
                false,
                true
            );

        new MailService()
            ->switchLangBack();

        \Piwigo\Config\ConfigDb::confUpdateParam(
            'update_notify_last_notification',
            [
                'version' => $newVersionsString,
                'notified_on' => date('c'),
            ]
        );
    }

    public function upgradeTo(string $upgradeTo, int|string &$step, bool $checkCurrentVersion = true): void
    {
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        $dataLocationRaw = \Piwigo\Config\Config::dataLocation();
        $dataLocation = $dataLocationRaw;

        if ($checkCurrentVersion and ! version_compare($upgradeTo, AppInfo::VERSION, '>')) {
            redirect(get_root_url() . 'admin.php?page=plugin-' . basename(__DIR__));
        }

        $obsoleteList = null;

        if ($this->stepIs($step, 2)) {
            $code = \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.x_to_' . $upgradeTo;
            $dlCode = str_replace(['.', '_'], '', $code);
            $removePath = $code;
        } else {
            $code = $upgradeTo;
            $dlCode = $code;
            $removePath = version_compare($code, '2.0.8', '>=') ? 'piwigo' : 'piwigo-' . $code;
            $obsoleteList = PHPWG_ROOT_PATH . 'install/obsolete.list';
        }

        if (\Piwigo\Core\PageState::current()->hasErrors()) {
            return;
        }

        $path = PHPWG_ROOT_PATH . $dataLocation . 'update';
        $filename = $path . '/' . $code . '.zip';
        @\Piwigo\Core\FilesystemHelper::mkgetdir($path);

        $chunkNum = 0;
        $end = false;
        $zip = @fopen($filename, 'w');

        while (! $end) {
            $chunkNum++;
            if (is_string($result = @HttpClientService::fetch(PHPWG_URL . '/download/dlcounter.php?code=' . $dlCode . '&chunk_num=' . $chunkNum))
              and (bool) ($input = @unserialize($result))) {
                if (is_array($input)) {
                    $remaining = $input['remaining'] ?? null;
                    if (is_numeric($remaining) && (int) $remaining === 0) {
                        $end = true;
                    }
                    if ($zip !== false) {
                        $chunkData = $input['data'] ?? null;
                        $decoded = is_string($chunkData) ? base64_decode($chunkData, true) : false;
                        if ($decoded !== false) {
                            @fwrite($zip, $decoded);
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

        if (! (bool) @filesize($filename)) {
            \Piwigo\Core\PageState::current()->addError(l10n('Piwigo cannot retrieve upgrade file from server'));
            return;
        }

        $result = $this->zipExtractor->extract($filename, PHPWG_ROOT_PATH, $removePath, 0755);
        if ($result === null) {
            FilesystemHelper::deltree(PHPWG_ROOT_PATH . $dataLocation . 'update');
            \Piwigo\Core\PageState::current()->addError(l10n('An error has occured during upgrade.'));
            return;
        }

        $error = '';
        foreach ($result as $extract) {
            if (! in_array($extract['status'], ['ok', 'filtered', 'already_a_directory'], true)) {
                if (@chmod(PHPWG_ROOT_PATH . $extract['filename'], 0777)
                  and ($res = $this->zipExtractor->extract(
                      $filename,
                      PHPWG_ROOT_PATH,
                      $removePath,
                      0755,
                      $removePath . '/' . $extract['filename']
                  )) !== null
                  and isset($res[0]['status'])
                  and $res[0]['status'] === 'ok') {
                    continue;
                }
                $error .= $extract['filename'] . ': ' . $extract['status'] . "\n";
            }
        }

        if ($error !== '') {
            file_put_contents(PHPWG_ROOT_PATH . $dataLocation . 'update/log_error.txt', $error);

            \Piwigo\Core\PageState::current()->addError(l10n(
                'An error has occured during extract. Please check files permissions of your piwigo installation.<br><a href="%s">Click here to show log error</a>.',
                get_root_url() . $dataLocation . 'update/log_error.txt'
            ));
            return;
        }

        if ($obsoleteList !== null) {
            $this->processObsoleteList($obsoleteList);
        }

        FilesystemHelper::deltree(PHPWG_ROOT_PATH . $dataLocation . 'update');
        UserCacheInvalidator::invalidate(true);
        \Piwigo\Config\ConfigDb::confUpdateParam('piwigo_installed_version', $upgradeTo);
        new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))->record('system', ActivitySystem::Core, 'update', [
            'from_version' => AppInfo::VERSION,
            'to_version' => $upgradeTo,
        ]);

        if ($this->stepIs($step, 2)) {
            $template->delete_compiled_templates();
            \Piwigo\Config\ConfigDb::confDeleteParam('fs_quick_check_last_check');

            \Piwigo\Core\PageState::current()->addInfo(l10n('Update Complete'));
            \Piwigo\Core\PageState::current()->addInfo($upgradeTo);
            $page['updated_version'] = $upgradeTo;
            $step = -1;
        } else {
            redirect(PHPWG_ROOT_PATH . 'upgrade.php?now=');
        }
    }

    /**
     * $step is int|string (numeric GET/POST values reach this class
     * un-normalized), so a plain === against an int literal would miss the
     * string-numeric case -- strict-compare against both representations
     * instead of the legacy `==`.
     */
    private function stepIs(int|string $step, int $target): bool
    {
        return is_int($step) ? $step === $target : $step === (string) $target;
    }

    private function processObsoleteList(string $file): void
    {
        if (! file_exists(PHPWG_ROOT_PATH . $file)) {
            return;
        }
        $oldFiles = file(PHPWG_ROOT_PATH . $file, FILE_IGNORE_NEW_LINES);
        if ($oldFiles === false || $oldFiles === []) {
            return;
        }
        $oldFiles[] = $file;
        foreach ($oldFiles as $oldFile) {
            $path = PHPWG_ROOT_PATH . $oldFile;
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                FilesystemHelper::deltree($path, PHPWG_ROOT_PATH . '_trash');
            }
        }
    }

    /**
     * Compares two version strings with a trailing single-letter container
     * sub-version suffix (e.g. "16.2.0d"), similar to version_compare()'s
     * "<" but also ordering the letter suffix.
     */
    public function containerVersionCompare(?string $v1, string $v2): bool|int
    {
        $v1SemanticVer = substr((string) $v1, 0, -1);
        $v1SubVer = substr((string) $v1, -1);
        $v2SemanticVer = substr($v2, 0, -1);
        $v2SubVer = substr($v2, -1);

        $res = version_compare($v1SemanticVer, $v2SemanticVer);

        if ($res === 0) {
            return strcmp($v1SubVer, $v2SubVer) < 0;
        }

        return $res;
    }
}
