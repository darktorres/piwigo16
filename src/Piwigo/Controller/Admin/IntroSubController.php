<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use DateInterval;
use DateTime;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\AdminUiHelper;
use Piwigo\Admin\InstallationStats;
use Piwigo\Admin\Integrity\C13yInternal;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Admin\LoadedPlugins;
use Piwigo\Admin\Maintenance\FilesystemIntegrityChecker;
use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Config\CacheSizesSnapshot;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\IntroPageContext;
use Piwigo\Controller\Admin\Request\IntroActionRequest;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Env;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Location\LocEndIntro;
use Piwigo\Http\HttpClientService;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the admin dashboard (page slug "intro" -- also admin.php's own
 * default `?page=` fallback). Its dashboard queries (activity chart,
 * storage chart, general stats via
 * InstallationStats::getGeneralStatistics()) are single-purpose
 * view-shaping for this one page and stay inline rather than living in a
 * separate service, the same "page/template glue stays inline" pattern as
 * admin.php's own dashboard-badge queries (pending comments/orphans/
 * locked albums).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so this controller does not repeat that check.
 *
 * `$my_base_url` is not needed here: CoreTabs::addCoreTabs()'s own
 * `case 'admin_home':` branch (the only case this page's
 * `$tabsheet->setId('admin_home'); $tabsheet->select('');` can ever
 * reach) hardcodes `'url' => 'admin.php'` and never reads
 * `global $my_base_url;` at all.
 *
 * The page is read-mostly (dashboard stats/activity chart/storage chart/
 * integrity check display). Its one write path --
 * `$_GET['action'] === 'hide_newsletter_subscription'` ->
 * userprefs_update_param('show_newsletter_subscription', 'false') -- has
 * no check_pwg_token(), because the mutation is a per-admin-user UI
 * preference toggle (hides a promo banner for the currently logged-in
 * admin only, no data loss, no privilege change, no cross-user effect).
 *
 * cmp_day() has zero external callers and is a private static method here
 * rather than a free function, avoiding the "cannot redeclare function"
 * fatal error a top-level function risks if the file is loaded twice.
 */
