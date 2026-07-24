<?php

declare(strict_types=1);

namespace Piwigo\History;

use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;

/**
 * History domain business logic: page-view search/filtering, the
 * year/month/day/hour summary rollup, autopurge, and visit logging.
 * Constructor-injects HistoryRepository (plain constructor injection,
 * same shape as PermalinkService) and, since Legacy Coupling Retirement
 * Phase 5, ConfigService for the history_sections_cache write below.
 *
 * P23 batch 8d: isLoggingAllowed()/logVisit() (ported from
 * include/functions.inc.php's do_log()/pwg_log()). AccessControl
 * (Piwigo\Auth, L2aCoreDomain) is a safe dependency from here
 * (L2bExtendedDomain) -- RateService/CommentService/SearchService already
 * establish the same precedent. logVisit()'s former bare
 * MysqliDb::query()/::insertId()/::getEnums() calls now go through
 * HistoryRepository (Legacy Coupling Retirement: DI+DBAL migration,
 * Phase 1b).
 *
 * `history_remove_summarized_column()` (originally called from
 * history_autopurge()) is deliberately NOT ported: it exists to
 * conditionally `ALTER TABLE ... DROP COLUMN summarized` against an old
 * `summarized` column that pre-Doctrine-Migration Piwigo installs may
 * still carry. This project's schema (created entirely via Doctrine
 * Migrations, see docs/plan's Version20260711150857.php and siblings)
 * never creates that column in the first place -- confirmed by grepping
 * every migration -- so the function's own `SHOW COLUMNS ... LIKE
 * "summarized"` check would always find zero rows here. Genuinely dead
 * code against this project's actual schema, not a deferred-for-later
 * gap; matches the "greenfield: remove legacy compat surfaces by default"
 * project convention.
 *
 * `history_tabsheet()` (admin UI tabsheet setup, no DB/domain logic at
 * all) also stays out of scope -- it's presentation glue, not history
 * domain logic, and is inlined directly into HistoryPageRenderer/
 * StatsPageRenderer instead (same shape as every other admin renderer's
 * own tabsheet construction, see P23 sub-batch 8b-2).
 */
