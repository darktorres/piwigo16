<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ArrayHelper;
use Piwigo\Core\ContainerDetector;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Http\HttpClientService;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * Lives under Admin\, not a domain namespace, because it constructs
 * `Admin\Extensions\*` (this same namespace's sibling) to cross-reference
 * installed extensions against the PEM directory -- putting this in a
 * domain namespace (L2bExtendedDomain, e.g. Piwigo\Telemetry, home of the
 * unrelated TelemetryService) would make an L2b class depend on
 * L4Integration, the wrong direction.
 *
 * Implements Piwigo\Core\TelemetrySenderInterface (bound in
 * config/container.php). Its one real caller, Piwigo\Page\PageTailRenderer
 * (L3Presentation, which cannot depend on this L4 class directly),
 * constructor-injects that interface instead; Piwigo\Bootstrap\
 * PageTail::prepareContext() passes the concrete instance. See
 * TelemetrySenderInterface's own docblock.
 */
final readonly class PiwigoInfosSender implements TelemetrySenderInterface
{
    public function __construct(
        private Lang $lang,
        private CurrentLogger $currentLogger,
        private ImageStdParams $imageStdParams,
        private ConfigService $configService,
        private InstallationStats $installationStats,
        private ActivityService $activityService,
        private UserService $userService,
        private ImageService $imageService,
        private UrlServiceInterface $urlService,
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function send(): void
    {
        $logger = $this->currentLogger->get();

        $startTime = TimingHelper::getMoment();

        if (! $this->currentConfig->sendPiwigoInfos) {
            return;
        }

        // \Piwigo\Config\CurrentConfig::sendPiwigoInfosLastNotice() has been loaded in include/common, maybe
        // a few seconds earlier, we need a refreshed value from the database. Another
        // concurrent execution might have already performed send_piwigo_infos 3 seconds ago.
        $this->configService->loadConfFromDb('send_piwigo_infos_last_notice', false);

        $doSend = false;
        $lastNotice = $this->currentConfig->sendPiwigoInfosLastNotice;
        // conf_get_param()/load_conf_from_db() both feed $conf through a
        // loosely-typed mixed pipeline, but this particular param is always a
        // MySQL datetime string once set; only strtotime()'s argument needs the
        // narrowing since the isset() check above doesn't provide a type.
        $lastNoticeStr = is_string($lastNotice) ? $lastNotice : null;
        if ($lastNoticeStr !== null) {
            $period = $this->currentConfig->sendPiwigoInfosPeriod;
            if (strtotime($lastNoticeStr) < strtotime($period . ' second ago')) {
                $doSend = true;
            }
        } else {
            $doSend = true;
        }

        if (! $doSend) {
            return;
        }

        $logger->info('[' . __FUNCTION__ . '] current conf.send_piwigo_infos_last_notice=' . ($lastNoticeStr ?? 'notFound') . ' => lets do it');

        if (! $this->configService->pwgIsDbconfWriteable()) {
            $logger->info('[' . __FUNCTION__ . '] conf is not writeable, abort');
            return;
        }

        $execId = UniqueExecLock::begins($logger, 'send_piwigo_infos');
        if ($execId === false) {
            $logger->info('[' . __FUNCTION__ . '] another execution is running, abort');
            return;
        }

        $conn = DbConnection::build();
        $dbInfo = new DbInfo($conn);
        $dbCurrentDate = $dbInfo->currentDateTime();

        if ($this->currentConfig->sendPiwigoInfosOriginHash === null) {
            $this->configService->confUpdateParam('send_piwigo_infos_origin_hash', sha1(random_bytes(1000)), true);
        }

        $containerInfo = ContainerDetector::detect();

        $piwigoInfos = [
            'origin_hash' => $this->currentConfig->sendPiwigoInfosOriginHash,
            'technical' => [
                'php_version' => PHP_VERSION,
                'piwigo_version' => AppInfo::VERSION,
                'os_version' => PHP_OS,
                'container_type' => $containerInfo->type,
                'container_version' => $containerInfo->version,
                'db_version' => $dbInfo->version(),
                'php_datetime' => date('Y-m-d H:i:s'),
                'db_datetime' => $dbCurrentDate,
                'graphics_library' => ImageBackend::getGraphicsLibrary(),
            ],
            'general_stats' => $this->installationStats->getGeneralStatistics()
                ->toArray(),
        ];

        // convert disk_usage from kB to mB
        $diskUsageKb = (float) $piwigoInfos['general_stats']['disk_usage'];
        $piwigoInfos['general_stats']['disk_usage'] = intval($diskUsageKb / 1024.0);

        $piwigoInfos['general_stats']['installed_on'] = $this->installationStats->getInstallationDate();
        $piwigoInfos['general_stats']['nb_photos_synced'] = 0;
        $piwigoInfos['general_stats']['last_photo_synced'] = null;
        $piwigoInfos['general_stats']['last_photo'] = null;

        if ($piwigoInfos['general_stats']['nb_photos'] > 0) {
            $imageService = $this->imageService;

            if ($imageService->countWithStorageCategory() > 0) {
                // slow SQL query, but necessary if you have files added by sync
                $filesAddedBy = [];
                foreach ($imageService->getAddMethodBreakdown() as $addMethodRow) {
                    $filesAddedBy[$addMethodRow->addMethod] = $addMethodRow;
                }

                // storage_category_id IS NOT NULL matched at least one row
                // above, so the 'sync' group is always present here.
                $piwigoInfos['general_stats']['nb_photos_synced'] = $filesAddedBy['sync']->nbFiles;
                $piwigoInfos['general_stats']['last_photo_synced'] = $filesAddedBy['sync']->lastAddedOn;

                $methodOfLastPhoto = 'sync';
                $apiLastAddedStr = $filesAddedBy['api']->lastAddedOn ?? '';
                $syncLastAddedStr = $filesAddedBy['sync']->lastAddedOn ?? '';
                if (isset($filesAddedBy['api']) and strtotime($apiLastAddedStr) > strtotime($syncLastAddedStr)) {
                    $methodOfLastPhoto = 'api';
                }
                $piwigoInfos['general_stats']['last_photo'] = $filesAddedBy[$methodOfLastPhoto]->lastAddedOn;
            } else {
                // much faster SQL query, but valid only if you do not use sync to add photos
                $mostRecentDateAvailable = $imageService->getMostRecentDateAvailable();
                if ($mostRecentDateAvailable !== null) {
                    $piwigoInfos['general_stats']['last_photo'] = $mostRecentDateAvailable;
                }
            }

            $piwigoInfos['file_extensions'] = [];
            foreach ($imageService->getExtensionBreakdown() as $extRow) {
                $piwigoInfos['file_extensions'][$extRow->ext] = $extRow->toArray();
            }
        }

        // $conf['pem_plugins_category'] = 12;
        // $conf['pem_themes_category'] = 10;
        $pemUrl = RequestBootstrap::pemUrl();
        $url = $pemUrl . '/api/get_extension_list.php';
        // unserialize() is typed mixed by PHP's own stub; the PEM API's
        // contract is an array of {eid: {...}} extension records, but a
        // malformed/unexpected response must degrade to the retry-later path
        // instead of crashing on a foreach/array-access of something else.
        // [P44-K] allowed_classes: false -- $result is a remote HTTPS
        // response body (compromised-catalog/MITM threat model only, see
        // HttpClientService's own SSRF guard), and this codebase only ever
        // reads the decoded value back as a plain array (the is_array()
        // check right below) -- disabling object instantiation entirely
        // costs nothing functionally and closes off PHP Object Injection
        // via any autoloadable class's __wakeup()/__destruct().
        $pemExtensions = [];
        $pemDecodeOk = false;
        if (is_string($result = HttpClientService::fetch($url, $this->currentConfig))) {
            $decodedExtensions = @unserialize($result, [
                'allowed_classes' => false,
            ]);
            if (is_array($decodedExtensions) and $decodedExtensions !== []) {
                $pemDecodeOk = true;
                foreach ($decodedExtensions as $decodedEid => $decodedExt) {
                    // $decodedEid is always int|string (PHP's own array-key
                    // invariant for a foreach), so only $decodedExt needs
                    // validating.
                    if (is_array($decodedExt)) {
                        $pemExtensions[$decodedEid] = $decodedExt;
                    }
                }
            }
        }

        if (! $pemDecodeOk) {
            $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] fetchRemote on ' . $url . ' has failed');
            $this->retryLater(1 * 60 * 60); // 1 hour later
            UniqueExecLock::ends($logger, 'send_piwigo_infos');
            $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] executed in ' . TimingHelper::getElapsedTime($startTime, TimingHelper::getMoment()));
            return;
        }

        $officialExts = [];
        foreach ($pemExtensions as $eid => $ext) {
            $archiveRootDir = $ext['archive_root_dir'] ?? null;
            $idxCategory = $ext['idx_category'] ?? null;
            if (
                ! in_array($archiveRootDir, [null, false, 0, '0', '', []], true)
                and (is_int($idxCategory) || is_string($idxCategory))
                and (is_int($archiveRootDir) || is_string($archiveRootDir))
            ) {
                @$officialExts[$idxCategory][$archiveRootDir] = $eid;
            }
        }

        $urlService = $this->urlService;
        $fsPlugins = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);
        $dbPluginsById = new ExtensionRepository($this->entityManager)
            ->findAll(ExtensionType::Plugin);
        $piwigoInfos['general_stats']['nb_private_plugins'] = 0;
        $piwigoInfos['plugins'] = [];
        foreach ($dbPluginsById as $plugin) {
            // plugins.state is `enum(...) NOT NULL` in the schema -- a
            // genuine row here always carries a string.
            assert(is_string($plugin['state']));
            if ($plugin['state'] === 'active') {
                // plugins.id/version are `varchar(...) NOT NULL` in the
                // schema — a genuine row here always carries strings.
                $pluginId = $plugin['id'];
                assert(is_string($pluginId));
                $pluginVersion = $plugin['version'];
                assert(is_string($pluginVersion));

                $eid = null;
                if (isset($fsPlugins[$pluginId])) {
                    $uri = $fsPlugins[$pluginId]['uri'] ?? null;
                    if (is_string($uri) and (bool) preg_match('/eid=(\d+)/', $uri, $matches)) {
                        if (isset($pemExtensions[$matches[1]])) {
                            $eid = $matches[1];
                        }
                    }
                }

                if (in_array($eid, [null, '0'], true)) {
                    // let's search in the data fetched from PEM
                    $pemPluginsCategory = $this->currentConfig->pemPluginsCategory;
                    $eid = $officialExts[$pemPluginsCategory][$pluginId] ?? null;
                }

                // we must exclude "private extensions". A private extension :
                //
                // * has no eid
                // * OR has un unknown plugin_id among all "Archive root directory" in PEM
                if (in_array($eid, [null, 0, '0', ''], true)) {
                    $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] ' . $pluginId . ' is a private plugin, not sent to piwigo.org');
                    $piwigoInfos['general_stats']['nb_private_plugins']++;
                    continue;
                }

                $codename = $pemExtensions[$eid]['archive_root_dir'] ?? $pluginId;
                $codename = is_scalar($codename) ? (string) $codename : $pluginId;

                $piwigoInfos['plugins'][] = '#' . $eid . '/' . $codename . '/' . $pluginVersion;
            }
        }

        $piwigoInfos['general_stats']['nb_plugins'] = $piwigoInfos['general_stats']['nb_private_plugins'] + count($piwigoInfos['plugins']);

        $fsThemes = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);
        $dbThemesById = new ExtensionRepository($this->entityManager)
            ->findAll(ExtensionType::Theme);
        $piwigoInfos['general_stats']['nb_private_themes'] = 0;
        $piwigoInfos['themes'] = [];
        $privateThemes = [];
        // themes has no 'state' column (confirmed in both
        // install/piwigo_structure-mysql.sql and the test fixture) — unlike
        // plugins, a theme is only in this table while active, so every row
        // here is implicitly active.
        foreach ($dbThemesById as $theme) {
            // themes.id/version are `varchar(...) NOT NULL` in the
            // schema — a genuine row here always carries strings.
            $themeId = $theme['id'];
            assert(is_string($themeId));
            $themeVersion = $theme['version'];
            assert(is_string($themeVersion));

            $eid = null;
            if (isset($fsThemes[$themeId])) {
                $uri = $fsThemes[$themeId]['uri'] ?? null;
                if (is_string($uri) and (bool) preg_match('/eid=(\d+)/', $uri, $matches)) {
                    if (isset($pemExtensions[$matches[1]])) {
                        $eid = $matches[1];
                    }
                }
            }

            if (in_array($eid, [null, '0'], true)) {
                // let's search in the data fetched from PEM
                $pemThemesCategory = $this->currentConfig->pemThemesCategory;
                $eid = $officialExts[$pemThemesCategory][$themeId] ?? null;
            }

            // we must exclude "private extensions". A private extension :
            //
            // * has no eid
            // * OR has un unknown theme_id among all "Archive root directory" in PEM
            if (in_array($eid, [null, 0, '0', ''], true)) {
                $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] ' . $themeId . ' is a private theme, not sent to piwigo.org');
                $privateThemes[$themeId] = 1;
                continue;
            }

            $codename = $pemExtensions[$eid]['archive_root_dir'] ?? $themeId;
            $codename = is_scalar($codename) ? (string) $codename : $themeId;

            $piwigoInfos['themes'][] = '#' . $eid . '/' . $codename . '/' . $themeVersion;
        }

        $piwigoInfos['general_stats']['nb_private_themes'] = count(array_keys($privateThemes));
        $piwigoInfos['general_stats']['nb_themes'] = $piwigoInfos['general_stats']['nb_private_themes'] + count($piwigoInfos['themes']);

        $defaultTheme = $this->userService
            ->getDefaultTheme();
        if (isset($privateThemes[$defaultTheme])) {
            $defaultTheme = 'private theme';
        }
        $piwigoInfos['general_stats']['default_theme'] = $defaultTheme;

        $piwigoInfos['themes_usage'] = [];
        $themesUsed = $this->userService->getThemeUsageCounts();
        // built as a separate local accumulator (rather than mutating
        // $piwigoInfos directly with a dynamic key) so PHPStan keeps tracking
        // a precise array<string, int> type instead of widening the whole
        // $piwigoInfos shape to mixed after a non-literal-key write.
        $themesUsage = [];
        foreach ($themesUsed as $themeUsed => $counterValue) {
            if (isset($privateThemes[$themeUsed])) {
                $themeUsed = 'private theme';
            }

            $themesUsage[$themeUsed] = ($themesUsage[$themeUsed] ?? 0) + $counterValue;
        }
        $piwigoInfos['themes_usage'] = $themesUsage;

        $piwigoInfos['general_stats']['default_language'] = $this->userService->getDefaultLanguage();

        $piwigoInfos['languages_usage'] = $this->userService->getLanguageUsageCounts();

        $piwigoInfos['activities'] = [];
        $piwigoInfos['general_stats']['nb_activities'] = 0;

        // 'activities' is heterogeneous by design: every object except 'system'
        // (queried here, WHERE object != 'system') stores a flat action=>counter
        // map; 'system' (queried below) stores an extra label-bucketing level.
        // Built as two separately-shaped accumulators (each internally
        // consistent, so PHPStan can track a precise nested type for each) and
        // merged at the end -- the two queries' WHERE clauses guarantee
        // disjoint $object keys, so the merge never overwrites either side.
        /** @var array<string, array<string, int>> $piwigoActivitiesFlat */
        $piwigoActivitiesFlat = [];
        // separate local accumulator for the same reason as $themesUsage above.
        $nbActivities = 0;
        foreach ($this->activityService->getActionCounts(null) as $activity) {
            $nbActivities += $activity->counter;
            $piwigoActivitiesFlat[$activity->object][$activity->action] = $activity->counter;
        }

        $labelForSystemScope = [
            1 => 'core',
            2 => 'plugin',
            3 => 'theme',
        ];

        /** @var array<string, array<string, array<string, int>>> $piwigoActivitiesSystem */
        $piwigoActivitiesSystem = [];
        foreach ($this->activityService->getSystemActionCountsByObjectId() as $activity) {
            // Null scope means a row written before the object_id overload
            // was split out, or by something bypassing ActivityService.
            $label = $activity->systemScope === null
                ? 'undefined'
                : $labelForSystemScope[$activity->systemScope] ?? 'undefined';
            $piwigoActivitiesSystem[$activity->object][$label][$activity->action] = $activity->counter;
        }
        $piwigoInfos['activities'] = $piwigoActivitiesFlat + $piwigoActivitiesSystem;
        $piwigoInfos['general_stats']['nb_activities'] = $nbActivities;

        $updates = $this->activityService->getCoreUpdateHistory();
        foreach ($updates as $update) {
            $updateDetails = $update->details;
            if ($updateDetails === null) {
                continue;
            }

            // `details` round-trips through ActivityRepository::
            // findCoreUpdateHistory()'s own json_encode() re-serialization
            // (the entity's real column Type is Doctrine's `json`) --
            // decoded here with safeJsonDecode(), matching that column's
            // real JSON encoding rather than the deleted (P44-K)
            // safeUnserialize()'s stale PHP-serialize() assumption.
            $details = ArrayHelper::safeJsonDecode($updateDetails);

            if (isset($details['from_version']) and isset($details['to_version'])) {
                @$piwigoInfos['updates'][] = [
                    'action' => $update->action,
                    'occured_on' => $update->occuredOn,
                    'from_version' => $details['from_version'],
                    'to_version' => $details['to_version'],
                ];
            }
        }

        $watermark = $this->imageStdParams->getWatermark();

        $piwigoInfos['features'] = [
            'use_watermark' => $watermark->file !== '' ? 'yes' : 'no',
        ];

        // which remote apps have been used?
        $remoteAppsStartTime = TimingHelper::getMoment();

        $activities = $this->activityService->getUserAgentBreakdown();
        $apps = [];

        $appsPattern = [
            'Piwigo iOS' => '/^Piwigo\/\d+ CFNetwork/',
            'Piwigo NG' => '/^Dart\/[\d\.]+ \(dart:io\)$/',
            'Piwigo Android' => '/^Piwigo-Android/',
            'Lightroom' => '/Lightroom/',
            'Piwigo Remote Sync' => '/(PiwigoRemoteSync|Apache-HttpClient)/',
            'darktable' => '/darktable/',
            'Piwigo Client' => '/PiwigoClient/',
            'Aperture' => '/ApertureToPiwigoPlugIn/',
            'MacShare' => '/MacShareToPiwigo/',
            'WordPress' => '/WordPress/',
            'pLoader' => '/pLoader/',
        ];

        foreach ($activities as $activity) {
            $activity_user_agent = $activity->userAgent ?? '';
            foreach ($appsPattern as $appName => $pattern) {
                if ((bool) preg_match($pattern, $activity_user_agent)) {
                    // $apps is written with a dynamic ($appName) key, so PHPStan
                    // can't track a precise per-key value type for it and every
                    // read below comes back mixed; narrow explicitly instead of
                    // bare-casting.
                    $currentCounter = $apps[$appName]['counter'] ?? 0;
                    $currentCounter = is_numeric($currentCounter) ? (int) $currentCounter : 0;
                    $apps[$appName]['counter'] = $currentCounter + $activity->counter;

                    $activityFirstEncounter = $activity->firstEncounter ?? '';
                    $knownFirstEncounter = $apps[$appName]['first_encounter'] ?? null;
                    $knownFirstEncounter = is_string($knownFirstEncounter) ? $knownFirstEncounter : null;
                    if ($knownFirstEncounter === null or strtotime($knownFirstEncounter) > strtotime($activityFirstEncounter)) {
                        $apps[$appName]['first_encounter'] = $activity->firstEncounter;
                    }

                    $activityLastEncounter = $activity->lastEncounter ?? '';
                    $knownLastEncounter = $apps[$appName]['last_encounter'] ?? null;
                    $knownLastEncounter = is_string($knownLastEncounter) ? $knownLastEncounter : null;
                    if ($knownLastEncounter === null or strtotime($knownLastEncounter) < strtotime($activityLastEncounter)) {
                        $apps[$appName]['last_encounter'] = $activity->lastEncounter;
                    }
                }
            }
        }

        $piwigoInfos['apps'] = $apps;

        $features = [
            'activate_comments' => $this->currentConfig->activateComments,
            'rate' => $this->currentConfig->rateEnabled,
            'log' => $this->currentConfig->logConf,
            'history_guest' => $this->currentConfig->historyGuest,
            'history_admin' => $this->currentConfig->historyAdmin,
        ];

        foreach ($features as $feature => $enabled) {
            $piwigoInfos['features'][$feature] = $enabled ? 'yes' : 'no';
        }

        $updateUrlBase = $this->currentConfig->sendPiwigoInfosUpdateUrl;
        $url = $updateUrlBase . '/ws.php';

        $getData = [
            'format' => 'php',
            'method' => 'porg.installs.update',
            'origin_hash' => $piwigoInfos['origin_hash'],
        ];

        $postData = [
            'data' => json_encode($piwigoInfos),
        ];

        if (HttpClientService::fetch($url, $this->currentConfig, $getData, $postData) === false) {
            $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] fetchRemote on ' . $url . ' method=porg.installs.update has failed');
            $this->retryLater(24 * 60 * 60);
        } else {
            $lastNotice = date('c');
            $this->configService->confUpdateParam('send_piwigo_infos_last_notice', $lastNotice, true);
            $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] fetchRemote success, new send_piwigo_infos_last_notice=' . $lastNotice);
        }

        UniqueExecLock::ends($logger, 'send_piwigo_infos');
        $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] executed in ' . TimingHelper::getElapsedTime($startTime, TimingHelper::getMoment()));
    }

    private function retryLater(int $waitTime): void
    {
        $logger = $this->currentLogger->get();

        // let's fake a last_notice so that we only try 1 day later
        $existingLastNotice = $this->currentConfig->sendPiwigoInfosLastNotice;
        $lastNotice = is_string($existingLastNotice) ? strtotime($existingLastNotice) : time();
        $lastNotice = ($lastNotice === false ? time() : $lastNotice) + $waitTime;

        $newLastNotice = date('c', $lastNotice);
        $this->configService->confUpdateParam('send_piwigo_infos_last_notice', $newLastNotice, true);
        $logger->info('[' . __FUNCTION__ . '] new send_piwigo_infos_last_notice=' . $newLastNotice);
    }
}
