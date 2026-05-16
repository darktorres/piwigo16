<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ExecutionMutex;
use Piwigo\Core\Kernel;
use Piwigo\Core\StringUtil;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageStdParams;
use Piwigo\Users\UserService;
use Psr\Log\LoggerInterface;

/**
 * Periodic "phone home" of anonymous installation statistics to the upstream
 * Piwigo telemetry endpoint. Disabled per-install via `Config::sendPiwigoInfos()`
 * (off by default in the fork). Replaces `Util::sendPiwigoInfos()` and
 * `Util::sendPiwigoInfosRetryLater()` (Phase 5).
 */
final readonly class TelemetryService
{
    public function __construct(
        private Connection $conn,
        private ConfigService $configService,
        private LoggerInterface $log,
        private AdminService $adminService,
        private ExecutionMutex $mutex,
    ) {
    }

    /**
     * Push the cadence-marker forward by `$waitTime` seconds so the next call
     * to {@see self::sendInfos()} skips the send until the new deadline.
     */
    public function retryLater(int $waitTime): void
    {
        $strtotimeResult = Config::has('send_piwigo_infos_last_notice') ? strtotime(Config::sendPiwigoInfosLastNotice() ?? '') : false;
        $lastNotice      = $strtotimeResult !== false ? $strtotimeResult : time();
        $lastNotice     += $waitTime;
        $this->configService->confUpdateParam('send_piwigo_infos_last_notice', date('c', $lastNotice), true);
        $this->log->info('[sendPiwigoInfosRetryLater] new send_piwigo_infos_last_notice=' . (Config::sendPiwigoInfosLastNotice() ?? ''));
    }

    public function sendInfos(): void
    {
        $startTime = StringUtil::getMoment();

        if (!Config::sendPiwigoInfos()) {
            return;
        }

        ConfigService::loadConfFromDb('param = "send_piwigo_infos_last_notice"', false);

        $doSend = false;
        if (Config::has('send_piwigo_infos_last_notice')) {
            $period = $this->configService->confGetParam('send_piwigo_infos_period', 7 * 24 * 60 * 60);
            if (strtotime(Config::sendPiwigoInfosLastNotice() ?? '') < strtotime((is_scalar($period) ? (string) $period : '604800') . ' second ago')) {
                $doSend = true;
            }
        } else {
            $doSend = true;
        }

        if (!$doSend) {
            return;
        }

        $this->log->info('[sendPiwigoInfos] last_notice=' . (Config::sendPiwigoInfosLastNotice() ?? 'notFound') . ' => lets do it');

        if (!$this->configService->pwgIsDbconfWriteable()) {
            $this->log->info('[sendPiwigoInfos] conf is not writeable, abort');
            return;
        }

        $execId = $this->mutex->acquire('send_piwigo_infos');
        if ($execId === false) {
            $this->log->info('[sendPiwigoInfos] another execution is running, abort');
            return;
        }

        $dbCurrentDate = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        if (!Config::has('send_piwigo_infos_origin_hash')) {
            $this->configService->confUpdateParam('send_piwigo_infos_origin_hash', sha1(random_bytes(1000)), true);
        }

        [$containerType, $containerVersion] = StringUtil::getContainerInfo();

        $piwigoInfos = [
            'origin_hash' => Config::sendPiwigoInfosOriginHash(),
            'technical'   => [
                'php_version'       => PHP_VERSION,
                'piwigo_version'    => AppInfo::VERSION,
                'os_version'        => PHP_OS,
                'container_type'    => $containerType,
                'container_version' => $containerVersion,
                'db_version'        => DbInfo::version(),
                'php_datetime'      => date('Y-m-d H:i:s'),
                'db_datetime'       => $dbCurrentDate,
                'graphics_library'  => $this->adminService->getGraphicsLibrary(),
            ],
            'general_stats' => $this->adminService->getPwgGeneralStatitics(),
        ];

        $du = $piwigoInfos['general_stats']['disk_usage'] ?? 0;
        $piwigoInfos['general_stats']['disk_usage']        = intval((is_numeric($du) ? (float) $du : 0.0) / 1024.0);
        $piwigoInfos['general_stats']['installed_on']      = $this->adminService->getInstallationDate();
        $piwigoInfos['general_stats']['nb_photos_synced']  = 0;
        $piwigoInfos['general_stats']['last_photo_synced'] = null;
        $piwigoInfos['general_stats']['last_photo']        = null;

        if ($piwigoInfos['general_stats']['nb_photos'] > 0) {
            $query = 'SELECT COUNT(*) AS counter FROM `' . Tables::images() . '` WHERE storage_category_id IS NOT NULL;';
            if (array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'counter')[0] > 0) {
                $query = 'SELECT IF(storage_category_id IS NULL, \'api\', \'sync\') AS add_method, MAX(date_available) AS last_added_on, COUNT(*) AS nb_files FROM `' . Tables::images() . '` GROUP BY add_method;';
                $filesByMethod = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, 'add_method');
                $piwigoInfos['general_stats']['nb_photos_synced']  = $filesByMethod['sync']['nb_files'];
                $piwigoInfos['general_stats']['last_photo_synced'] = $filesByMethod['sync']['last_added_on'];
                $methodOfLastPhoto = 'sync';
                if (isset($filesByMethod['api']) && strtotime(is_scalar($filesByMethod['api']['last_added_on']) ? (string) $filesByMethod['api']['last_added_on'] : '') > strtotime(is_scalar($filesByMethod['sync']['last_added_on']) ? (string) $filesByMethod['sync']['last_added_on'] : '')) {
                    $methodOfLastPhoto = 'api';
                }
                $piwigoInfos['general_stats']['last_photo'] = $filesByMethod[$methodOfLastPhoto]['last_added_on'];
            } else {
                $query  = 'SELECT date_available FROM `' . Tables::images() . '` ORDER BY id DESC LIMIT 1;';
                $images = $this->conn->executeQuery($query)->fetchAllAssociative();
                if (count($images) > 0) {
                    $piwigoInfos['general_stats']['last_photo'] = $images[0]['date_available'];
                }
            }

            $query = 'SELECT SUBSTRING_INDEX(path,".",-1) AS ext, COUNT(*) AS counter, SUM(filesize) AS filesize FROM `' . Tables::images() . '` GROUP BY ext;';
            $piwigoInfos['file_extensions'] = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, 'ext');
        }

        $url           = PEM_URL . '/api/get_extension_list.php';
        $result        = '';
        $pemExtensions = $this->adminService->fetchRemote($url, $result) && is_string($result) ? StringUtil::safeUnserialize($result) : [];

        if ($pemExtensions !== []) {
            $officialExts = [];
            foreach ($pemExtensions as $eid => $ext) {
                if (is_array($ext) && !empty($ext['archive_root_dir'])) {
                    $idxCat     = $ext['idx_category'] ?? null;
                    $archiveDir = $ext['archive_root_dir'];
                    if (is_string($idxCat) || is_int($idxCat)) {
                        $officialExts[$idxCat][is_string($archiveDir) ? $archiveDir : ''] = $eid;
                    }
                }
            }
        } else {
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] fetchRemote on ' . $url . ' has failed');
            $this->retryLater(1 * 60 * 60);
            $this->mutex->release('send_piwigo_infos');
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] executed in ' . StringUtil::getElapsedTime($startTime, StringUtil::getMoment()));
            return;
        }

        $plugins = Kernel::service(Plugins::class);
        $piwigoInfos['general_stats']['nb_private_plugins'] = 0;
        $piwigoInfos['plugins'] = [];
        foreach ($plugins->db_plugins_by_id as $plugin) {
            $pluginId      = is_string($plugin['id'] ?? null) ? $plugin['id'] : '';
            $pluginState   = is_string($plugin['state'] ?? null) ? $plugin['state'] : '';
            $pluginVersion = is_string($plugin['version'] ?? null) ? $plugin['version'] : '';
            if ($pluginState === 'active') {
                $eid      = null;
                $fsPlugin = $plugins->fs_plugins[$pluginId] ?? null;
                if (is_array($fsPlugin)) {
                    $uri = is_string($fsPlugin['uri'] ?? null) ? $fsPlugin['uri'] : '';
                    if (preg_match('/eid=(\d+)/', $uri, $matches) && isset($pemExtensions[$matches[1]])) {
                        $eid = $matches[1];
                    }
                }
                if ($eid === null) {
                    $eid = $officialExts[Config::pemPluginsCategory()][$pluginId] ?? null;
                }
                if ($eid === null || $eid === '') {
                    $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] ' . $pluginId . ' is a private plugin');
                    $piwigoInfos['general_stats']['nb_private_plugins']++;
                    continue;
                }
                $pemExt   = is_array($pemExtensions[$eid] ?? null) ? $pemExtensions[$eid] : [];
                $codename = is_string($pemExt['archive_root_dir'] ?? null) ? $pemExt['archive_root_dir'] : $pluginId;
                $piwigoInfos['plugins'][] = '#' . (string) $eid . '/' . $codename . '/' . $pluginVersion;
            }
        }
        $piwigoInfos['general_stats']['nb_plugins'] = $piwigoInfos['general_stats']['nb_private_plugins'] + count($piwigoInfos['plugins']);

        $themes  = Kernel::service(Themes::class);
        $piwigoInfos['general_stats']['nb_private_themes'] = 0;
        $piwigoInfos['themes'] = [];
        $privateThemes = [];
        foreach ($themes->db_themes_by_id as $theme) {
            $themeId      = is_string($theme['id'] ?? null) ? $theme['id'] : '';
            $themeVersion = is_string($theme['version'] ?? null) ? $theme['version'] : '';
            $eid          = null;
            $fsTheme = $themes->fs_themes[$themeId] ?? null;
            if (is_array($fsTheme)) {
                $uri = is_string($fsTheme['uri'] ?? null) ? $fsTheme['uri'] : '';
                if (preg_match('/eid=(\d+)/', $uri, $matches) && isset($pemExtensions[$matches[1]])) {
                    $eid = $matches[1];
                }
            }
            if ($eid === null) {
                $eid = $officialExts[Config::pemThemesCategory()][$themeId] ?? null;
            }
            if ($eid === null || $eid === '') {
                $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] ' . $themeId . ' is a private theme');
                $privateThemes[$themeId] = 1;
                continue;
            }
            $pemExt   = is_array($pemExtensions[$eid] ?? null) ? $pemExtensions[$eid] : [];
            $codename = is_string($pemExt['archive_root_dir'] ?? null) ? $pemExt['archive_root_dir'] : $themeId;
            $piwigoInfos['themes'][] = '#' . (string) $eid . '/' . $codename . '/' . $themeVersion;
        }
        $piwigoInfos['general_stats']['nb_private_themes'] = count(array_keys($privateThemes));
        $piwigoInfos['general_stats']['nb_themes']         = $piwigoInfos['general_stats']['nb_private_themes'] + count($piwigoInfos['themes']);

        $defaultTheme = Kernel::service(UserService::class)->getDefaultTheme();
        if (isset($privateThemes[$defaultTheme])) {
            $defaultTheme = 'private theme';
        }
        $piwigoInfos['general_stats']['default_theme'] = $defaultTheme;

        $piwigoInfos['themes_usage'] = [];
        $query      = 'SELECT theme, COUNT(*) AS theme_counter FROM ' . Tables::userInfos() . ' GROUP BY theme ORDER BY theme;';
        $themesUsed = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'theme_counter', 'theme');
        foreach ($themesUsed as $themeUsed => $counter) {
            if (isset($privateThemes[$themeUsed])) {
                $themeUsed = 'private theme';
            }
            $piwigoInfos['themes_usage'][$themeUsed] = ($piwigoInfos['themes_usage'][$themeUsed] ?? 0) + (is_numeric($counter) ? (int) $counter : 0);
        }

        $piwigoInfos['general_stats']['default_language'] = Kernel::service(UserService::class)->getDefaultLanguage();

        $query = 'SELECT language, COUNT(*) AS language_counter FROM ' . Tables::userInfos() . ' GROUP BY language ORDER BY language;';
        $piwigoInfos['languages_usage'] = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'language_counter', 'language');

        $piwigoInfos['activities']                      = [];
        $piwigoInfos['general_stats']['nb_activities']  = 0;

        $query      = 'SELECT object, action, COUNT(*) AS counter FROM ' . Tables::activity() . " WHERE object != 'system' GROUP BY object, action;";
        $activities = $this->conn->executeQuery($query)->fetchAllAssociative();
        foreach ($activities as $activity) {
            $piwigoInfos['general_stats']['nb_activities'] += is_numeric($activity['counter']) ? (int) $activity['counter'] : 0;
            $objectKey = is_string($activity['object'] ?? null) ? $activity['object'] : '';
            $actionKey = is_string($activity['action'] ?? null) ? $activity['action'] : '';
            if (!isset($piwigoInfos['activities'][$objectKey])) {
                $piwigoInfos['activities'][$objectKey] = [];
            }
            $piwigoInfos['activities'][$objectKey][$actionKey] = $activity['counter'];
        }

        $labelForSystemObjectId = [1 => 'core', 2 => 'plugin', 3 => 'theme'];
        $query      = 'SELECT object, object_id, action, COUNT(*) AS counter FROM ' . Tables::activity() . " WHERE object = 'system' GROUP BY object, object_id, action;";
        $activities = $this->conn->executeQuery($query)->fetchAllAssociative();
        $systemActivities = [];
        foreach ($activities as $activity) {
            $objectIdKey = is_numeric($activity['object_id']) ? (int) $activity['object_id'] : 0;
            $actionKey   = is_string($activity['action'] ?? null) ? $activity['action'] : '';
            $labelKey    = $labelForSystemObjectId[$objectIdKey] ?? 'undefined';
            if (!isset($systemActivities[$labelKey])) {
                $systemActivities[$labelKey] = [];
            }
            $systemActivities[$labelKey][$actionKey] = $activity['counter'];
        }
        $piwigoInfos['activities']['system'] = $systemActivities;

        $query   = 'SELECT action, occured_on, details FROM ' . Tables::activity() . " WHERE object = 'system' AND object_id = " . ActivitySystem::Core . " AND action IN ('update', 'autoupdate') ORDER BY activity_id ASC;";
        $updates = $this->conn->executeQuery($query)->fetchAllAssociative();
        foreach ($updates as $update) {
            $details = StringUtil::safeUnserialize(is_string($update['details']) ? $update['details'] : '');
            if (isset($details['from_version']) && isset($details['to_version'])) {
                $piwigoInfos['updates'][] = [
                    'action'       => $update['action'],
                    'occured_on'   => $update['occured_on'],
                    'from_version' => $details['from_version'],
                    'to_version'   => $details['to_version'],
                ];
            }
        }

        $watermark = ImageStdParams::getWatermark();
        $piwigoInfos['features'] = ['use_watermark' => !empty($watermark->file) ? 'yes' : 'no'];

        $query      = 'SELECT user_agent, COUNT(*) AS counter, MIN(occured_on) AS first_encounter, MAX(occured_on) AS last_encounter FROM ' . Tables::activity() . " WHERE user_agent NOT LIKE 'Mozilla/5%' GROUP BY user_agent;";
        $activities = $this->conn->executeQuery($query)->fetchAllAssociative();
        $apps       = [];
        $appsPattern = [
            'Piwigo iOS'          => '/^Piwigo\/\d+ CFNetwork/',
            'Piwigo NG'           => '/^Dart\/[\d\.]+ \(dart:io\)$/',
            'Piwigo Android'      => '/^Piwigo-Android/',
            'Lightroom'           => '/Lightroom/',
            'Piwigo Remote Sync'  => '/(PiwigoRemoteSync|Apache-HttpClient)/',
            'darktable'           => '/darktable/',
            'Piwigo Client'       => '/PiwigoClient/',
            'Aperture'            => '/ApertureToPiwigoPlugIn/',
            'MacShare'            => '/MacShareToPiwigo/',
            'WordPress'           => '/WordPress/',
            'pLoader'             => '/pLoader/',
        ];
        foreach ($activities as $activity) {
            foreach ($appsPattern as $appName => $pattern) {
                if (preg_match($pattern, is_string($activity['user_agent'] ?? null) ? $activity['user_agent'] : '')) {
                    $existingApp = $apps[$appName] ?? [];
                    /** @psalm-var mixed $existingCounter */
                    $existingCounter = $existingApp['counter'] ?? null;
                    $existingCounterInt = is_numeric($existingCounter) ? (int) $existingCounter : 0;
                    /** @psalm-var mixed $activityCounterRaw */
                    $activityCounterRaw = $activity['counter'] ?? null;
                    $activityCounter    = is_numeric($activityCounterRaw) ? (int) $activityCounterRaw : 0;
                    $apps[$appName]['counter'] = $existingCounterInt + $activityCounter;
                    if (!isset($apps[$appName]['first_encounter']) || strtotime(is_scalar($apps[$appName]['first_encounter']) ? (string) $apps[$appName]['first_encounter'] : '') > strtotime(is_string($activity['first_encounter'] ?? null) ? $activity['first_encounter'] : '')) {
                        $apps[$appName]['first_encounter'] = $activity['first_encounter'];
                    }
                    if (!isset($apps[$appName]['last_encounter']) || strtotime(is_scalar($apps[$appName]['last_encounter']) ? (string) $apps[$appName]['last_encounter'] : '') < strtotime(is_string($activity['last_encounter'] ?? null) ? $activity['last_encounter'] : '')) {
                        $apps[$appName]['last_encounter'] = $activity['last_encounter'];
                    }
                }
            }
        }
        $piwigoInfos['apps'] = $apps;

        $piwigoInfos['features']['activate_comments'] = Config::activateComments() ? 'yes' : 'no';
        $piwigoInfos['features']['rate']              = Config::rateEnabled() ? 'yes' : 'no';
        $piwigoInfos['features']['log']               = Config::logConf() ? 'yes' : 'no';
        $piwigoInfos['features']['history_guest']     = Config::historyGuest() ? 'yes' : 'no';
        $piwigoInfos['features']['history_admin']     = Config::historyAdmin() ? 'yes' : 'no';

        $updateUrl = $this->configService->confGetParam('send_piwigo_infos_update_url', PHPWG_URL);
        $url = (is_scalar($updateUrl) ? (string) $updateUrl : PHPWG_URL) . '/ws.php';

        $getData  = ['format' => 'php', 'method' => 'porg.installs.update', 'origin_hash' => $piwigoInfos['origin_hash']];
        $postData = ['data' => json_encode($piwigoInfos)];

        if (!$this->adminService->fetchRemote($url, $result, $getData, $postData)) {
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] fetchRemote on ' . $url . ' method=porg.installs.update has failed');
            $this->retryLater(24 * 60 * 60);
        } else {
            $lastNotice = date('c');
            $this->configService->confUpdateParam('send_piwigo_infos_last_notice', $lastNotice, true);
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] fetchRemote success, new last_notice=' . (Config::sendPiwigoInfosLastNotice() ?? ''));
        }

        $this->mutex->release('send_piwigo_infos');
        $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] executed in ' . StringUtil::getElapsedTime($startTime, StringUtil::getMoment()));
    }
}
