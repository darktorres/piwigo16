<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ArrayHelper;
use Piwigo\Core\ContainerDetector;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Http\HttpClientService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Users\UserService;

/**
 * Ported from include/functions.inc.php's send_piwigo_infos()/
 * send_piwigo_infos_retry_later() (P23 batch 8d). Lives under Admin\, not
 * a domain namespace, because it constructs `Admin\Extensions\*` (this same
 * namespace's sibling) to cross-reference installed extensions against the
 * PEM directory -- putting this in a domain namespace (L2bExtendedDomain,
 * e.g. Piwigo\Telemetry, home of the unrelated greenfield
 * TelemetryService) would make an L2b class depend on L4Integration, the
 * wrong direction. Same "administrative machinery invoked from
 * non-admin-page contexts" precedent as PluginLoader (P23 batch 7).
 *
 * P23 batch 8f-4: the send_piwigo_infos() free-function facade is deleted
 * along with include/functions.inc.php -- this class now implements
 * Piwigo\Core\TelemetrySenderInterface (bound in config/container.php),
 * and its one real caller, Piwigo\Page\PageTailRenderer (L3Presentation,
 * which cannot depend on this L4 class directly), constructor-injects
 * that interface instead; Piwigo\Bootstrap\PageTail::render() passes the
 * concrete instance. See TelemetrySenderInterface's own docblock.
 *
 * get_graphics_library()/get_pwg_general_statitics()/get_installation_date()
 * were migrated onto PwgImage::get_graphics_library()/
 * InstallationStats::getGeneralStatistics()/getInstallationDate(), and
 * fetchRemote() onto Piwigo\Http\HttpClientService::fetch(), in the
 * following file-3 sub-batches (System info, Network/HTTP).
 */
final class PiwigoInfosSender implements \Piwigo\Core\TelemetrySenderInterface
{
    public function __construct(
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly ImageStdParams $imageStdParams,
        private readonly \Piwigo\Config\ConfigService $configService,
    ) {}

