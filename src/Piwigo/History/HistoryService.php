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
use Piwigo\Event\Picture\PwgLogAllowed;
use Piwigo\Event\Picture\PwgLogUpdateLastVisit;
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

        return $this->eventDispatcher->dispatchChange(new PwgLogAllowed($doLog, ImageId::tryFrom($imageId), $imageType))
            ->doLog;
    }

    /**
     * Logs the visit into the history table.
     *
     * $section/$category/$tagIds are the caller's own gallery-navigation
     * context; $searchId is resolved by SearchFilterRenderer
     * (via SearchService::getValidatedSearchArray()) while rendering the
     * "search" section, only ever non-null for the GalleryController
     * caller. All 4 params are threaded explicitly because this method
     * has 3 mutually-exclusive real callers -- GalleryController/
     * PictureController (a real SectionContext always exists, pass
     * SectionContextRegistry::current()'s values), Core::historyLog()
     * (a WS method that never runs SectionPopulator, passes its own
     * WS-param-derived values), and ActionController (no section context
     * available at all, passes nothing) -- so these can't be read
     * implicitly off a shared registry/global here without silently
     * breaking the other two callers. $authKeyId is read directly off
     * PageState (not threaded) since, unlike the gallery-navigation
     * values above, it's equally possible for any of the 3 callers to
     * have been reached via an auth-keyed request.
     *
     * @param array<string, mixed>|null $category
     * @param list<int>|null $tagIds
     */
    public function logVisit(
        ?int $imageId = null,
        ?string $imageType = null,
        int|string|null $formatId = null,
        ?string $section = null,
        ?array $category = null,
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
        $updateLastVisit = $this->eventDispatcher->dispatchChange(new PwgLogUpdateLastVisit($updateLastVisit))
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
        // If plugin developers add their own sections, Piwigo will automatically add it in the history.section enum column
        if ($pageSection !== null) {
            // set cache if not available
            if ($this->currentConfig->historySectionsCache === null) {
                $this->configService->confUpdateParam('history_sections_cache', $this->repo->getSectionEnumOptions(), true);
            }

            // CurrentConfig::historySectionsCache() already unserializes internally
            // and returns list<string>|null -- no further decoding needed.
            $cachedSections = $this->currentConfig->historySectionsCache;
            if (! is_array($cachedSections)) {
                $cachedSections = $this->repo->getSectionEnumOptions();
            }

            $historySectionsCache = $cachedSections;

            $this->currentConfig->historySectionsCache = $historySectionsCache;

            // Real bug found live -- a case-insensitive
            // match used to store $pageSection verbatim, relying on MySQL's
            // own ENUM-column side effect (inserting a value that matches
            // an enum member case-insensitively is silently stored/read
            // back in that member's own canonical casing) to normalize it.
            // PostgreSQL's portable `section` column is a plain VARCHAR
            // (this column's set of valid values is plugin-extensible at
            // runtime, so it was deliberately never given a static CHECK
            // constraint the way image_type's fixed set was) with no such
            // implicit normalization -- stores whatever string it's given,
            // verbatim. Resolving to the canonical cached casing explicitly
            // here makes the real, intended behavior (treat a
            // case-insensitive match as *the same* section) portable
            // instead of an accidental MySQL-ENUM-specific side effect.
            $canonicalMatch = null;
            foreach ($historySectionsCache as $knownSection) {
                if (strtolower($knownSection) === strtolower($pageSection)) {
                    $canonicalMatch = $knownSection;

                    break;
                }
            }

            if ($canonicalMatch !== null) {
                $section = $canonicalMatch;
            } elseif ((bool) preg_match('/^[a-zA-Z0-9_-]+$/', $pageSection)) {
                $historySections = $this->repo->getSectionEnumOptions();
                $historySections[] = $pageSection;

                // alter history table structure, to include a new section
                $this->repo->alterSectionEnum($historySections);

                // and refresh cache
                $this->configService->confUpdateParam('history_sections_cache', $this->repo->getSectionEnumOptions(), true);

                $section = $pageSection;
            }
        }

        // $user['id'] is read from a loosely-typed global bag fed by DB rows
        // (string|null); narrow to the scalar the column actually stores
        // before splicing into SQL.
        $categoryForQuery = $category ?? [];
        $categoryId = $categoryForQuery['id'] ?? null;
        $categoryId = is_numeric($categoryId) ? (int) $categoryId : null;
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
     * @param array<string, mixed> $search $search['fields'] comes from
     *   unserialize()'d search rules (see ws_history_search()), so it's
     *   only provably an array, not that its values have the expected
     *   scalar/array shapes -- narrowed on use, same as the original.
     * @param list<string> $types every possible image_type value + 'none'
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(array $data, array $search, array $types): array
    {
        $fields = isset($search['fields']) && is_array($search['fields']) ? $search['fields'] : [];

        $filename = isset($fields['filename']) && is_string($fields['filename']) ? $fields['filename'] : null;
        $imageIdsFromFilename = $filename !== null ? $this->repo->findImageIdsByFilename($filename) : null;

        $dateAfter = isset($fields['date-after']) && is_string($fields['date-after']) ? $fields['date-after'] : null;
        $dateBefore = isset($fields['date-before']) && is_string($fields['date-before']) ? $fields['date-before'] : null;

        $imageTypes = null;
        if (isset($fields['types']) && is_array($fields['types'])) {
            $imageTypes = array_values(array_filter($fields['types'], is_string(...)));
        }

        $userId = isset($fields['user']) && is_numeric($fields['user']) && (int) $fields['user'] !== -1
            ? (int) $fields['user']
            : null;
        $imageId = isset($fields['image_id']) && is_numeric($fields['image_id']) ? (int) $fields['image_id'] : null;
        $ip = isset($fields['ip']) && is_string($fields['ip']) ? $fields['ip'] : null;

        $rows = $this->repo->search($dateAfter, $dateBefore, $imageTypes, $types, $userId, $imageId, $imageIdsFromFilename, $ip);

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
        if ($lastSummary !== null) {
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
        if ($lastSummary === null) {
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
     * @return list<array{year: int, month: ?int, day: ?int, hour: ?int, nb_pages: int}>
     */
    public function getLastByType(string $type, int $limit): array
    {
        return array_map(
            static fn (HistorySummaryRow $row): array => $row->toArray(),
            $this->repo->findLastByType($type, $limit)
        );
    }

    /**
     * @return list<array{year: int, month: ?int, day: ?int, hour: ?int, nb_pages: int}>
     */
    public function getMonthlyRows(?int $limit): array
    {
        return array_map(
            static fn (HistorySummaryRow $row): array => $row->toArray(),
            $this->repo->findMonthlyRows($limit)
        );
    }

    /**
     * @return list<array{year: int, month: ?int, day: ?int, hour: ?int, nb_pages: int}>
     */
    public function getDailyRowsForMonths(int $year1, int $month1, int $year2, int $month2, int $year3, int $month3): array
    {
        return array_map(
            static fn (HistorySummaryRow $row): array => $row->toArray(),
            $this->repo->findDailyRowsForMonths($year1, $month1, $year2, $month2, $year3, $month3)
        );
    }

    public function getAverageDailyPageViewsSince(int $year, int $previousYear, int $afterMonth): ?float
    {
        return $this->repo->findAverageDailyPageViewsSince($year, $previousYear, $afterMonth);
    }
}
