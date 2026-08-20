<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\Projection\NewVersionsInfo;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\UpdateNotification;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ContainerDetector;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\VersionHelper;
use Piwigo\Db\DbConnection;
use Piwigo\Http\HttpClientService;
use Piwigo\Mail\MailService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\UserService;

/**
 * Piwigo-core (not extension) self-update: checking piwigo.org for a newer
 * core version and downloading/applying it. This is a genuinely separate
 * concern from the PEM extension catalog (PemCatalog/ExtensionScanner/
 * ExtensionLifecycle) -- its own version-check/notify/upgrade logic never
 * touches the plugins/themes/languages tables or PEM_URL at all, it talks
 * to AppInfo::URL directly. Extracted here rather than folded into
 * PemCatalog to keep that class's "one generic PEM-communication concern,
 * parameterized by ExtensionType" shape clean.
 *
 * Real callers: `Bootstrap\PageTail::checkForUpdates()` (called from both
 * `renderToString()` and `prepareContext()`) constructs it directly;
 * `Admin\UpdatesPwgPageRenderer` and `Controller\Api\Extensions\
 * CheckUpdatesController` both take it via constructor injection.
 */
final readonly class CoreUpdateService
{
    public function __construct(
        private readonly Lang $lang,
        private ZipExtractor $zipExtractor,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly Paths $paths,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ActivityService $activityService,
        private readonly UserService $userService,
        private readonly MailService $mailService,
        private readonly CurrentConfig $currentConfig,
        private readonly ExtensionUpdateCachePool $extensionUpdateCachePool,
    ) {}

    public function checkPiwigoUpgrade(): void
    {
        $item = $this->extensionUpdateCachePool->getItem('core_need_update_' . AppInfo::VERSION);
        $item->set(null);

        if ((bool) preg_match('/(\d+\.\d+)\.(\d+)/', AppInfo::VERSION)
          and is_string($result = @HttpClientService::fetch(AppInfo::URL . '/download/all_versions.php?rand=' . md5(uniqid((string) mt_rand(), true)), $this->currentConfig))) {
            $allVersions = explode("\n", $result);
            $newVersion = trim($allVersions[0]);
            $item->set(version_compare(AppInfo::VERSION, $newVersion, '<'));
        }

        $this->extensionUpdateCachePool->save($item);
    }

    public function getPiwigoNewVersions(): NewVersionsInfo
    {
        $containerInfo = ContainerDetector::detect();
        $env = $containerInfo->type;
        $buildVersion = $containerInfo->version;
        if (! (bool) preg_match('/^(\d+\.\d+)\.(\d+)$/', AppInfo::VERSION)) {
            return new NewVersionsInfo(piwigoOrgChecked: false, isDev: true);
        }

        $actualBranch = VersionHelper::getBranchFromVersion($env === 'Official' ? substr((string) $buildVersion, 0, -1) : AppInfo::VERSION);

        $url = AppInfo::URL . '/download/all_versions.php';
        $url .= '?rand=' . md5(uniqid((string) mt_rand(), true));
        $url .= $env === 'Official' ? '&docker' : '&show_requirements';
        $secretKeyRaw = $this->currentConfig->secretKey;
        $secretKey = $secretKeyRaw;
        $url .= '&origin_hash=' . sha1($secretKey . $this->urlService->getAbsoluteRootUrl());

        if (! is_string($result = @HttpClientService::fetch($url, $this->currentConfig))) {
            return new NewVersionsInfo(piwigoOrgChecked: false, isDev: false);
        }

        $allVersions = explode("\n", $result);
        $lastVersion = trim($allVersions[0]);

        $minor = null;
        $major = null;
        $minorPhp = null;
        $majorPhp = null;

        if ($env === 'Official') {
            if ($this->containerVersionCompare($buildVersion, $lastVersion) === -1) {
                $lastBranch = VersionHelper::getBranchFromVersion(substr($lastVersion, 0, -1));
                if ($lastBranch === $actualBranch) {
                    $minor = $lastVersion;
                } else {
                    $major = $lastVersion;
                    foreach ($allVersions as $version) {
                        $branch = VersionHelper::getBranchFromVersion(substr($version, 0, -1));
                        if ($branch === $actualBranch) {
                            if ($this->containerVersionCompare($buildVersion, $version) === -1) {
                                $minor = $version;
                            }
                            break;
                        }
                    }
                }
            }

            return new NewVersionsInfo(piwigoOrgChecked: true, isDev: false, minor: $minor, major: $major);
        }

        [$lastVersionNumber, $lastVersionPhp] = explode('/', trim($allVersions[0]));

        if (version_compare(AppInfo::VERSION, $lastVersionNumber, '<')) {
            $lastBranch = VersionHelper::getBranchFromVersion($lastVersionNumber);

            if ($lastBranch === $actualBranch) {
                $minor = $lastVersionNumber;
                $minorPhp = $lastVersionPhp;
            } else {
                $major = $lastVersionNumber;
                $majorPhp = $lastVersionPhp;

                foreach ($allVersions as $version) {
                    [$versionNumber, $versionPhp] = explode('/', trim($version));
                    $branch = VersionHelper::getBranchFromVersion($versionNumber);

                    if ($branch === $actualBranch) {
                        if (version_compare(AppInfo::VERSION, $versionNumber, '<')) {
                            $minor = $versionNumber;
                            $minorPhp = $versionPhp;
                        }
                        break;
                    }
                }
            }
        }

        return new NewVersionsInfo(piwigoOrgChecked: true, isDev: false, minor: $minor, major: $major, minorPhp: $minorPhp, majorPhp: $majorPhp);
    }

    /**
     * Checks for new Piwigo versions and notifies webmasters by email, not
     * too often (see \Piwigo\Config\CurrentConfig::updateNotifyReminderPeriod()).
     */
    public function notifyPiwigoNewVersions(): void
    {
        if (! $this->configService->pwgIsDbconfWriteable()) {
            return;
        }

        $newVersions = $this->getPiwigoNewVersions();
        $this->configService->confUpdateParam('update_notify_last_check', date('c'));

        if ($newVersions->isDev) {
            return;
        }

        $matchingNewVersions = array_filter(
            [$newVersions->major, $newVersions->minor],
            static fn (?string $v): bool => $v !== null
        );
        $newVersionsString = join(' & ', $matchingNewVersions);

        if ($newVersionsString === '') {
            return;
        }

        $notify = false;
        $lastNotificationSetting = $this->currentConfig->updateNotifyLastNotification;
        if (! $lastNotificationSetting instanceof UpdateNotification) {
            $notify = true;
        } else {
            $lastNotification = $lastNotificationSetting->notifiedOn;
            $lastNotificationVersion = $lastNotificationSetting->version;

            $reminderPeriodRaw = $this->currentConfig->updateNotifyReminderPeriod;
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

        $notifyConn = DbConnection::build();
        $this->mailService
            ->switchLangTo($this->userService->getDefaultLanguage());

        $content = $this->lang->t('Hello,');
        $content .= "\n\n" . $this->lang->t(
            'Time has come to update your Piwigo with version %s, go to %s',
            $newVersionsString,
            $this->urlService->getAbsoluteRootUrl() . 'admin.php?page=updates'
        );
        $content .= "\n\n" . $this->lang->t('It only takes a few clicks.');
        $content .= "\n\n" . $this->lang->t('Running on an up-to-date Piwigo is important for security.');

        $this->mailService
            ->mailAdmins(
                [
                    'subject' => $this->lang->t('Piwigo %s is available, please update', $newVersionsString),
                    'content' => $content,
                    'content_format' => 'text/plain',
                ],
                [
                    'filename' => 'notification_admin',
                ],
                false,
                true
            );

        $this->mailService
            ->switchLangBack();

        $this->configService->confUpdateParam(
            'update_notify_last_notification',
            [
                'version' => $newVersionsString,
                'notified_on' => date('c'),
            ]
        );
    }

    public function upgradeTo(string $upgradeTo, int|string &$step, bool $checkCurrentVersion = true): void
    {
        $template = $this->currentTemplate->get();

        $dataLocationRaw = $this->currentConfig->dataLocation;
        $dataLocation = $dataLocationRaw;

        if ($checkCurrentVersion and ! version_compare($upgradeTo, AppInfo::VERSION, '>')) {
            // $upgradeTo isn't actually newer than the installed version
            // (a stale page, a resubmitted form, or a hand-crafted
            // request) -- back to the real Updates page. Reachable in
            // practice: UpdatesPwgPageRenderer's form submission calls
            // this with the default $checkCurrentVersion=true.
            $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=updates');
        }

        $obsoleteList = null;

        if ($this->stepIs($step, 2)) {
            $code = VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.x_to_' . $upgradeTo;
            $dlCode = str_replace(['.', '_'], '', $code);
            $removePath = $code;
        } else {
            $code = $upgradeTo;
            $dlCode = $code;
            $removePath = version_compare($code, '2.0.8', '>=') ? 'piwigo' : 'piwigo-' . $code;
            $obsoleteList = $this->paths->root . 'install/obsolete.list';
        }

        if ($this->pageState->hasErrors()) {
            return;
        }

        $path = $this->paths->root . $dataLocation . 'update';
        $filename = $path . '/' . $code . '.zip';
        @FilesystemHelper::mkgetdir($path, $this->currentConfig);

        $chunkNum = 0;
        $end = false;
        $zip = @fopen($filename, 'w');

        while (! $end) {
            $chunkNum++;
            if (is_string($result = @HttpClientService::fetch(AppInfo::URL . '/download/dlcounter.php?code=' . $dlCode . '&chunk_num=' . $chunkNum, $this->currentConfig))
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
            $this->pageState->addError($this->lang->t('Piwigo cannot retrieve upgrade file from server'));
            return;
        }

        $result = $this->zipExtractor->extract($filename, $this->paths->root, $removePath, $this->currentConfig, 0755);
        if ($result === null) {
            FilesystemHelper::deltree($this->paths->root . $dataLocation . 'update');
            $this->pageState->addError($this->lang->t('An error has occured during upgrade.'));
            return;
        }

        $error = '';
        foreach ($result as $extract) {
            if (! in_array($extract->status, ['ok', 'filtered', 'already_a_directory'], true)) {
                if (@chmod($this->paths->root . $extract->filename, 0777)
                  and ($res = $this->zipExtractor->extract(
                      $filename,
                      $this->paths->root,
                      $removePath,
                      $this->currentConfig,
                      0755,
                      $removePath . '/' . $extract->filename
                  )) !== null
                  and isset($res[0])
                  and $res[0]->status === 'ok') {
                    continue;
                }
                $error .= $extract->filename . ': ' . $extract->status . "\n";
            }
        }

        if ($error !== '') {
            file_put_contents($this->paths->root . $dataLocation . 'update/log_error.txt', $error);

            $this->pageState->addError($this->lang->t(
                'An error has occured during extract. Please check files permissions of your piwigo installation.<br><a href="%s">Click here to show log error</a>.',
                $this->urlService->getRootUrl() . $dataLocation . 'update/log_error.txt'
            ));
            return;
        }

        if ($obsoleteList !== null) {
            $this->processObsoleteList($obsoleteList);
        }

        FilesystemHelper::deltree($this->paths->root . $dataLocation . 'update');
        PermissionCacheInvalidator::invalidate();
        $this->configService->confUpdateParam('piwigo_installed_version', $upgradeTo);
        $this->activityService->record('system', ActivitySystem::Core, 'update', [
            'from_version' => AppInfo::VERSION,
            'to_version' => $upgradeTo,
        ]);

        if ($this->stepIs($step, 2)) {
            $template->deleteCompiledTemplates();
            $this->configService->confDeleteParam('fs_quick_check_last_check');

            $this->pageState->addInfo($this->lang->t('Update Complete'));
            $this->pageState->addInfo($upgradeTo);
            $this->pageState->setUpdatedVersion($upgradeTo);
            $step = -1;
        } else {
            $this->redirectService->redirect($this->urlService->getRootUrl() . 'upgrade.php?now=');
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
        if (! file_exists($this->paths->root . $file)) {
            return;
        }
        $oldFiles = file($this->paths->root . $file, FILE_IGNORE_NEW_LINES);
        if ($oldFiles === false || $oldFiles === []) {
            return;
        }
        $oldFiles[] = $file;
        foreach ($oldFiles as $oldFile) {
            $path = $this->paths->root . $oldFile;
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                FilesystemHelper::deltree($path, $this->paths->root . '_trash');
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