    /**
     * DRY extraction (Phase 1k DI-chain audit): the same UserService
     * recipe was repeated verbatim at 2 sites in this file.
     */
    private static function userService(): UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    #[\Override]
    public function send(): void
    {
        $logger = $this->currentLogger->get();

        $startTime = TimingHelper::getMoment();

        if (! \Piwigo\Config\CurrentConfig::sendPiwigoInfos()) {
            return;
        }

        // \Piwigo\Config\CurrentConfig::sendPiwigoInfosLastNotice() has been loaded in include/common, maybe
        // a few seconds earlier, we need a refreshed value from the database. Another
        // concurrent execution might have already performed send_piwigo_infos 3 seconds ago.
        $this->configService->loadConfFromDb('send_piwigo_infos_last_notice', false);

        $doSend = false;
        $lastNotice = \Piwigo\Config\CurrentConfig::sendPiwigoInfosLastNotice() ?? null;
        // conf_get_param()/load_conf_from_db() both feed $conf through a
        // loosely-typed mixed pipeline, but this particular param is always a
        // MySQL datetime string once set; only strtotime()'s argument needs the
        // narrowing since the isset() check above doesn't provide a type.
        $lastNoticeStr = is_string($lastNotice) ? $lastNotice : null;
        if ($lastNoticeStr !== null) {
            $period = \Piwigo\Config\CurrentConfig::sendPiwigoInfosPeriod();
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

        $execId = UniqueExecLock::begins('send_piwigo_infos');
        if ($execId === false) {
            $logger->info('[' . __FUNCTION__ . '] another execution is running, abort');
            return;
        }

        $conn = DbConnection::build();
        $dbInfo = new DbInfo($conn);
        $dbCurrentDate = $dbInfo->currentDateTime();

        if (\Piwigo\Config\CurrentConfig::sendPiwigoInfosOriginHash() === null) {
            $this->configService->confUpdateParam('send_piwigo_infos_origin_hash', sha1(random_bytes(1000)), true);
        }

        [$containerType, $containerVersion] = ContainerDetector::detect();

        $piwigoInfos = [
            'origin_hash' => \Piwigo\Config\CurrentConfig::sendPiwigoInfosOriginHash(),
            'technical' => [
                'php_version' => PHP_VERSION,
                'piwigo_version' => AppInfo::VERSION,
                'os_version' => PHP_OS,
                'container_type' => $containerType,
                'container_version' => $containerVersion,
                'db_version' => $dbInfo->version(),
                'php_datetime' => date('Y-m-d H:i:s'),
                'db_datetime' => $dbCurrentDate,
                'graphics_library' => PwgImage::get_graphics_library(),
            ],
            'general_stats' => InstallationStats::getGeneralStatistics(),
        ];

        // convert disk_usage from kB to mB
        $diskUsageKb = (float) $piwigoInfos['general_stats']['disk_usage'];
        $piwigoInfos['general_stats']['disk_usage'] = intval($diskUsageKb / 1024.0);

        $piwigoInfos['general_stats']['installed_on'] = InstallationStats::getInstallationDate();
        $piwigoInfos['general_stats']['nb_photos_synced'] = 0;
        $piwigoInfos['general_stats']['last_photo_synced'] = null;
        $piwigoInfos['general_stats']['last_photo'] = null;

        if ($piwigoInfos['general_stats']['nb_photos'] > 0) {
            $imageService = \Piwigo\Bootstrap\CoreDomainAccessor::imageService();

            if ($imageService->countWithStorageCategory() > 0) {
                // slow SQL query, but necessary if you have files added by sync
                $filesAddedBy = [];
                foreach ($imageService->getAddMethodBreakdown() as $addMethodRow) {
                    $filesAddedBy[$addMethodRow['add_method']] = $addMethodRow;
                }

                // storage_category_id IS NOT NULL matched at least one row
                // above, so the 'sync' group is always present here.
                $piwigoInfos['general_stats']['nb_photos_synced'] = $filesAddedBy['sync']['nb_files'];
                $piwigoInfos['general_stats']['last_photo_synced'] = $filesAddedBy['sync']['last_added_on'];

                $methodOfLastPhoto = 'sync';
                $apiLastAddedStr = $filesAddedBy['api']['last_added_on'] ?? '';
                $syncLastAddedStr = $filesAddedBy['sync']['last_added_on'] ?? '';
                if (isset($filesAddedBy['api']) and strtotime($apiLastAddedStr) > strtotime($syncLastAddedStr)) {
                    $methodOfLastPhoto = 'api';
                }
                $piwigoInfos['general_stats']['last_photo'] = $filesAddedBy[$methodOfLastPhoto]['last_added_on'];
            } else {
                // much faster SQL query, but valid only if you do not use sync to add photos
                $mostRecentDateAvailable = $imageService->getMostRecentDateAvailable();
                if ($mostRecentDateAvailable !== null) {
                    $piwigoInfos['general_stats']['last_photo'] = $mostRecentDateAvailable;
                }
            }

            $piwigoInfos['file_extensions'] = [];
            foreach ($imageService->getExtensionBreakdown() as $extRow) {
                $piwigoInfos['file_extensions'][$extRow['ext']] = $extRow;
            }
        }

        // $conf['pem_plugins_category'] = 12;
        // $conf['pem_themes_category'] = 10;
        $pemUrl = \Piwigo\Bootstrap\RequestBootstrap::pemUrl();
        $url = $pemUrl . '/api/get_extension_list.php';
        // unserialize() is typed mixed by PHP's own stub; the PEM API's
        // contract is an array of {eid: {...}} extension records, but a
        // malformed/unexpected response must degrade to the retry-later path
        // instead of crashing on a foreach/array-access of something else.
        $pemExtensions = [];
        $pemDecodeOk = false;
        if (is_string($result = HttpClientService::fetch($url))) {
            $decodedExtensions = @unserialize($result);
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
            UniqueExecLock::ends('send_piwigo_infos');
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

        $urlService = \Piwigo\Bootstrap\PresentationAccessor::urlService();
        $fsPlugins = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService);
        $dbPluginsById = new ExtensionRepository(\Piwigo\Db\EntityManagerFactory::build($conn))
            ->findAll(ExtensionType::Plugin);
        $piwigoInfos['general_stats']['nb_private_plugins'] = 0;
        $piwigoInfos['plugins'] = [];
        foreach ($dbPluginsById as $plugin) {
            // piwigo_plugins.state is `enum(...) NOT NULL` in the schema --
            // a genuine row here always carries a string.
            assert(is_string($plugin['state']));
            if ($plugin['state'] === 'active') {
                // piwigo_plugins.id/version are `varchar(...) NOT NULL` in the
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

                if (in_array($eid, [null, 0, '0', ''], true)) {
                    // let's search in the data fetched from PEM
                    $pemPluginsCategory = \Piwigo\Config\CurrentConfig::pemPluginsCategory();
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
            ->scan(ExtensionType::Theme, $urlService);
        $dbThemesById = new ExtensionRepository(\Piwigo\Db\EntityManagerFactory::build($conn))
            ->findAll(ExtensionType::Theme);
        $piwigoInfos['general_stats']['nb_private_themes'] = 0;
        $piwigoInfos['themes'] = [];
        $privateThemes = [];
        // piwigo_themes has no 'state' column (confirmed in both
        // install/piwigo_structure-mysql.sql and the test fixture) — unlike
        // plugins, a theme is only in this table while active, so every row
        // here is implicitly active.
        foreach ($dbThemesById as $theme) {
            // piwigo_themes.id/version are `varchar(...) NOT NULL` in the
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

            if (in_array($eid, [null, 0, '0', ''], true)) {
                // let's search in the data fetched from PEM
                $pemThemesCategory = \Piwigo\Config\CurrentConfig::pemThemesCategory();
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

        $defaultTheme = self::userService()
            ->getDefaultTheme();
        if (isset($privateThemes[$defaultTheme])) {
            $defaultTheme = 'private theme';
        }
        $piwigoInfos['general_stats']['default_theme'] = $defaultTheme;

        $piwigoInfos['themes_usage'] = [];
        $themesUsed = self::userService()->getThemeUsageCounts();
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

        $piwigoInfos['general_stats']['default_language'] = self::userService()->getDefaultLanguage();

        $piwigoInfos['languages_usage'] = self::userService()->getLanguageUsageCounts();

        $piwigoInfos['activities'] = [];
        $piwigoInfos['general_stats']['nb_activities'] = 0;

        $activityService = \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService();

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
        foreach ($activityService->getActionCounts(null) as $activity) {
            $nbActivities += $activity['counter'];
            $piwigoActivitiesFlat[$activity['object']][$activity['action']] = $activity['counter'];
        }

        $labelForSystemObjectId = [
            1 => 'core',
            2 => 'plugin',
            3 => 'theme',
        ];

        /** @var array<string, array<string, array<string, int>>> $piwigoActivitiesSystem */
        $piwigoActivitiesSystem = [];
        foreach ($activityService->getSystemActionCountsByObjectId() as $activity) {
            $label = $labelForSystemObjectId[$activity['object_id']] ?? 'undefined';
            $piwigoActivitiesSystem[$activity['object']][$label][$activity['action']] = $activity['counter'];
        }
        $piwigoInfos['activities'] = $piwigoActivitiesFlat + $piwigoActivitiesSystem;
        $piwigoInfos['general_stats']['nb_activities'] = $nbActivities;

        $updates = $activityService->getCoreUpdateHistory();
        foreach ($updates as $update) {
            $updateDetails = $update['details'];
            if (! is_string($updateDetails)) {
                continue;
            }

            $details = ArrayHelper::safeUnserialize($updateDetails);
            if (! is_array($details)) {
                continue;
            }

            if (isset($details['from_version']) and isset($details['to_version'])) {
                @$piwigoInfos['updates'][] = [
                    'action' => $update['action'],
                    'occured_on' => $update['occured_on'],
                    'from_version' => $details['from_version'],
                    'to_version' => $details['to_version'],
                ];
            }
        }

        $watermark = $this->imageStdParams->get_watermark();

        $piwigoInfos['features'] = [
            'use_watermark' => $watermark->file !== '' ? 'yes' : 'no',
        ];

        // which remote apps have been used?
        $remoteAppsStartTime = TimingHelper::getMoment();

        $activities = $activityService->getUserAgentBreakdown();
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
            $activity_user_agent = $activity['user_agent'] ?? '';
            foreach ($appsPattern as $appName => $pattern) {
                if ((bool) preg_match($pattern, $activity_user_agent)) {
                    // $apps is written with a dynamic ($appName) key, so PHPStan
                    // can't track a precise per-key value type for it and every
                    // read below comes back mixed; narrow explicitly instead of
                    // bare-casting.
                    $currentCounter = $apps[$appName]['counter'] ?? 0;
                    $currentCounter = is_numeric($currentCounter) ? (int) $currentCounter : 0;
                    $apps[$appName]['counter'] = $currentCounter + $activity['counter'];

                    $activityFirstEncounter = $activity['first_encounter'] ?? '';
                    $knownFirstEncounter = $apps[$appName]['first_encounter'] ?? null;
                    $knownFirstEncounter = is_string($knownFirstEncounter) ? $knownFirstEncounter : null;
                    if ($knownFirstEncounter === null or strtotime($knownFirstEncounter) > strtotime($activityFirstEncounter)) {
                        $apps[$appName]['first_encounter'] = $activity['first_encounter'];
                    }

                    $activityLastEncounter = $activity['last_encounter'] ?? '';
                    $knownLastEncounter = $apps[$appName]['last_encounter'] ?? null;
                    $knownLastEncounter = is_string($knownLastEncounter) ? $knownLastEncounter : null;
                    if ($knownLastEncounter === null or strtotime($knownLastEncounter) < strtotime($activityLastEncounter)) {
                        $apps[$appName]['last_encounter'] = $activity['last_encounter'];
                    }
                }
            }
        }

        $piwigoInfos['apps'] = $apps;

        $features = [
            'activate_comments' => \Piwigo\Config\CurrentConfig::activateComments(),
            'rate' => \Piwigo\Config\CurrentConfig::rateEnabled(),
            'log' => \Piwigo\Config\CurrentConfig::logConf(),
            'history_guest' => \Piwigo\Config\CurrentConfig::historyGuest(),
            'history_admin' => \Piwigo\Config\CurrentConfig::historyAdmin(),
        ];

        foreach ($features as $feature => $enabled) {
            $piwigoInfos['features'][$feature] = $enabled ? 'yes' : 'no';
        }

        $updateUrlBase = \Piwigo\Config\CurrentConfig::sendPiwigoInfosUpdateUrl();
        $url = $updateUrlBase . '/ws.php';

        $getData = [
            'format' => 'php',
            'method' => 'porg.installs.update',
            'origin_hash' => $piwigoInfos['origin_hash'],
        ];

        $postData = [
            'data' => json_encode($piwigoInfos),
        ];

        if (HttpClientService::fetch($url, $getData, $postData) === false) {
            $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] fetchRemote on ' . $url . ' method=porg.installs.update has failed');
            $this->retryLater(24 * 60 * 60);
        } else {
            $lastNotice = date('c');
            $this->configService->confUpdateParam('send_piwigo_infos_last_notice', $lastNotice, true);
            $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] fetchRemote success, new send_piwigo_infos_last_notice=' . $lastNotice);
        }

        UniqueExecLock::ends('send_piwigo_infos');
        $logger->info('[' . __FUNCTION__ . '][exec=' . $execId . '] executed in ' . TimingHelper::getElapsedTime($startTime, TimingHelper::getMoment()));
    }

    private function retryLater(int $waitTime): void
    {
        $logger = $this->currentLogger->get();

        // let's fake a last_notice so that we only try 1 day later
        $existingLastNotice = \Piwigo\Config\CurrentConfig::sendPiwigoInfosLastNotice() ?? null;
        $lastNotice = is_string($existingLastNotice) ? strtotime($existingLastNotice) : time();
        $lastNotice = ($lastNotice === false ? time() : $lastNotice) + $waitTime;

        $newLastNotice = date('c', $lastNotice);
        $this->configService->confUpdateParam('send_piwigo_infos_last_notice', $newLastNotice, true);
        $logger->info('[' . __FUNCTION__ . '] new send_piwigo_infos_last_notice=' . $newLastNotice);
    }
}
