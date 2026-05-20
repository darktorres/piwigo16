<?php

declare(strict_types=1);

namespace Piwigo\Admin\History;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Admin\Tabsheet;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\StringUtil;
use Piwigo\Db\SqlFragment;
use Piwigo\History\HistoryPageRow;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistorySummaryDetail;
use Piwigo\Image\ImageRepository;

final readonly class HistoryAdminService
{
    public function __construct(
        private HistoryRepository $historyRepository,
        private ImageRepository $imageRepository,
    ) {
    }

    public function historyTabsheet(string $currentPage): void
    {
        $tabsheet = new Tabsheet();
        $tabsheet->setId('history');
        $tabsheet->select($currentPage);
        $tabsheet->assign();
    }

    /**
     * Resolves filename-pattern matches into concrete image_ids and
     * applies the documented image_id-wins-over-filename precedence.
     * Idempotent: callers can chain this once per request and reuse the
     * prepared array across the aggregate methods below.
     *
     * @param array<mixed> $search
     * @return array<mixed>
     */
    public function prepareSearch(array $search): array
    {
        /** @var array<string, mixed> $fields */
        $fields = is_array($search['fields'] ?? null) ? $search['fields'] : [];

        if (isset($fields['image_id'])) {
            unset($fields['filename']);
        }

        if (isset($fields['filename'])) {
            $pattern             = is_string($fields['filename']) ? $fields['filename'] : '';
            $search['image_ids'] = $this->imageRepository->findIdsByFileLike($pattern);
        }

        $search['fields'] = $fields;
        return $search;
    }

    /**
     * Returns the WHERE fragment plus bound parameters for a `history` query
     * (joined with `AND`, each clause already wrapped in parens). When
     * `$alias` is non-empty, columns are emitted as `<alias>.<col>` so the
     * fragment works inside a JOIN (see getHistoryTotalFilesizeForHigh).
     *
     * @param array<mixed> $search must already be passed through prepareSearch()
     * @param string[]|string $types image_type enum values to keep
     */
    private function buildHistoryWhereSql(array $search, array|string $types, string $alias = ''): SqlFragment
    {
        if (!is_array($types)) {
            $types = [$types];
        }
        /** @var array<string, mixed> $fields */
        $fields = is_array($search['fields'] ?? null) ? $search['fields'] : [];
        $p = $alias === '' ? '' : $alias . '.';

        $clauses     = [];
        $params      = [];
        $paramTypes  = [];

        if (isset($fields['date-after'])) {
            $clauses[]     = "{$p}date >= ?";
            $params[]      = is_string($fields['date-after']) ? $fields['date-after'] : '';
            $paramTypes[]  = ParameterType::STRING;
        }

        if (isset($fields['date-before'])) {
            $clauses[]     = "{$p}date <= ?";
            $params[]      = is_string($fields['date-before']) ? $fields['date-before'] : '';
            $paramTypes[]  = ParameterType::STRING;
        }

        if (isset($fields['types'])) {
            $local_clauses = [];
            $types_field   = is_array($fields['types']) ? $fields['types'] : [];

            foreach ($types as $type) {
                if (in_array($type, $types_field)) {
                    if ($type === 'none') {
                        $local_clauses[] = "{$p}image_type IS NULL";
                    } else {
                        $local_clauses[]   = "{$p}image_type = ?";
                        $params[]          = $type;
                        $paramTypes[]      = ParameterType::STRING;
                    }
                }
            }

            if (count($local_clauses) > 0) {
                $clauses[] = implode(' OR ', $local_clauses);
            }
        }

        if (isset($fields['user']) && $fields['user'] != -1) {
            $clauses[]    = "{$p}user_id = ?";
            $params[]     = is_numeric($fields['user']) ? (int) $fields['user'] : 0;
            $paramTypes[] = ParameterType::INTEGER;
        }

        if (isset($fields['image_id'])) {
            $clauses[]    = "{$p}image_id = ?";
            $params[]     = is_numeric($fields['image_id']) ? (int) $fields['image_id'] : 0;
            $paramTypes[] = ParameterType::INTEGER;
        }

        if (isset($fields['filename'])) {
            /** @var list<int> $image_ids */
            $image_ids = is_array($search['image_ids'] ?? null) ? array_values($search['image_ids']) : [];
            if ($image_ids === []) {
                $clauses[] = '1 = 2 ';
            } else {
                $clauses[]    = "{$p}image_id IN (?)";
                $params[]     = $image_ids;
                $paramTypes[] = ArrayParameterType::INTEGER;
            }
        }

        if (isset($fields['ip'])) {
            $clauses[]    = "{$p}IP LIKE ?";
            $params[]     = is_string($fields['ip']) ? $fields['ip'] : '';
            $paramTypes[] = ParameterType::STRING;
        }

        $clauses = StringUtil::prependAppendArrayItems($clauses, '(', ')');
        return new SqlFragment(implode("\n    AND ", $clauses), $params, $paramTypes);
    }

    /**
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     */
    public function getHistoryCount(array $search, array|string $types): int
    {
        $f = $this->buildHistoryWhereSql($search, $types);
        return $this->historyRepository->countByWhere($f->where, $f->params, $f->types);
    }

    /**
     * Sum of `images.filesize` (KiB) over filtered history rows whose
     * image_type='high' and whose image_id still resolves to an existing
     * image — the inner join replicates the original PHP behavior of
     * skipping deleted images.
     *
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     */
    public function getHistoryTotalFilesizeForHigh(array $search, array|string $types): int
    {
        $f = $this->buildHistoryWhereSql($search, $types, 'h');
        return $this->historyRepository->sumHighFilesizeByWhere($f->where, $f->params, $f->types);
    }

    /**
     * IP → hit-count map for guest rows in the filtered set.
     *
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     * @return array<string, int>
     */
    public function getHistoryGuestIpHistogram(array $search, array|string $types, int $guestId): array
    {
        $f = $this->buildHistoryWhereSql($search, $types);
        return $this->historyRepository->findIpHitCountsForUser($guestId, $f->where, $f->params, $f->types);
    }

    /**
     * user_id → hit-count map across the filtered set (includes guest;
     * caller decides whether to filter it out).
     *
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     * @return array<int, int>
     */
    public function getHistoryUserHitCounts(array $search, array|string $types): array
    {
        $f = $this->buildHistoryWhereSql($search, $types);
        return $this->historyRepository->findUserHitCountsByWhere($f->where, $f->params, $f->types);
    }

    /**
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     * @return list<int>
     */
    public function getHistoryDistinctSearchIds(array $search, array|string $types): array
    {
        $f = $this->buildHistoryWhereSql($search, $types);
        return $this->historyRepository->findDistinctSearchIdsByWhere($f->where, $f->params, $f->types);
    }

    /**
     * Paginated detail rows, newest first.
     *
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     * @return list<HistoryPageRow>
     */
    public function getHistoryPage(array $search, array|string $types, int $offset, int $limit): array
    {
        $f = $this->buildHistoryWhereSql($search, $types);
        return $this->historyRepository->findPageByWhere($f->where, $f->params, $f->types, $offset, $limit);
    }

    public function historySummarize(?int $max_lines = null): void
    {
        $last_summary = $this->historyRepository->findLastSummaryWithIdTo();
        $history_min_id = 0;
        if ($last_summary !== null) {
            $history_min_id = is_numeric($last_summary['history_id_to'] ?? null) ? (int) $last_summary['history_id_to'] : 0;
        } else {
            $minId = $this->historyRepository->findMinId();
            if ($minId !== null) {
                $history_min_id = $minId - 1;
            }
        }

        $historyRows = $this->historyRepository->findHourlyGroupingAfterId($history_min_id, $max_lines);

        $need_update    = [];
        $is_first       = true;
        $first_time_key = null;

        foreach ($historyRows as $row) {
            $time_keys = [
                substr($row->date, 0, 4),
                substr($row->date, 0, 7),
                substr($row->date, 0, 10),
                sprintf('%s-%02u', $row->date, $row->hour),
            ];

            foreach ($time_keys as $time_key) {
                if (!isset($need_update[$time_key])) {
                    $need_update[$time_key] = [
                        'nb_pages'        => 0,
                        'history_id_from' => $row->minId,
                        'history_id_to'   => $row->maxId,
                    ];
                }
                $need_update[$time_key]['nb_pages'] += $row->nbPages;

                if ($row->minId < $need_update[$time_key]['history_id_from']) {
                    $need_update[$time_key]['history_id_from'] = $row->minId;
                }
                if ($row->maxId > $need_update[$time_key]['history_id_to']) {
                    $need_update[$time_key]['history_id_to'] = $row->maxId;
                }
            }

            if ($is_first) {
                $is_first       = false;
                $first_time_key = $time_keys[3];
            }
        }

        $updates = [];
        $inserts = [];

        if (isset($first_time_key)) {
            [$year, $month, $day, $hour] = explode('-', $first_time_key);
            foreach ($this->historyRepository->findSummariesAtTime((int) $year, (int) $month, (int) $day, (int) $hour) as $row) {
                $key = sprintf('%4u', $row->year ?? 0);
                if ($row->month !== null) {
                    $key .= sprintf('-%02u', $row->month);
                    if ($row->day !== null) {
                        $key .= sprintf('-%02u', $row->day);
                        if ($row->hour !== null) {
                            $key .= sprintf('-%02u', $row->hour);
                        }
                    }
                }

                if (isset($need_update[$key])) {
                    $updates[] = new HistorySummaryDetail(
                        $row->year,
                        $row->month,
                        $row->day,
                        $row->hour,
                        $row->nbPages + $need_update[$key]['nb_pages'],
                        $need_update[$key]['history_id_to'],
                    );
                    unset($need_update[$key]);
                }
            }
        }

        foreach ($need_update as $time_key => $summary) {
            // PHP coerces numeric-string array keys to int (e.g. '2026' → 2026),
            // so the key can be int at runtime even though the docblock types
            // it as string. explode() requires a string in PHP 8.
            /** @psalm-suppress RedundantCastGivenDocblockType */
            $time_tokens = explode('-', (string) $time_key);
            $inserts[] = [
                'year'            => $time_tokens[0],
                'month'           => $time_tokens[1] ?? null,
                'day'             => $time_tokens[2] ?? null,
                'hour'            => $time_tokens[3] ?? null,
                'nb_pages'        => $summary['nb_pages'],
                'history_id_from' => $summary['history_id_from'],
                'history_id_to'   => $summary['history_id_to'],
            ];
        }

        $this->historyRepository->updateSummaryBatch($updates);
        $this->historyRepository->insertSummaryBatch($inserts);
    }

    public function historyAutopurge(): void
    {
        $logger = LoggerRegistry::current();

        if (0 == Config::historyAutopurgeKeepLines()) {
            return;
        }

        $count = $this->historyRepository->countAll();
        if ($count <= Config::historyAutopurgeKeepLines()) {
            return;
        }

        $lastSummary = $this->historyRepository->findLastSummaryWithIdTo();
        if ($lastSummary === null) {
            return;
        }
        $history_id_last_summarized = is_numeric($lastSummary['history_id_to'] ?? null) ? (int) $lastSummary['history_id_to'] : 0;

        $history_id_latest = $this->historyRepository->findMaxId();
        if ($history_id_latest === null) {
            return;
        }
        $history_id_oldest = $this->historyRepository->findMinId() ?? 0;

        $search_min = [
            $history_id_last_summarized,
            $history_id_latest - Config::historyAutopurgeKeepLines(),
            $history_id_oldest + Config::historyAutopurgeBlocksize(),
        ];

        $history_id_delete_before = min($search_min);
        $logger->debug(__FUNCTION__ . ', ' . join('/', $search_min));

        $this->historyRepository->deleteBeforeId($history_id_delete_before);
    }
}
