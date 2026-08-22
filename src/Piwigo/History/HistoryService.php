<?php

declare(strict_types=1);

namespace Piwigo\History;

use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\PageState;
use Piwigo\History\Event\LogAllowed;
use Piwigo\History\Event\LogUpdateLastVisit;
use Piwigo\History\Projection\HistorySearchCriteria;
use Piwigo\History\Projection\HistorySummaryCursor;
use Piwigo\History\Projection\HistorySummaryRow;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;

/**
 * History domain business logic: page-view search/filtering, the
 * year/month/day/hour summary rollup, autopurge, and visit logging.
 * Constructor-injects HistoryRepository and ConfigService (used for the
 * history_sections_cache write below). AccessControl is a safe
 * dependency for this domain service to hold.
 */
final readonly class HistoryService
{
    /**
     * Matches `history.section`'s own column width on both platforms
     * (`varchar(20)`). A longer plugin section name is dropped rather than
     * silently truncated -- Postgres would reject it outright, and MySQL
     * under a non-strict sql_mode would truncate it, so neither engine's
     * default behaviour is one to rely on.
     */
    private const int SECTION_MAX_LENGTH = 20;

    public function __construct(
        private AccessControl $accessControl,
        private HistoryRepository $repo,
        private ConfigService $configService,
        private CurrentLogger $currentLogger,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * Does the current user must log visits in history table.
     */
    public function isLoggingAllowed(?int $imageId = null, ?string $imageType = null): bool
    {

        $doLog = $this->currentConfig->logConf;
        if ($this->accessControl->isAdmin()) {
            $doLog = $this->currentConfig->historyAdmin;
        }
        if ($this->accessControl->isAGuest()) {
            $doLog = $this->currentConfig->historyGuest;
        }

        return $this->eventDispatcher->dispatch(new LogAllowed($doLog, ImageId::tryFrom($imageId), $imageType))
            ->doLog;
    }

    /**
     * Logs the visit into the history table.
     *
     * $section/$categoryId/$tagIds are the caller's own gallery-navigation
     * context ($categoryId narrowed to the only field this method ever
     * reads -- SectionContext::$category's own id -- rather than threading
     * the whole CategoryInfo object/array through); $searchId is resolved
     * by SearchFilterRenderer
     * (via SearchService::getValidatedSearchArray()) while rendering the
     * "search" section, only ever non-null for the GalleryController
     * caller. All 4 params are threaded explicitly because this method
     * has 3 mutually-exclusive real callers -- GalleryController/
     * PictureController (a real SectionContext always exists, pass
     * SectionContextRegistry::current()'s values), `Controller\Api\
     * History\HistoryLogController` (never runs SectionPopulator, passes
     * its own request-param-derived values), and ActionController (no
     * section context available at all, passes nothing) -- so these can't be read
     * implicitly off a shared registry/global here without silently
     * breaking the other two callers. $authKeyId is read directly off
     * PageState (not threaded) since, unlike the gallery-navigation
     * values above, it's equally possible for any of the 3 callers to
     * have been reached via an auth-keyed request.
     *
     * @param list<int>|null $tagIds
     */
    public function logVisit(
        ?int $imageId = null,
        ?string $imageType = null,
        ?int $formatId = null,
        ?string $section = null,
        ?int $categoryId = null,
        ?array $tagIds = null,
        ?int $searchId = null,
    ): bool {
        $user = $this->currentUser->get();
        $lastVisit = $user->rawAttributes['last_visit'] ?? null;
        $lastVisitStr = is_string($lastVisit) ? $lastVisit : (is_numeric($lastVisit) ? (string) $lastVisit : '');
        $sessionLength = $this->currentConfig->sessionLength;

        $updateLastVisit = false;
        if (in_array($lastVisit, [null, false, 0, '0', '', []], true) or strtotime($lastVisitStr) < time() - $sessionLength) {
            $updateLastVisit = true;
        }
        $updateLastVisit = $this->eventDispatcher->dispatch(new LogUpdateLastVisit($updateLastVisit))
            ->update;

        $userId = $user->id->value;

        if ($updateLastVisit) {
            $this->repo->updateLastVisitNow($userId);
        }

        if (! $this->isLoggingAllowed($imageId, $imageType)) {
            return false;
        }

        $pageSection = $section;

        $tagsString = null;
        if ($pageSection === 'tags') {
            $tagIdsForQuery = $tagIds ?? [];
            $tagsString = implode(',', $tagIdsForQuery);

            if (strlen($tagsString) > 50) {
                // we need to truncate, mysql won't accept a too long string
                $tagsString = substr($tagsString, 0, 50);
                // the last tag_id may have been truncated itself, so we must
                // remove it — unless there's no comma at all (a single tag_id
                // >= 50 digits long, not realistic but keep the substring as-is)
                $lastComma = strrpos($tagsString, ',');
                if ($lastComma !== false) {
                    $tagsString = substr($tagsString, 0, $lastComma);
                }
            }
        }

        $ip = IpAddress::fromRemoteAddr()->value ?? '';
        // A full native IPv6 address (8 groups) is exactly 39 chars, fitting
        // `history.IP CHAR(39)` exactly -- but a valid IPv6-mapped-IPv4
        // address in its expanded dotted-quad form (e.g.
        // '0000:...:ffff:192.168.100.100') can reach 45 chars.
        // filter_var(FILTER_VALIDATE_IP) doesn't normalize/compress its
        // input, so there's no cheap way to shorten a real address like
        // that without losing information. Truncating to fit would cut
        // into the embedded IPv4 octets, producing a string that isn't a
        // real IP address at all -- HistoryRepository::insert()'s own
        // IpAddress::tryFrom() correctly rejects that truncated garbage
        // rather than storing it, and the graceful `ip_address_graceful`
        // column Type then stores it as empty (see that Type's own
        // docblock) instead of a corrupted partial address. Left
        // un-truncated here so a genuinely-representable address (the
        // overwhelming majority of real traffic, all IPv4 and all
        // standard-form IPv6) is never needlessly mangled.
        if (strlen($ip) > 39) {
            $ip = '';
        }

        $section = null;
        // A plugin may introduce its own section name. `history.section` is a
        // plain VARCHAR on both platforms, so that needs no schema change:
        // an unrecognised-but-well-formed name is simply stored, and
        // getSectionEnumOptions()'s SELECT DISTINCT picks it up from the data
        // afterwards.
        //
        // This used to issue `ALTER TABLE history CHANGE section section
        // enum(...)` from inside a page view, which required ALTER privilege
        // in production, took a metadata lock on a hot high-write table, and
        // implicitly committed. The lookup below is only about canonical
        // casing now, so a stale cache costs nothing.
        if ($pageSection !== null) {
            $cachedSections = $this->currentConfig->historySectionsCache;
            if (! is_array($cachedSections)) {
                $cachedSections = $this->repo->getSectionEnumOptions();
                $this->configService->confUpdateParam('history_sections_cache', $cachedSections, true);
                $this->currentConfig->historySectionsCache = $cachedSections;
            }

            $canonicalMatch = array_find($cachedSections, fn ($knownSection): bool => strtolower($knownSection) === strtolower($pageSection));

            if ($canonicalMatch !== null) {
                $section = $canonicalMatch;
            } elseif ((bool) preg_match('/^[a-zA-Z0-9_-]+$/', $pageSection) && strlen($pageSection) <= self::SECTION_MAX_LENGTH) {
                $section = $pageSection;
            }
        }

        $authKeyId = $this->pageState->authKeyId;

        $historyId = $this->repo->insert([
            'userId' => $userId,
            'ip' => $ip,
            'section' => $section,
            'categoryId' => $categoryId,
            'searchId' => $searchId,
            'imageId' => $imageId,
            'imageType' => $imageType,
            'formatId' => $formatId,
            'authKeyId' => $authKeyId,
            'tagsString' => $tagsString,
        ]);
        if ($historyId % 1000 === 0) {
            $this->summarize(50000);
        }

        $historyAutopurgeEvery = $this->currentConfig->historyAutopurgeEvery;
        if ($historyAutopurgeEvery > 0 and $historyId % $historyAutopurgeEvery === 0) {
            $this->autopurge();
        }

        return true;
    }

    /**
     * Callback used to sort history entries. Same cross-domain
     * generic-row-reader rationale as
     * Category\CategoryService::compareByGlobalRank() -- only
     * 'date'/'time' are read, defensively.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function historyCompare(array $a, array $b): int
    {
        $aDate = isset($a['date']) && is_string($a['date']) ? $a['date'] : '';
        $aTime = isset($a['time']) && is_string($a['time']) ? $a['time'] : '';
        $bDate = isset($b['date']) && is_string($b['date']) ? $b['date'] : '';
        $bTime = isset($b['time']) && is_string($b['time']) ? $b['time'] : '';

        return strcmp($aDate . $aTime, $bDate . $bTime);
    }

    /**
     * Performs a history search.
     *
     * @param array<int, array<string, mixed>> $data
     * @param list<string> $types every possible image_type value + 'none'
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(array $data, HistorySearchCriteria $criteria, array $types): array
    {
        $imageIdsFromFilename = $criteria->filename !== null ? $this->repo->findImageIdsByFilename($criteria->filename) : null;

        $rows = $this->repo->search($criteria->dateAfter, $criteria->dateBefore, $criteria->imageTypes, $types, $criteria->userId, $criteria->imageId, $imageIdsFromFilename, $criteria->ip);

        foreach ($rows as $row) {
            $data[] = $row->toArray();
        }

        return $data;
    }

    /**
     * Computes statistics from the history table into history_summary.
     *
     * @param int|null $maxLines to only compute the next X lines, not the
     *   whole remaining backlog
     */
    public function summarize(?int $maxLines = null): void
    {
        $lastSummary = $this->repo->findLastSummaryWithHistoryIdTo();
        if ($lastSummary instanceof HistorySummaryCursor) {
            $historyMinId = $lastSummary->historyIdTo;
        } else {
            // if we have no "reference" starting point, "0" is not the
            // right answer, because the history table may have been
            // purged already.
            $historyMinId = ($this->repo->findMinHistoryId() ?? 0) - 1;
        }

        $maxId = $maxLines !== null ? $historyMinId + $maxLines : null;
        $groups = $this->repo->findGroupedCountsSince($historyMinId, $maxId);

        // PHP coerces a purely-numeric string key (e.g. the year-only
        // bucket "2026") into a real int key, so this array's actual keys
        // are int|string, not always string.
        /** @var array<int|string, array{nbPages: int, historyIdFrom: int, historyIdTo: int}> $needUpdate */
        $needUpdate = [];
        $firstTimeKey = null;

        foreach ($groups as $i => $group) {
            $timeKeys = [
                substr($group->date, 0, 4), // yyyy
                substr($group->date, 0, 7), // yyyy-mm
                substr($group->date, 0, 10), // yyyy-mm-dd
                sprintf('%s-%02u', $group->date, $group->hour), // yyyy-mm-dd-hh
            ];

            foreach ($timeKeys as $timeKey) {
                if (! isset($needUpdate[$timeKey])) {
                    $needUpdate[$timeKey] = [
                        'nbPages' => 0,
                        'historyIdFrom' => $group->minId,
                        'historyIdTo' => $group->maxId,
                    ];
                }

                $needUpdate[$timeKey]['nbPages'] += $group->nbPages;

                if ($group->minId < $needUpdate[$timeKey]['historyIdFrom']) {
                    $needUpdate[$timeKey]['historyIdFrom'] = $group->minId;
                }

                if ($group->maxId > $needUpdate[$timeKey]['historyIdTo']) {
                    $needUpdate[$timeKey]['historyIdTo'] = $group->maxId;
                }
            }

            if ($i === 0) {
                $firstTimeKey = $timeKeys[3];
            }
        }

        // Only the oldest time_key might be already summarized (every
        // later bucket is guaranteed brand new, since $historyMinId was
        // the previous run's own cutoff), so only its 4-level hierarchy
        // needs to be looked up and possibly updated instead of inserted.
        $updates = [];
        if ($firstTimeKey !== null) {
            $tokens = explode('-', $firstTimeKey);
            $existingRows = $this->repo->findSummaryRowsForHierarchy(
                (int) $tokens[0],
                (int) $tokens[1],
                (int) $tokens[2],
                (int) $tokens[3]
            );

            foreach ($existingRows as $row) {
                $key = sprintf('%4u', $row->year);
                if ($row->month !== null) {
                    $key .= sprintf('-%02u', $row->month);
                    if ($row->day !== null) {
                        $key .= sprintf('-%02u', $row->day);
                        if ($row->hour !== null) {
                            $key .= sprintf('-%02u', $row->hour);
                        }
                    }
                }

                if (isset($needUpdate[$key])) {
                    $updates[] = [
                        'year' => $row->year,
                        'month' => $row->month,
                        'day' => $row->day,
                        'hour' => $row->hour,
                        'nbPages' => $row->nbPages + $needUpdate[$key]['nbPages'],
                        'historyIdTo' => $needUpdate[$key]['historyIdTo'],
                    ];
                    unset($needUpdate[$key]);
                }
            }
        }

        $inserts = [];
        foreach ($needUpdate as $timeKey => $summary) {
            // PHP coerces a purely-numeric string array key (e.g. the
            // year-only bucket "2026") into a real int key -- explode()
            // needs the string form back under strict_types (the original,
            // non-strict code relied on implicit int->string coercion at
            // this same call).
            $tokens = explode('-', (string) $timeKey);
            $inserts[] = [
                'year' => (int) $tokens[0],
                'month' => isset($tokens[1]) ? (int) $tokens[1] : null,
                'day' => isset($tokens[2]) ? (int) $tokens[2] : null,
                'hour' => isset($tokens[3]) ? (int) $tokens[3] : null,
                'nbPages' => $summary['nbPages'],
                'historyIdFrom' => $summary['historyIdFrom'],
                'historyIdTo' => $summary['historyIdTo'],
            ];
        }

        if ($updates !== []) {
            $this->repo->updateSummaryRows($updates);
        }

        if ($inserts !== []) {
            $this->repo->insertSummaryRows($inserts);
        }
    }

    /**
     * Smart purge on the history table: keeps some lines, purges only
     * summarized ones.
     */
    public function autopurge(): void
    {
        $logger = $this->currentLogger->get();

        $keepLines = $this->currentConfig->historyAutopurgeKeepLines;
        if ($keepLines === 0) {
            return;
        }

        // purge only if there are too many lines and the lines are summarized
        if ($this->repo->countAll() <= $keepLines) {
            return;
        }

        $lastSummary = $this->repo->findLastSummaryWithHistoryIdTo();
        if (! $lastSummary instanceof HistorySummaryCursor) {
            return; // lines not summarized, no purge
        }

        $latestId = $this->repo->findLatestHistoryId();
        if ($latestId === null) {
            return;
        }

        $oldestId = $this->repo->findOldestHistoryId() ?? 0;
        $blocksize = $this->currentConfig->historyAutopurgeBlocksize;

        $searchMin = [
            $lastSummary->historyIdTo,
            $latestId - $keepLines,
            $oldestId + $blocksize,
        ];

        $deleteBeforeId = min($searchMin);

        $logger->debug(self::class . '::autopurge, ' . implode('/', array_map(strval(...), $searchMin)));

        $this->repo->deleteBefore($deleteBeforeId);
    }

    public function getTotalPageViews(): int
    {
        return $this->repo->sumPageViews();
    }

    /**
     * @return list<HistorySummaryRow>
     */
    public function getLastByType(string $type, int $limit): array
    {
        return $this->repo->findLastByType($type, $limit);
    }

    /**
     * @return list<HistorySummaryRow>
     */
    public function getMonthlyRows(?int $limit): array
    {
        return $this->repo->findMonthlyRows($limit);
    }

    /**
     * @return list<HistorySummaryRow>
     */
    public function getDailyRowsForMonths(int $year1, int $month1, int $year2, int $month2, int $year3, int $month3): array
    {
        return $this->repo->findDailyRowsForMonths($year1, $month1, $year2, $month2, $year3, $month3);
    }

    public function getAverageDailyPageViewsSince(int $year, int $previousYear, int $afterMonth): ?float
    {
        return $this->repo->findAverageDailyPageViewsSince($year, $previousYear, $afterMonth);
    }
}