final readonly class HistoryService
{
    public function __construct(
        private HistoryRepository $repo,
        private ConfigService $configService,
    ) {}

    /**
     * Does the current user must log visits in history table.
     *
     * @since 14
     */
    public function isLoggingAllowed(?int $imageId = null, ?string $imageType = null): bool
    {

        $doLog = \Piwigo\Config\CurrentConfig::logConf();
        if (AccessControl::isAdmin()) {
            $doLog = \Piwigo\Config\CurrentConfig::historyAdmin();
        }
        if (AccessControl::isAGuest()) {
            $doLog = \Piwigo\Config\CurrentConfig::historyGuest();
        }

        $doLog = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('pwg_log_allowed', $doLog, $imageId, $imageType);

        // trigger_change() hands the value through arbitrary registered event
        // handlers (mixed return); the contract of this filter is a bool, so a
        // misbehaving handler falls back to the pre-filter truthiness instead of
        // being trusted blindly.
        return is_bool($doLog) ? $doLog : (bool) $doLog;
    }

    /**
     * Logs the visit into the history table.
     *
     * $section/$category/$tagIds are the caller's own gallery-navigation
     * context (Legacy Coupling Retirement Track A batch A5.2e); $searchId
     * (batch A5.2h) is the same shape -- resolved by SearchFilterRenderer
     * (via SearchService::getValidatedSearchArray()) while rendering the
     * "search" section, only ever non-null for the GalleryController
     * caller. All 4 params are threaded explicitly because this method
     * has 3 mutually-exclusive real callers -- GalleryController/
     * PictureController (a real SectionContext always exists, pass
     * SectionContextRegistry::current()'s values), PwgCore::historyLog()
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
        $currentUser = \Piwigo\Users\CurrentUser::get();
        $lastVisit = $currentUser->rawAttributes['last_visit'] ?? null;
        $lastVisitStr = is_string($lastVisit) ? $lastVisit : (is_numeric($lastVisit) ? (string) $lastVisit : '');
        $sessionLength = \Piwigo\Config\CurrentConfig::sessionLength();

        $updateLastVisit = false;
        if (in_array($lastVisit, [null, false, 0, '0', '', []], true) or strtotime($lastVisitStr) < time() - $sessionLength) {
            $updateLastVisit = true;
        }
        $updateLastVisit = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('pwg_log_update_last_visit', $updateLastVisit);

        $userId = $currentUser->id;

        if ((bool) $updateLastVisit) {
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

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ip = is_string($ip) ? $ip : '';
        // IPv6 should not be longer than 39 chars, and that is the maximum length of
        // the column in the database, but in case it would be longer, let's truncate it.
        if (strlen($ip) > 39) {
            $ip = substr($ip, 0, 39);
        }

        $section = null;
        // If plugin developers add their own sections, Piwigo will automatically add it in the history.section enum column
        if ($pageSection !== null) {
            // set cache if not available
            if (\Piwigo\Config\CurrentConfig::historySectionsCache() === null) {
                $this->configService->confUpdateParam('history_sections_cache', $this->repo->getSectionEnumOptions(), true);
            }

            // CurrentConfig::historySectionsCache() already unserializes internally
            // and returns list<string>|null -- no further decoding needed.
            $cachedSections = \Piwigo\Config\CurrentConfig::historySectionsCache();
            if (! is_array($cachedSections)) {
                $cachedSections = $this->repo->getSectionEnumOptions();
            }

            $historySectionsCache = $cachedSections;

            \Piwigo\Config\CurrentConfig::setHistorySectionsCache($historySectionsCache);

            if (
                in_array($pageSection, $historySectionsCache, true)
                or in_array(strtolower($pageSection), array_map(strtolower(...), $historySectionsCache), true)
            ) {
                $section = $pageSection;
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
        $authKeyId = \Piwigo\Core\PageState::current()->authKeyId;

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

        $historyAutopurgeEvery = \Piwigo\Config\CurrentConfig::historyAutopurgeEvery();
        if ($historyAutopurgeEvery > 0 and $historyId % $historyAutopurgeEvery === 0) {
            $this->autopurge();
        }

        return true;
    }

    /**
     * Callback used to sort history entries.
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
            $data[] = $row;
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
            $historyMinId = $lastSummary['historyIdTo'];
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
                substr($group['date'], 0, 4), // yyyy
                substr($group['date'], 0, 7), // yyyy-mm
                substr($group['date'], 0, 10), // yyyy-mm-dd
                sprintf('%s-%02u', $group['date'], $group['hour']), // yyyy-mm-dd-hh
            ];

            foreach ($timeKeys as $timeKey) {
                if (! isset($needUpdate[$timeKey])) {
                    $needUpdate[$timeKey] = [
                        'nbPages' => 0,
                        'historyIdFrom' => $group['minId'],
                        'historyIdTo' => $group['maxId'],
                    ];
                }

                $needUpdate[$timeKey]['nbPages'] += $group['nbPages'];

                if ($group['minId'] < $needUpdate[$timeKey]['historyIdFrom']) {
                    $needUpdate[$timeKey]['historyIdFrom'] = $group['minId'];
                }

                if ($group['maxId'] > $needUpdate[$timeKey]['historyIdTo']) {
                    $needUpdate[$timeKey]['historyIdTo'] = $group['maxId'];
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
                $key = sprintf('%4u', $row['year']);
                if ($row['month'] !== null) {
                    $key .= sprintf('-%02u', $row['month']);
                    if ($row['day'] !== null) {
                        $key .= sprintf('-%02u', $row['day']);
                        if ($row['hour'] !== null) {
                            $key .= sprintf('-%02u', $row['hour']);
                        }
                    }
                }

                if (isset($needUpdate[$key])) {
                    $updates[] = [
                        'year' => $row['year'],
                        'month' => $row['month'],
                        'day' => $row['day'],
                        'hour' => $row['hour'],
                        'nbPages' => $row['nbPages'] + $needUpdate[$key]['nbPages'],
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
        $logger = \Piwigo\Core\CurrentLogger::get();

        $keepLines = \Piwigo\Config\CurrentConfig::historyAutopurgeKeepLines();
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
        $blocksize = \Piwigo\Config\CurrentConfig::historyAutopurgeBlocksize();

        $searchMin = [
            $lastSummary['historyIdTo'],
            $latestId - $keepLines,
            $oldestId + $blocksize,
        ];

        $deleteBeforeId = min($searchMin);

        $logger->debug(self::class . '::autopurge, ' . implode('/', array_map(strval(...), $searchMin)));

        $this->repo->deleteBefore($deleteBeforeId);
    }
}
