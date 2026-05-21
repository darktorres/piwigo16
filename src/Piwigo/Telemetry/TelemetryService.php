<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\PemUrlResolver;
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
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Plugin\PluginState;
use Piwigo\Users\UserRepository;
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
        private ActivityRepository $activityRepository,
        private ConfigService $configService,
        private ImageRepository $imageRepository,
        private LoggerInterface $log,
        private AdminService $adminService,
        private ExecutionMutex $mutex,
        private UserRepository $userRepository,
        private PemUrlResolver $pemUrlResolver,
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
            $periodSeconds = $this->configService->sendPiwigoInfosPeriodSeconds();
            if (strtotime(Config::sendPiwigoInfosLastNotice() ?? '') < strtotime($periodSeconds . ' second ago')) {
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

        if (!Config::has('send_piwigo_infos_origin_hash')) {
            $this->configService->confUpdateParam('send_piwigo_infos_origin_hash', sha1(random_bytes(1000)), true);
        }

        $pemExtensions = $this->fetchPemExtensions();
        if ($pemExtensions === null) {
            $this->retryLater(1 * 60 * 60);
            $this->mutex->release('send_piwigo_infos');
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] executed in ' . StringUtil::getElapsedTime($startTime, StringUtil::getMoment()));
            return;
        }
        $officialExts = $this->indexOfficialExtensions($pemExtensions);

        $adminStats = $this->adminService->getPwgGeneralStatitics();

        [$plugins, $nbPrivatePlugins] = $this->buildPluginsSnapshot($pemExtensions, $officialExts, $execId);
        [$themes, $privateThemes]     = $this->buildThemesSnapshot($pemExtensions, $officialExts, $execId);
        $themesUsage                  = $this->buildThemesUsage($privateThemes);

        $defaultTheme = Kernel::service(UserService::class)->getDefaultTheme();
        if (isset($privateThemes[$defaultTheme])) {
            $defaultTheme = 'private theme';
        }

        [$activities, $nbActivities] = $this->buildUserActivities();
        $activities['system']        = $this->buildSystemActivities();

        $nbPrivateThemes = count($privateThemes);

        $nbPhotosSynced  = 0;
        $lastPhotoSynced = null;
        $lastPhoto       = null;
        if ($adminStats->nbPhotos > 0) {
            if ($this->imageRepository->countWithStorageCategorySet() > 0) {
                $filesByMethod   = $this->imageRepository->findFilesAddedByMethod();
                $syncFiles       = $filesByMethod['sync'] ?? null;
                $nbPhotosSynced  = $syncFiles['nb_files'] ?? 0;
                $lastPhotoSynced = $syncFiles['last_added_on'] ?? null;
                $methodOfLast    = 'sync';
                if (isset($filesByMethod['api'])
                    && strtotime($filesByMethod['api']['last_added_on']) > strtotime($syncFiles['last_added_on'] ?? '')
                ) {
                    $methodOfLast = 'api';
                }
                $lastPhoto = $filesByMethod[$methodOfLast]['last_added_on'] ?? null;
            } else {
                $lastPhoto = $this->imageRepository->findLatestDateAvailable();
            }
        }

        $generalStats = new TelemetryGeneralStats(
            nbPhotos:         $adminStats->nbPhotos,
            nbCategories:     $adminStats->nbCategories,
            nbTags:           $adminStats->nbTags,
            nbImageTag:       $adminStats->nbImageTag,
            nbUsers:          $adminStats->nbUsers,
            nbAdmins:         $adminStats->nbAdmins,
            nbGroups:         $adminStats->nbGroups,
            nbRates:          $adminStats->nbRates,
            nbViews:          $adminStats->nbViews,
            diskUsage:        intdiv($adminStats->diskUsage, 1024),
            nbFormats:        $adminStats->nbFormats,
            formatsDiskUsage: $adminStats->formatsDiskUsage,
            installedOn:      $this->adminService->getInstallationDate(),
            nbPhotosSynced:   $nbPhotosSynced,
            lastPhotoSynced:  $lastPhotoSynced,
            lastPhoto:        $lastPhoto,
            nbPrivatePlugins: $nbPrivatePlugins,
            nbPlugins:        $nbPrivatePlugins + count($plugins),
            nbPrivateThemes:  $nbPrivateThemes,
            nbThemes:         $nbPrivateThemes + count($themes),
            defaultTheme:     $defaultTheme,
            defaultLanguage:  Kernel::service(UserService::class)->getDefaultLanguage(),
            nbActivities:     $nbActivities,
        );

        $payload = new TelemetryPayload(
            originHash:     Config::sendPiwigoInfosOriginHash() ?? '',
            technical:      $this->buildTechnical(),
            generalStats:   $generalStats,
            fileExtensions: $generalStats->nbPhotos > 0 ? $this->imageRepository->findFileExtensionUsage() : [],
            plugins:        $plugins,
            themes:         $themes,
            themesUsage:    $themesUsage,
            languagesUsage: $this->userRepository->findLanguageUsage(),
            activities:     $activities,
            updates:        $this->buildUpdates(),
            features:       $this->buildFeatures(),
            apps:           $this->buildAppsStats(),
        );

        $updateUrl = $this->configService->sendPiwigoInfosUpdateUrl(AppInfo::PROJECT_URL);
        $url       = $updateUrl . '/ws.php';

        $getData  = ['method' => 'porg.installs.update', 'origin_hash' => $payload->originHash];
        $postData = ['data' => json_encode($payload->toArray())];

        $result = '';
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

    private function buildTechnical(): TelemetryTechnical
    {
        $dbCurrentDate                       = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        [$containerType, $containerVersion]  = StringUtil::getContainerInfo();
        return new TelemetryTechnical(
            phpVersion:       PHP_VERSION,
            piwigoVersion:    AppInfo::VERSION,
            osVersion:        PHP_OS,
            containerType:    $containerType,
            containerVersion: $containerVersion,
            dbVersion:        DbInfo::version(),
            phpDatetime:      date('Y-m-d H:i:s'),
            dbDatetime:       $dbCurrentDate,
            graphicsLibrary:  $this->adminService->getGraphicsLibrary(),
        );
    }

    /**
     * Fetch the PEM extensions catalog (eid → metadata). Returns null when the
     * remote call fails so the caller can defer the upload.
     *
     * @return array<int|string, mixed>|null
     */
    private function fetchPemExtensions(): ?array
    {
        $url    = $this->pemUrlResolver->url() . '/api/get_extension_list.php';
        $result = '';
        if (!$this->adminService->fetchRemote($url, $result) || !is_string($result)) {
            $this->log->info('[sendPiwigoInfos] fetchRemote on ' . $url . ' has failed');
            return null;
        }
        $decoded = json_decode($result, associative: true);
        if (!is_array($decoded) || $decoded === []) {
            $this->log->info('[sendPiwigoInfos] fetchRemote on ' . $url . ' returned empty catalog');
            return null;
        }
        return $decoded;
    }

    /**
     * Index PEM extensions by (idx_category, archive_root_dir) for the
     * plugin/theme codename → eid resolution path.
     *
     * @param  array<int|string, mixed>            $pemExtensions
     * @return array<int|string, array<string, int|string>>
     */
    private function indexOfficialExtensions(array $pemExtensions): array
    {
        $officialExts = [];
        foreach ($pemExtensions as $eid => $ext) {
            if (is_array($ext) && !empty($ext['archive_root_dir'])) {
                $idxCat     = $ext['idx_category'] ?? null;
                $archiveDir = $ext['archive_root_dir'];
                if ((is_string($idxCat) || is_int($idxCat)) && (is_string($archiveDir) || is_int($archiveDir))) {
                    $officialExts[$idxCat][(string) $archiveDir] = $eid;
                }
            }
        }
        return $officialExts;
    }

    /**
     * @param  array<int|string, mixed>            $pemExtensions
     * @param  array<int|string, array<string, int|string>> $officialExts
     * @return array{0: list<string>, 1: int}
     */
    private function buildPluginsSnapshot(array $pemExtensions, array $officialExts, string|false $execId): array
    {
        $plugins        = Kernel::service(Plugins::class);
        $entries        = [];
        $nbPrivate      = 0;
        $pluginsCat     = Config::pemPluginsCategory();
        foreach ($plugins->db_plugins_by_id as $plugin) {
            $pluginId      = $plugin->id;
            $pluginState   = $plugin->state;
            $pluginVersion = $plugin->version;
            if ($pluginState !== PluginState::Active) {
                continue;
            }
            $eid      = null;
            $fsPlugin = $plugins->fs_plugins[$pluginId] ?? null;
            if (is_array($fsPlugin)) {
                $uri = is_string($fsPlugin['uri'] ?? null) ? $fsPlugin['uri'] : '';
                if (preg_match('/eid=(\d+)/', $uri, $matches) && isset($pemExtensions[$matches[1]])) {
                    $eid = $matches[1];
                }
            }
            if ($eid === null) {
                $eid = $officialExts[$pluginsCat][$pluginId] ?? null;
            }
            if ($eid === null || $eid === '') {
                $this->log->info('[sendPiwigoInfos][exec=' . (string) $execId . '] ' . $pluginId . ' is a private plugin');
                $nbPrivate++;
                continue;
            }
            $pemExt    = is_array($pemExtensions[$eid] ?? null) ? $pemExtensions[$eid] : [];
            $codename  = is_string($pemExt['archive_root_dir'] ?? null) ? $pemExt['archive_root_dir'] : $pluginId;
            $entries[] = '#' . (string) $eid . '/' . $codename . '/' . $pluginVersion;
        }
        return [$entries, $nbPrivate];
    }

    /**
     * @param  array<int|string, mixed>            $pemExtensions
     * @param  array<int|string, array<string, int|string>> $officialExts
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private function buildThemesSnapshot(array $pemExtensions, array $officialExts, string|false $execId): array
    {
        $themesSvc     = Kernel::service(Themes::class);
        $entries       = [];
        $privateThemes = [];
        $themesCat     = Config::pemThemesCategory();
        foreach ($themesSvc->db_themes_by_id as $theme) {
            $themeId      = is_string($theme['id'] ?? null) ? $theme['id'] : '';
            $themeVersion = is_string($theme['version'] ?? null) ? $theme['version'] : '';
            $eid          = null;
            $fsTheme = $themesSvc->fs_themes[$themeId] ?? null;
            if (is_array($fsTheme)) {
                $uri = is_string($fsTheme['uri'] ?? null) ? $fsTheme['uri'] : '';
                if (preg_match('/eid=(\d+)/', $uri, $matches) && isset($pemExtensions[$matches[1]])) {
                    $eid = $matches[1];
                }
            }
            if ($eid === null) {
                $eid = $officialExts[$themesCat][$themeId] ?? null;
            }
            if ($eid === null || $eid === '') {
                $this->log->info('[sendPiwigoInfos][exec=' . (string) $execId . '] ' . $themeId . ' is a private theme');
                $privateThemes[$themeId] = 1;
                continue;
            }
            $pemExt    = is_array($pemExtensions[$eid] ?? null) ? $pemExtensions[$eid] : [];
            $codename  = is_string($pemExt['archive_root_dir'] ?? null) ? $pemExt['archive_root_dir'] : $themeId;
            $entries[] = '#' . (string) $eid . '/' . $codename . '/' . $themeVersion;
        }
        return [$entries, $privateThemes];
    }

    /**
     * @param  array<string, int> $privateThemes
     * @return array<string, int>
     */
    private function buildThemesUsage(array $privateThemes): array
    {
        $usage = [];
        foreach ($this->userRepository->findThemeUsage() as $themeUsed => $counter) {
            if (isset($privateThemes[$themeUsed])) {
                $themeUsed = 'private theme';
            }
            $usage[$themeUsed] = ($usage[$themeUsed] ?? 0) + $counter;
        }
        return $usage;
    }

    /** @return array{0: array<string, array<string, int>>, 1: int} */
    private function buildUserActivities(): array
    {
        $activities    = [];
        $nbActivities  = 0;
        foreach ($this->activityRepository->findUserActivityGroupCounts() as $activity) {
            $nbActivities += $activity->counter;
            $activities[$activity->object][$activity->action] = $activity->counter;
        }
        return [$activities, $nbActivities];
    }

    /** @return array<string, array<string, int>> */
    private function buildSystemActivities(): array
    {
        $labelForSystemObjectId = [1 => 'core', 2 => 'plugin', 3 => 'theme'];
        $systemActivities = [];
        foreach ($this->activityRepository->findSystemActivityGroupCounts() as $activity) {
            $labelKey = $labelForSystemObjectId[$activity->objectId ?? 0] ?? 'undefined';
            $systemActivities[$labelKey][$activity->action] = $activity->counter;
        }
        return $systemActivities;
    }

    /** @return list<array<string, mixed>> */
    private function buildUpdates(): array
    {
        $out = [];
        foreach ($this->activityRepository->findCoreUpdateActivities(ActivitySystem::Core) as $update) {
            $detailsDecoded = json_decode($update->details ?? '', associative: true);
            $details        = is_array($detailsDecoded) ? $detailsDecoded : [];
            if (isset($details['from_version']) && isset($details['to_version'])) {
                $out[] = [
                    'action'       => $update->action,
                    'occured_on'   => $update->occurredOn,
                    'from_version' => $details['from_version'],
                    'to_version'   => $details['to_version'],
                ];
            }
        }
        return $out;
    }

    /** @return array<string, string> */
    private function buildFeatures(): array
    {
        $watermark = ImageStdParams::getWatermark();
        return [
            'use_watermark'     => !empty($watermark->file) ? 'yes' : 'no',
            'activate_comments' => Config::activateComments() ? 'yes' : 'no',
            'rate'              => Config::rateEnabled() ? 'yes' : 'no',
            'log'               => Config::logConf() ? 'yes' : 'no',
            'history_guest'     => Config::historyGuest() ? 'yes' : 'no',
            'history_admin'     => Config::historyAdmin() ? 'yes' : 'no',
        ];
    }

    /** @return array<string, TelemetryAppStat> */
    private function buildAppsStats(): array
    {
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
        /** @var array<string, TelemetryAppStat> $apps */
        $apps = [];
        foreach ($this->activityRepository->findAppUserAgentStats() as $activity) {
            foreach ($appsPattern as $appName => $pattern) {
                if (preg_match($pattern, $activity->userAgent) !== 1) {
                    continue;
                }
                $existing = $apps[$appName] ?? null;
                $counter  = ($existing !== null ? $existing->counter : 0) + $activity->counter;
                $first    = $existing !== null ? $existing->firstEncounter : '';
                if ($first === '' || strtotime($first) > strtotime($activity->firstEncounter)) {
                    $first = $activity->firstEncounter;
                }
                $last = $existing !== null ? $existing->lastEncounter : '';
                if ($last === '' || strtotime($last) < strtotime($activity->lastEncounter)) {
                    $last = $activity->lastEncounter;
                }
                $apps[$appName] = new TelemetryAppStat($counter, $first, $last);
            }
        }
        return $apps;
    }
}
