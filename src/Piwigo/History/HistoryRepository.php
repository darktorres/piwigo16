<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Core\Env;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the history domain: `history` (one row per public
 * page view) and `history_summary` (year/month/day/hour rollup, keyed by a
 * NULL-inclusive unique index -- MySQL never treats two NULLs as equal in a
 * unique index, which is why summary rows that might already exist are
 * looked up explicitly (findSummaryRowsForHierarchy()) rather than upserted
 * blindly).
 */
final class HistoryRepository extends AbstractRepository
{
    /**
     * @return array{year: int, month: ?int, day: ?int, hour: ?int, historyIdTo: int}|null
     */
    public function findLastSummaryWithHistoryIdTo(): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('year', 'month', 'day', 'hour', 'history_id_to')
            ->from(Tables::historySummary())
            ->where('history_id_to IS NOT NULL')
            ->orderBy('history_id_to', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'year' => is_numeric($row['year']) ? (int) $row['year'] : 0,
            'month' => is_numeric($row['month']) ? (int) $row['month'] : null,
            'day' => is_numeric($row['day']) ? (int) $row['day'] : null,
            'hour' => is_numeric($row['hour']) ? (int) $row['hour'] : null,
            'historyIdTo' => is_numeric($row['history_id_to']) ? (int) $row['history_id_to'] : 0,
        ];
    }

    public function findMinHistoryId(): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('MIN(id)')
            ->from(Tables::history())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * One row per (date, hour) bucket with at least one history line in
     * ]$minId, $maxId].
     *
     * @return list<array{date: string, hour: int, minId: int, maxId: int, nbPages: int}>
     */
    public function findGroupedCountsSince(int $minId, ?int $maxId): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('date', 'HOUR(time) AS hour', 'MIN(id) AS min_id', 'MAX(id) AS max_id', 'COUNT(*) AS nb_pages')
            ->from(Tables::history())
            ->where('id > :minId')
            ->groupBy('date', 'hour')
            ->orderBy('date', 'ASC')
            ->addOrderBy('hour', 'ASC')
            ->setParameter('minId', $minId);

        if ($maxId !== null) {
            $qb->andWhere('id <= :maxId')
                ->setParameter('maxId', $maxId);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'date' => is_string($row['date']) ? $row['date'] : '',
                'hour' => is_numeric($row['hour']) ? (int) $row['hour'] : 0,
                'minId' => is_numeric($row['min_id']) ? (int) $row['min_id'] : 0,
                'maxId' => is_numeric($row['max_id']) ? (int) $row['max_id'] : 0,
                'nbPages' => is_numeric($row['nb_pages']) ? (int) $row['nb_pages'] : 0,
            ],
            $rows
        );
    }

    /**
     * Existing summary rows anywhere in the (year[, month[, day[, hour]]])
     * hierarchy -- e.g. for (2026, 7, 12, 3): the year-only row, the
     * year+month row, the year+month+day row, and the year+month+day+hour
     * row, whichever of those 4 already exist.
     *
     * @return list<array{year: int, month: ?int, day: ?int, hour: ?int, nbPages: int}>
     */
    public function findSummaryRowsForHierarchy(int $year, ?int $month, ?int $day, ?int $hour): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('year', 'month', 'day', 'hour', 'nb_pages')
            ->from(Tables::historySummary())
            ->where('year = :year')
            ->setParameter('year', $year);

        $monthClause = 'month IS NULL';
        if ($month !== null) {
            $dayClause = 'day IS NULL';
            if ($day !== null) {
                $hourClause = 'hour IS NULL';
                if ($hour !== null) {
                    $hourClause = '(hour IS NULL OR hour = :hour)';
                    $qb->setParameter('hour', $hour);
                }

                $dayClause = '(day IS NULL OR (day = :day AND ' . $hourClause . '))';
                $qb->setParameter('day', $day);
            }

            $monthClause = '(month IS NULL OR (month = :month AND ' . $dayClause . '))';
            $qb->setParameter('month', $month);
        }

        $rows = $qb->andWhere($monthClause)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'year' => is_numeric($row['year']) ? (int) $row['year'] : 0,
                'month' => is_numeric($row['month']) ? (int) $row['month'] : null,
                'day' => is_numeric($row['day']) ? (int) $row['day'] : null,
                'hour' => is_numeric($row['hour']) ? (int) $row['hour'] : null,
                'nbPages' => is_numeric($row['nb_pages']) ? (int) $row['nb_pages'] : 0,
            ],
            $rows
        );
    }

    /**
     * @param list<array{year: int, month: ?int, day: ?int, hour: ?int, nbPages: int, historyIdTo: int}> $rows
     */
    public function updateSummaryRows(array $rows): void
    {
        foreach ($rows as $row) {
            $qb = $this->conn->createQueryBuilder()
                ->update(Tables::historySummary())
                ->set('nb_pages', ':nbPages')
                ->set('history_id_to', ':historyIdTo')
                ->where('year = :year')
                ->setParameter('nbPages', $row['nbPages'])
                ->setParameter('historyIdTo', $row['historyIdTo'])
                ->setParameter('year', $row['year']);

            $qb->andWhere($row['month'] === null ? 'month IS NULL' : 'month = :month');
            if ($row['month'] !== null) {
                $qb->setParameter('month', $row['month']);
            }

            $qb->andWhere($row['day'] === null ? 'day IS NULL' : 'day = :day');
            if ($row['day'] !== null) {
                $qb->setParameter('day', $row['day']);
            }

            $qb->andWhere($row['hour'] === null ? 'hour IS NULL' : 'hour = :hour');
            if ($row['hour'] !== null) {
                $qb->setParameter('hour', $row['hour']);
            }

            $qb->executeStatement();
        }
    }

    /**
     * @param list<array{year: int, month: ?int, day: ?int, hour: ?int, nbPages: int, historyIdFrom: int, historyIdTo: int}> $rows
     */
    public function insertSummaryRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->conn->createQueryBuilder()
                ->insert(Tables::historySummary())
                ->values([
                    'year' => ':year',
                    'month' => ':month',
                    'day' => ':day',
                    'hour' => ':hour',
                    'nb_pages' => ':nbPages',
                    'history_id_from' => ':historyIdFrom',
                    'history_id_to' => ':historyIdTo',
                ])
                ->setParameter('year', $row['year'])
                ->setParameter('month', $row['month'])
                ->setParameter('day', $row['day'])
                ->setParameter('hour', $row['hour'])
                ->setParameter('nbPages', $row['nbPages'])
                ->setParameter('historyIdFrom', $row['historyIdFrom'])
                ->setParameter('historyIdTo', $row['historyIdTo'])
                ->executeStatement();
        }
    }

    public function countAll(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::history())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function findLatestHistoryId(): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::history())
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    public function findOldestHistoryId(): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::history())
            ->orderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    public function deleteBefore(int $id): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::history())
            ->where('id < :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * @return list<int>
     */
    public function findImageIdsByFilename(string $filenamePattern): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::images())
            ->where('file LIKE :pattern')
            ->setParameter('pattern', $filenamePattern)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $ids
        );
    }

    /**
     * History lines matching every given filter, each filter applied only
     * when its criterion is present (matches get_history()'s own
     * conditional clause-building).
     *
     * @param ?list<string> $imageTypes the search's own requested subset
     *   of $types (null when no type filter was requested at all)
     * @param list<string> $types every possible image_type value + 'none'
     *   (matched against a NULL image_type)
     * @param ?list<int> $imageIdsFromFilename
     * @return list<array<string, mixed>>
     */
    public function search(
        ?string $dateAfter,
        ?string $dateBefore,
        ?array $imageTypes,
        array $types,
        ?int $userId,
        ?int $imageId,
        ?array $imageIdsFromFilename,
        ?string $ip
    ): array {
        $qb = $this->conn->createQueryBuilder()
            ->select('date', 'time', 'user_id', 'IP', 'section', 'category_id', 'search_id', 'tag_ids', 'image_id', 'image_type')
            ->from(Tables::history());

        if ($dateAfter !== null) {
            $qb->andWhere('date >= :dateAfter')
                ->setParameter('dateAfter', $dateAfter);
        }

        if ($dateBefore !== null) {
            $qb->andWhere('date <= :dateBefore')
                ->setParameter('dateBefore', $dateBefore);
        }

        if ($imageTypes !== null) {
            $typeClauses = [];
            foreach ($types as $i => $type) {
                if (! in_array($type, $imageTypes, true)) {
                    continue;
                }

                if ($type === 'none') {
                    $typeClauses[] = 'image_type IS NULL';
                } else {
                    $paramName = 'type' . $i;
                    $typeClauses[] = 'image_type = :' . $paramName;
                    $qb->setParameter($paramName, $type);
                }
            }

            if ($typeClauses !== []) {
                $qb->andWhere('(' . implode(' OR ', $typeClauses) . ')');
            }
        }

        if ($userId !== null) {
            $qb->andWhere('user_id = :userId')
                ->setParameter('userId', $userId);
        }

        if ($imageId !== null) {
            $qb->andWhere('image_id = :imageId')
                ->setParameter('imageId', $imageId);
        }

        if ($imageIdsFromFilename !== null) {
            if ($imageIdsFromFilename === []) {
                // a filename filter was given but matched no image: always false
                $qb->andWhere('1 = 2');
            } else {
                $qb->andWhere('image_id IN (:imageIds)')
                    ->setParameter('imageIds', $imageIdsFromFilename, ArrayParameterType::INTEGER);
            }
        }

        if ($ip !== null) {
            $qb->andWhere('IP LIKE :ip')
                ->setParameter('ip', $ip);
        }

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    public function updateLastVisitNow(int $userId): void
    {
        // Env::now() rather than SQL's NOW() -- matches
        // SessionRepository/CommentRepository's own established reasoning
        // (invisible to PIWIGO_TEST_NOW). `lastmodified = lastmodified` is
        // a deliberate self-assignment (see Auth\AuthRepository::
        // saveLastVisitFromHistory()'s own docblock for why).
        $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('last_visit', ':now')
            ->set('lastmodified', 'lastmodified')
            ->where('user_id = :userId')
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Parses the `history`.`section` column's current ENUM options
     * (`enum('blue','green','black')` -> `['blue', 'green', 'black']`),
     * matching the original MysqliDb::getEnums()'s own `DESC` + string-parse
     * approach -- no cross-driver-portable DBAL equivalent exists for
     * reading a live ENUM definition.
     *
     * @return list<string>
     */
    public function getSectionEnumOptions(): array
    {
        $rows = $this->conn->executeQuery('DESC ' . Tables::history())->fetchAllAssociative();

        foreach ($rows as $row) {
            if (($row['Field'] ?? null) === 'section') {
                $type = is_string($row['Type'] ?? null) ? $row['Type'] : '';
                $options = explode(',', substr($type, 5, -1));

                return array_map(static fn (string $option): string => str_replace('\'', '', $option), $options);
            }
        }

        return [];
    }

    /**
     * Widens the `section` column's ENUM definition to include every
     * option in $options -- $options is always either DB-introspected
     * values (getSectionEnumOptions()'s own output) or a single new value
     * already regex-validated by the caller (HistoryService::logVisit(),
     * `/^[a-zA-Z0-9_-]+$/`), never raw user input, matching the original's
     * own trust boundary for this DDL statement.
     *
     * @param  list<string>  $options
     */
    public function alterSectionEnum(array $options): void
    {
        $enumList = implode(',', array_map(static fn (string $option): string => "'" . $option . "'", array_unique($options)));

        $this->conn->executeStatement(
            'ALTER TABLE ' . Tables::history() . ' CHANGE section section enum(' . $enumList . ') DEFAULT NULL'
        );
    }

    /**
     * @param array{
     *   userId: int, ip: string, section: ?string, categoryId: ?int,
     *   searchId: ?int, imageId: ?int, imageType: ?string,
     *   formatId: int|string|null, authKeyId: ?int, tagsString: ?string,
     * } $data
     */
    public function insert(array $data): int
    {
        $now = Env::now();

        $this->conn->createQueryBuilder()
            ->insert(Tables::history())
            ->values([
                'date' => ':date',
                'time' => ':time',
                'user_id' => ':userId',
                'IP' => ':ip',
                'section' => ':section',
                'category_id' => ':categoryId',
                'search_id' => ':searchId',
                'image_id' => ':imageId',
                'image_type' => ':imageType',
                'format_id' => ':formatId',
                'auth_key_id' => ':authKeyId',
                'tag_ids' => ':tagsString',
            ])
            ->setParameter('date', $now->format('Y-m-d'))
            ->setParameter('time', $now->format('H:i:s'))
            ->setParameter('userId', $data['userId'])
            ->setParameter('ip', $data['ip'])
            ->setParameter('section', $data['section'])
            ->setParameter('categoryId', $data['categoryId'])
            ->setParameter('searchId', $data['searchId'])
            ->setParameter('imageId', $data['imageId'])
            ->setParameter('imageType', $data['imageType'])
            ->setParameter('formatId', $data['formatId'])
            ->setParameter('authKeyId', $data['authKeyId'])
            ->setParameter('tagsString', $data['tagsString'])
            ->executeStatement();

        return (int) $this->conn->lastInsertId();
    }
}
