<?php

declare(strict_types=1);

namespace Piwigo\History;

/**
 * History domain business logic: page-view search/filtering, the
 * year/month/day/hour summary rollup, and autopurge. Constructor-injects
 * only HistoryRepository (plain constructor injection, same shape as
 * PermalinkService).
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
 * domain logic, and is left as a plain free function in
 * admin/include/functions_history.inc.php, unmigrated (same category as
 * Menu/Template helpers elsewhere in this project).
 */
final class HistoryService
{
    public function __construct(
        private readonly HistoryRepository $repo,
    ) {}

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
        /**
         * @var array<string, mixed> $conf
         * @var \Piwigo\Core\Logger $logger
         */
        global $conf, $logger;

        $keepLines = is_numeric($conf['history_autopurge_keep_lines'] ?? null) ? (int) $conf['history_autopurge_keep_lines'] : 0;
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
        $blocksize = is_numeric($conf['history_autopurge_blocksize'] ?? null) ? (int) $conf['history_autopurge_blocksize'] : 0;

        $searchMin = [
            $lastSummary['historyIdTo'],
            $latestId - $keepLines,
            $oldestId + $blocksize,
        ];

        $deleteBeforeId = min($searchMin);

        $logger->debug(__CLASS__ . '::autopurge, ' . implode('/', array_map(strval(...), $searchMin)));

        $this->repo->deleteBefore($deleteBeforeId);
    }
}