final readonly class IntroSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private LoadedPlugins $loadedPlugins,
        private CurrentLogger $currentLogger,
        private FilesystemIntegrityChecker $filesystemIntegrityChecker,
        private SessionService $sessionService,
        private Translator $translator,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private InstallationStats $installationStats,
        private CommentService $commentService,
        private ActivityService $activityService,
        private PreferencesService $preferencesService,
        private ImageService $imageService,
        private CategoryService $categoryService,
        private UserService $userService,
        private CurrentConfig $currentConfig,
        private Paths $paths,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        // $link_start is computed locally rather than read from a global:
        // AdminShell's own same-named value (used for its menubar hrefs)
        // is a local variable in a different call frame, never `global`-
        // or $GLOBALS[]-declared.
        $link_start = $this->urlService->getRootUrl() . 'admin.php?page=';
        $logger = $this->currentLogger->get();
        $template = $this->currentTemplate->get();

        // A single connection is used for the whole request, avoiding
        // needless reconnects.
        $conn = DbConnection::build();

        // +-----------------------------------------------------------------------+
        // | tabs                                                                  |
        // +-----------------------------------------------------------------------+

        if (IntroActionRequest::fromGlobals()->isHideNewsletterSubscription) {
            $this->preferencesService
                ->updateParam('show_newsletter_subscription', 'false');
            exit();
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('admin_home');
        $tabsheet->select('', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        // +-----------------------------------------------------------------------+
        // |                                actions                                |
        // +-----------------------------------------------------------------------+

        $nb_pending_comments = $this->pageState->nbPendingComments;
        if ($nb_pending_comments !== null) {
            $message = $this->lang->t('User comments') . ' <i class="icon-chat"></i> ';
            $message .= '<a href="' . $link_start . 'comments">';
            $message .= $this->lang->t('%d waiting for validation', $nb_pending_comments);
            $message .= ' <i class="icon-right"></i></a>';

            $this->pageState->addMessage($message);
        }

        // any orphan photo?
        $nb_orphans = $this->pageState->nbOrphans; // already calculated in admin.php

        if ($this->pageState->nbPhotosTotal >= 100000) { // but has not been calculated on a big gallery, so force it now
            $nb_orphans = $this->imageService
                ->countOrphans();
        }

        if ($nb_orphans > 0) {
            $orphans_url = $this->urlService->getRootUrl() . 'admin.php?page=batch_manager&amp;filter=prefilter-no_album';

            $message = '<a href="' . $orphans_url . '"><i class="icon-heart-broken"></i>';
            $message .= $this->lang->t('Orphans') . '</a>';
            $message .= '<span class="adminMenubarCounter">' . $nb_orphans . '</span>';

            $this->pageState->addWarning($message);
        }

        // locked album ?
        $locked_album = $this->categoryService->countByVisible(false);
        if ($locked_album > 0) {
            $locked_album_url = $this->urlService->getRootUrl() . 'admin.php?page=cat_options&section=visible';

            $message = '<a href="' . $locked_album_url . '"><i class="icon-cone"></i>';
            $message .= $this->lang->t('Locked album') . '</a>';
            $message .= '<span class="adminMenubarCounter">' . (string) $locked_album . '</span>';

            $this->pageState->addWarning($message);
        }

        $this->filesystemIntegrityChecker->fsQuickCheck();

        // +-----------------------------------------------------------------------+
        // |                             template init                             |
        // +-----------------------------------------------------------------------+

        $template->setFilenames([
            'intro' => 'intro.tpl',
        ]);

        $newsletter_email = null;
        $newsletter_subscribe_base_url = null;
        $newsletter_old_newsletters_url = null;
        if ($this->currentConfig->showNewsletterSubscription and ($this->preferencesService->getShowNewsletterSubscription() ?? true)) {
            $register_date = new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig)
                ->findEarliestRegistrationDate();
            $nb_cats = $this->categoryService->countAllCategories();
            $nb_images = $this->imageService->getTotalImageCount();

            // To see the newsletter promote, the account must have 2 weeks ancient, 3 albums created and 30 photos uploaded
            $register_date_str = is_string($register_date) ? $register_date : '';

            if (strtotime($register_date_str) < strtotime('2 weeks ago') and $nb_cats >= 3 and $nb_images >= 30) {
                $user = $this->currentUser->get();
                $user_language = $user->language->value;

                $newsletter_email = $user->email?->value;
                $newsletter_subscribe_base_url = AdminUiHelper::getNewsletterSubscribeBaseUrl($user_language);
                $newsletter_old_newsletters_url = AdminUiHelper::getOldNewslettersBaseUrl($user_language);
            }

        }

        $stats = $this->installationStats->getGeneralStatistics();

        $disk_usage = (float) $stats->diskUsage;
        $nb_views = (float) $stats->nbViews;

        $du_decimals = 1;
        $du_gb = $disk_usage / (1024.0 * 1024.0);
        if ($du_gb > 100) {
            $du_decimals = 0;
        }

        $nb_comments = $this->currentConfig->activateComments ? $this->commentService->countAll() : 0;

        if ($this->currentConfig->showPiwigoLatestNews) {
            $latest_news = self::getLatestNews($this->lang, $this->currentConfig, $this->paths);

            // getLatestNews()'s array shape's own leaf values stay mixed (raw
            // external JSON), and it can also be bool|null (unserialize()
            // failure/no cache yet), so every field still needs a real runtime
            // check before use below.
            $news_posted_on = is_array($latest_news) ? ($latest_news['posted_on'] ?? null) : null;

            if (
                is_array($latest_news)
                && isset($latest_news['id'])
                && (is_int($news_posted_on) || is_string($news_posted_on))
                && $news_posted_on > time() - 60 * 60 * 24 * 30
            ) {
                $news_url = $latest_news['url'] ?? null;
                $news_url = is_string($news_url) ? $news_url : '';

                $news_posted = $latest_news['posted'] ?? null;
                $news_posted = is_string($news_posted) ? $news_posted : '';

                $news_subject = $latest_news['subject'] ?? null;
                $news_subject = is_string($news_subject) ? $news_subject : '';

                $this->pageState->addMessage(sprintf(
                    '%s <a href="%s" title="%s" target="_blank"><i class="icon-bell"></i> %s</a>',
                    $this->lang->t('Latest Piwigo news'),
                    $news_url,
                    DateHelper::timeSince($news_posted_on, 'year') . ' (' . $news_posted . ')',
                    $news_subject
                ));
            }
        }

        $this->eventDispatcher->dispatchNotify(new LocEndIntro());

        // +-----------------------------------------------------------------------+
        // |                           get activity data                           |
        // +-----------------------------------------------------------------------+

        $nb_weeks = $this->currentConfig->dashboardActivityNbWeeks;

        // Count mondays
        $mondays = 0;
        // Get mondays number for the chart legend
        $week_number = [];
        // Array for sorting days in circle size
        $temp_data = [];

        $activity_last_weeks = [];
        $date = Env::now();

        // Get data from $nb_weeks last weeks
        while ($mondays < $nb_weeks) {
            if ($date->format('D') === 'Mon') {
                $week_number[] = $date->format('W');
                ++$mondays;
            }

            $date->sub(new DateInterval('P1D'));
        }

        $week_number = array_reverse($week_number);
        $date_string = $date->format('Y-m-d');

        $session_cache_activity = $_SESSION['cache_activity_last_weeks'] ?? null;
        $session_cache_calculated_on = is_array($session_cache_activity) ? ($session_cache_activity['calculated_on'] ?? null) : null;
        $session_cache_calculated_on = is_numeric($session_cache_calculated_on) ? (int) $session_cache_calculated_on : null;

        if ($session_cache_calculated_on === null or $session_cache_calculated_on < Env::now()->getTimestamp() - 300) {
            $start_time = TimingHelper::getMoment();

            $activity_actions = $this->activityService->getDailyActionCountsSince($date_string);

            foreach ($activity_actions as $action) {
                // set the time to 12:00 (midday) so that it doesn't goes to previous/next day due to timezone offset
                $day_date = new DateTime($action->activityDay . ' 12:00:00');

                $week = 0;
                for ($i = 0; $i < $nb_weeks; $i++) {
                    if ($week_number[$i] === $day_date->format('W')) {
                        $week = $i;
                    }
                }
                $day_nb = $day_date->format('N');

                $activity_counter = $action->counter;

                $action_object = $action->object;
                $action_action = $action->action;

                $activity_last_weeks[$week][$day_nb]['details'][ucfirst($action_object)][ucfirst($action_action)] = $activity_counter;

                // 'number' is only ever set below to an int (this same accumulation),
                // so this is always int|missing -- the ?? 0 fallback covers both.
                $current_number = $activity_last_weeks[$week][$day_nb]['number'] ?? 0;
                $activity_last_weeks[$week][$day_nb]['number'] = $current_number + $activity_counter;

                $activity_last_weeks[$week][$day_nb]['date'] = DateHelper::formatDate($day_date->getTimestamp());
            }

            $logger->debug('[admin/intro::' . __LINE__ . '] recent activity calculated in ' . TimingHelper::getElapsedTime($start_time, TimingHelper::getMoment()));

            $_SESSION['cache_activity_last_weeks'] = [
                'calculated_on' => Env::now()
                    ->getTimestamp(),
                'data' => $activity_last_weeks,
            ];
        }

        $session_cache_activity = $_SESSION['cache_activity_last_weeks'] ?? null;
        $cached_activity_data = is_array($session_cache_activity) ? ($session_cache_activity['data'] ?? null) : null;
        $raw_activity_last_weeks = is_array($cached_activity_data) ? $cached_activity_data : [];

        $activity_last_weeks = [];

        foreach ($raw_activity_last_weeks as $week => $i) {
            if (! is_array($i)) {
                continue;
            }

            foreach ($i as $day => $j) {
                if (! is_array($j)) {
                    continue;
                }

                $details = $j['details'] ?? null;
                $details = is_array($details) ? $details : [];
                ksort($details);

                $number = $j['number'] ?? null;
                $number = is_numeric($number) ? $number : 0;

                $activity_last_weeks[$week][$day] = $j;
                $activity_last_weeks[$week][$day]['details'] = $details;

                if ($number > 0) {
                    $temp_data[] = [
                        'x' => $number,
                        'd' => $day,
                        'w' => $week,
                    ];
                }
            }
        }

        // Algorithm to sort days in circle size :
        //  * Get the difference between sorted numbers of activity per day (only not null numbers)
        //  * Split days max $circle_sizes time on the biggest difference (but not below 120%)
        //  * Set the sizes according to the groups created

        usort($temp_data, self::cmpDay(...));

        // Get the percent difference
        $diff_x = [];

        for ($i = 1; $i < count($temp_data); $i++) {
            // the 'x' key is always the numeric $number built earlier (guarded by
            // is_numeric()+> 0 above).
            $current_x = $temp_data[$i]['x'];
            $previous_x = $temp_data[$i - 1]['x'];
            $diff_x[] = (float) $current_x / (float) $previous_x * 100.0;
        }

        $split = 0;
        // Split (split represented by -1)
        if (count($diff_x) > 0) {
            while (max($diff_x) > 120) {
                $max_idx = array_search(max($diff_x), $diff_x, true);
                if ($max_idx === false) {
                    break;
                }
                $diff_x[$max_idx] = -1;
                $split++;
            }
        }

        // Fill empty chart data for the template
        $chart_data = [];
        for ($i = 0; $i < $nb_weeks; $i++) {
            for ($j = 1; $j <= 7; $j++) {
                $chart_data[$i][$j] = 0;
            }
        }

        $size = 1;

        // 'w'/'d' are always the $week/$day foreach keys built earlier, always
        // int|string (PHP's own array-key invariant).
        if (isset($temp_data[0])) {
            $chart_w = $temp_data[0]['w'];
            $chart_d = $temp_data[0]['d'];
            $chart_data[$chart_w][$chart_d] = $size;
        }

        // Set sizes in chart data
        for ($i = 1; $i < count($temp_data); $i++) {
            if ($diff_x[$i - 1] === -1) {
                $size++;
            }
            $chart_w = $temp_data[$i]['w'];
            $chart_d = $temp_data[$i]['d'];
            $chart_data[$chart_w][$chart_d] = $size;
        }

        $lang_days = $this->lang->days();

        $day_labels = [];
        for ($i = 0; $i <= 6; $i++) {
            // first 3 letters of day name
            $day_name = $lang_days[($i + 1) % 7] ?? null;
            $day_name = is_string($day_name) ? $day_name : '';
            $day_labels[] = mb_substr($day_name, 0, 3);
        }

        // +-----------------------------------------------------------------------+
        // |                           get storage data                            |
        // +-----------------------------------------------------------------------+

        $video_format = ['webm', 'webmv', 'ogg', 'ogv', 'mp4', 'm4v', 'mov'];
        /** @var array<string, array<string, array<string, mixed>>> $data_storage */
        $data_storage = [];

        $picture_ext = $this->currentConfig->pictureExtensions;

        // Select files in Image_Table
        $imageService = $this->imageService;

        foreach ($imageService->getExtensionBreakdown() as $ext_details) {
            $ext = $ext_details->ext;
            $type = null;
            if (in_array(strtolower($ext), $picture_ext, true)) {
                $type = 'Photos';
            } elseif (in_array(strtolower($ext), $video_format, true)) {
                $type = 'Videos';
            } else {
                $type = 'Other';
            }

            $ext_filesize = (float) $ext_details->filesize;
            $ext_counter = $ext_details->counter;

            $current_filesize = $data_storage[$type]['total']['filesize'] ?? 0;
            $current_filesize = is_numeric($current_filesize) ? (float) $current_filesize : 0.0;
            $data_storage[$type]['total']['filesize'] = $current_filesize + $ext_filesize;

            $current_nb_files = $data_storage[$type]['total']['nb_files'] ?? 0;
            $current_nb_files = is_numeric($current_nb_files) ? (int) $current_nb_files : 0;
            $data_storage[$type]['total']['nb_files'] = $current_nb_files + $ext_counter;

            $data_storage[$type]['details'][strtoupper($ext)] = [
                'filesize' => $ext_filesize,
                'nb_files' => $ext_counter,
            ];
        }

        // Select files from format table
        foreach ($imageService->getFormatExtensionBreakdown() as $ext_details) {
            $ext = $ext_details->ext;
            $type = 'Formats';

            $ext_filesize = (float) $ext_details->filesize;
            $ext_counter = $ext_details->counter;

            $current_filesize = $data_storage[$type]['total']['filesize'] ?? 0;
            $current_filesize = is_numeric($current_filesize) ? (float) $current_filesize : 0.0;
            $data_storage[$type]['total']['filesize'] = $current_filesize + $ext_filesize;

            $current_nb_files = $data_storage[$type]['total']['nb_files'] ?? 0;
            $current_nb_files = is_numeric($current_nb_files) ? (int) $current_nb_files : 0;
            $data_storage[$type]['total']['nb_files'] = $current_nb_files + $ext_counter;

            $data_storage[$type]['details'][strtoupper($ext)] = [
                'filesize' => $ext_filesize,
                'nb_files' => $ext_counter,
            ];
        }

        // Add cache size if requested and known.
        if ($this->currentConfig->addCacheToStorageChart) {
            $cache_sizes = $this->currentConfig->cacheSizes;

            if ($cache_sizes instanceof CacheSizesSnapshot && $cache_sizes->cacheSize !== null) {
                @$data_storage['Cache']['total']['filesize'] = $cache_sizes->cacheSize / 1024.0;
            }
        }

        // Calculate total storage
        $total_storage = 0.0;
        foreach ($data_storage as $value) {
            $storage_filesize = $value['total']['filesize'] ?? 0;
            $storage_filesize = is_numeric($storage_filesize) ? (float) $storage_filesize : 0.0;
            $total_storage += $storage_filesize;
        }

        // Pass data to HTML
        $template->assignContext(new IntroPageContext(
            email: $newsletter_email,
            subscribeBaseUrl: $newsletter_subscribe_base_url,
            oldNewslettersUrl: $newsletter_old_newsletters_url,
            nbPhotos: $stats->nbPhotos,
            nbAlbums: $stats->nbCategories,
            nbTags: $stats->nbTags,
            nbImageTag: $stats->nbImageTag,
            nbUsers: $stats->nbUsers,
            nbGroups: $stats->nbGroups,
            nbRates: $stats->nbRates,
            nbViews: AdminUiHelper::numberFormatHumanReadable($nb_views),
            nbPlugins: count($this->loadedPlugins->get()),
            storageUsed: str_replace(' ', '&nbsp;', $this->lang->t('%sGB', number_format($du_gb, $du_decimals))),
            uQuickSync: $this->urlService->getRootUrl() . 'admin.php?page=site_update&amp;site=1&amp;quick_sync=1&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken(),
            checkForUpdates: $this->currentConfig->dashboardCheckForUpdates,
            nbComments: $nb_comments,
            activityWeekNumber: $week_number,
            activityLastWeeks: $activity_last_weeks,
            activityChartData: $chart_data,
            activityChartNumberSizes: $size,
            dayLabels: $day_labels,
            storageTotal: $total_storage,
            storageChartData: $data_storage,
        ));

        // +-----------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-----------------------------------------------------------------------+

        $template->assignVarFromHandle('ADMIN_CONTENT', 'intro');

        // Check integrity
        $integrityRepo = EntityManagerFactory::build($conn)->getRepository(IntegrityIgnoredAnomalyEntity::class);
        $c13y = new CheckIntegrity($this->lang, $integrityRepo, $this->translator, $this->eventDispatcher, $this->pageState, $this->currentTemplate);
        // add internal checks
        new C13yInternal($this->lang, $this->sessionService, $this->eventDispatcher, $this->pageState, $this->userService, $this->currentConfig)
            ->registerHandlers();
        // check and display
        $c13y->check();
        $c13y->display();
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function cmpDay(array $a, array $b): int
    {
        return $a['x'] <=> $b['x'];
    }

    /**
     * Fetches (and 24h-caches) the latest Piwigo project news for the
     * dashboard's news panel. Kept as a private method here (not a
     * separate service) since this dashboard page is its only caller.
     *
     * `false` is a real, reachable case (a corrupted cache file --
     * `unserialize()` is natively `mixed|false`), not just theoretical.
     * The 4 non-`posted` keys stay `mixed`: they're raw `json_decode()`
     * leaves from the external `porg.news.getLatest` API, genuinely
     * unknowable beyond "whatever that endpoint returns" -- but the fixed
     * 5-key shape itself is real, checkable information a bare `mixed`
     * return threw away.
     *
     * @return array{id: mixed, subject: mixed, posted_on: mixed, posted: string, url: mixed}|list<never>|bool|null
     */
    private static function getLatestNews(Lang $lang, CurrentConfig $currentConfig, Paths $paths): array|bool|null
    {
        $news = null;

        $data_location = $currentConfig->dataLocation;
        $lang_code = $lang->langInfo()['code'] ?? null;
        $lang_code = is_string($lang_code) ? $lang_code : '';
        $cache_path = $paths->root . $data_location . 'cache/piwigo_latest_news-' . $lang_code . '.cache.php';
        if (! is_file($cache_path) or filemtime($cache_path) < strtotime('24 hours ago')) {
            $url = AppInfo::URL . '/ws.php?method=porg.news.getLatest&format=json';

            $content = HttpClientService::fetch($url, $currentConfig);
            if ($content !== false) {
                $all_news = [];

                $porg_news_getLatest = json_decode($content, true);

                if (is_array($porg_news_getLatest) && isset($porg_news_getLatest['result']) && is_array($porg_news_getLatest['result'])) {
                    $topic = $porg_news_getLatest['result'];
                    $posted_on = $topic['posted_on'] ?? null;
                    $posted_on_for_format = (is_string($posted_on) || is_int($posted_on)) ? $posted_on : false;

                    $news = [
                        'id' => $topic['topic_id'] ?? null,
                        'subject' => $topic['subject'] ?? null,
                        'posted_on' => $posted_on,
                        'posted' => DateHelper::formatDate($posted_on_for_format),
                        'url' => $topic['url'] ?? null,
                    ];
                }

                if (FilesystemHelper::mkgetdir(dirname($cache_path), $currentConfig)) {
                    file_put_contents($cache_path, serialize($news));
                }
            } else {
                return [];
            }
        }

        if ($news === null) {
            $cached_contents = file_get_contents($cache_path);
            if ($cached_contents !== false) {
                $unserialized = unserialize($cached_contents);
                if (self::isLatestNewsShape($unserialized)) {
                    $news = $unserialized;
                } elseif (is_bool($unserialized)) {
                    $news = $unserialized;
                }
            }
        }

        return $news;
    }

    /**
     * Validates a cache-file round-trip actually produced this method's own
     * shape (not just any array) before trusting it as `$news` -- a stale or
     * hand-edited cache file is a real, if unlikely, possibility.
     *
     * @phpstan-assert-if-true array{id: mixed, subject: mixed, posted_on: mixed, posted: string, url: mixed} $value
     */
    private static function isLatestNewsShape(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists('id', $value)
            && array_key_exists('subject', $value)
            && array_key_exists('posted_on', $value)
            && is_string($value['posted'] ?? null)
            && array_key_exists('url', $value);
    }
}
