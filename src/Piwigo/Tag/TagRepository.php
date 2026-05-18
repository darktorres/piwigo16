<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for the tag domain.
 *
 * All queries use Doctrine DBAL's query builder with parameter binding —
 * no raw string interpolation of user-controlled values.
 *
 * Callers remain responsible for permission filtering (get_sql_condition_FandF)
 * and for applying plugin hooks such as the RenderTagName typed event.
 */
final class TagRepository extends AbstractRepository
{
    /**
     * Return every tag row (id, name, url_name, …).
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('tags'))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return tag rows for the given ids.
     *
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('tags'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Count image associations per tag, subject to the caller's permission
     * filter. Optionally restricts to a tag subset. Result keyed by tag_id.
     *
     * @param  list<int>                                                   $tagIds  Empty = all tags
     * @param  list<mixed>                                                 $permParams
     * @param  list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType> $permTypes
     * @return array<int|string, int>
     */
    public function findTagCountersWithPermissions(
        array $tagIds,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        $sql = 'SELECT tag_id, COUNT(DISTINCT(it.image_id)) AS counter'
            . ' FROM ' . $this->table('image_category') . ' ic'
            . ' INNER JOIN ' . $this->table('image_tag') . ' it ON ic.image_id = it.image_id'
            . ' WHERE 1=1 ' . $permWhere;
        $params = $permParams;
        $types  = $permTypes;
        if ($tagIds !== []) {
            $sql .= ' AND tag_id IN (?)';
            $params[] = $tagIds;
            $types[]  = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        $sql .= ' GROUP BY tag_id';
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $out  = [];
        foreach ($rows as $row) {
            $key       = is_scalar($row['tag_id']) ? (string) $row['tag_id'] : '';
            $out[$key] = is_numeric($row['counter']) ? (int) $row['counter'] : 0;
        }
        return $out;
    }

    /**
     * Update arbitrary columns on user_cache for the given user. Used by
     * TagService to store the nb_available_tags counter.
     *
     * @param array<string, mixed> $fields
     */
    public function setUserCacheFields(int $userId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->conn->update($this->table('user_cache'), $fields, ['user_id' => $userId]);
    }

    /**
     * Return image ids matching tag intersection — used by
     * TagService::getImageIdsForTags — joins image_tag with image_category
     * and image, applies permission filter and ORDER BY suffix, with
     * optional extra WHERE and HAVING COUNT(tag_id) = N for AND-mode.
     *
     * @param  list<mixed>                                                 $permParams
     * @param  list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType> $permTypes
     * @return list<int>
     */
    public function findImageIdsForTagsWithPermissions(
        string $baseQuery,
        string $permWhere,
        bool $useAndMode,
        int $tagCountForHaving,
        ?string $extraWhere,
        string $orderBySuffix,
        array $permParams,
        array $permTypes,
    ): array {
        $sql = $baseQuery . $permWhere;
        if ($extraWhere !== null && $extraWhere !== '') {
            $sql .= " \nAND (" . $extraWhere . ')';
        }
        $sql .= "\n  GROUP BY id";
        if ($useAndMode) {
            $sql .= "\n  HAVING COUNT(DISTINCT tag_id)=" . $tagCountForHaving;
        }
        $sql .= "\n" . $orderBySuffix;
        $rows = $this->conn->executeQuery($sql, $permParams, $permTypes)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }

    /**
     * Return tags shared by all given images (common-tags query).
     *
     * @param int[]  $imageIds     Image ids to intersect tags for.
     * @param int    $maxTags      0 = no limit.
     * @param int[]  $excludedIds  Tag ids to exclude from results.
     * @return list<array<string, mixed>>
     */
    public function findCommonTags(array $imageIds, int $maxTags, array $excludedIds = []): array
    {
        if ($imageIds === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
            ->select('t.*', 'COUNT(*) AS counter')
            ->from($this->table('image_tag'), 'it')
            ->innerJoin('it', $this->table('tags'), 't', 'it.tag_id = t.id')
            ->groupBy('t.id')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->where($qb->expr()->in('it.image_id', ':imageIds'));

        if ($excludedIds !== []) {
            $qb->andWhere($qb->expr()->notIn('it.tag_id', ':excludedIds'))
               ->setParameter('excludedIds', $excludedIds, ArrayParameterType::INTEGER);
        }

        if ($maxTags > 0) {
            $qb->orderBy('counter', 'DESC')->setMaxResults($maxTags);
        } else {
            $qb->orderBy('NULL');
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return tags matching any of the supplied ids, url_names, or names.
     *
     * Replaces the legacy find_tags() string-interpolation approach with
     * parameter-bound IN clauses — eliminating the SQL-injection risk that
     * the original had for url_name / name values.
     *
     * @param int[]    $ids
     * @param string[] $urlNames
     * @param string[] $names
     * @return list<array<string, mixed>>
     */
    /**
     * Count tags whose id is in the given set.
     *
     * @param int[] $ids
     */
    public function countByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('tags'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Count tags with the given id (0 or 1). */
    public function countById(int $id): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('tags'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Count tags whose name exactly matches $name. */
    public function countByExactName(string $name): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('tags'))
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return all tag names except for the tag with the given id.
     *
     * @return string[]
     */
    public function findNamesExcluding(int $excludeId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('name')
            ->from($this->table('tags'))
            ->where('id != :id')
            ->setParameter('id', $excludeId)
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(static fn (mixed $r): string => is_scalar($r['name'] ?? null) ? (string) $r['name'] : '', $rows);
    }

    /**
     * Return the full tag row for the given id, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('tags'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Return image ids associated with a single tag.
     *
     * @return int[]
     */
    public function findImageIdsByTagId(int $tagId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from($this->table('image_tag'))
            ->where('tag_id = :tagId')
            ->setParameter('tagId', $tagId)
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'image_id'));
    }

    /**
     * Return distinct image ids associated with any of the given tag ids.
     *
     * @param int[] $tagIds
     * @return int[]
     */
    public function findDistinctImageIdsByTagIds(array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT image_id')
            ->from($this->table('image_tag'));
        $qb->where($qb->expr()->in('tag_id', ':tagIds'))
           ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'image_id'));
    }

    /**
     * Return a map of image_id → comma-separated tag_ids string for images
     * that have any of the given tags.  Used by ws_tags_getImages OR mode.
     *
     * @param int[] $tagIds
     * @param int[] $imageIds
     * @return list<array<string, mixed>>
     */
    public function findImageTagMap(array $tagIds, array $imageIds): array
    {
        if ($tagIds === [] || $imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id', 'GROUP_CONCAT(tag_id) AS tag_ids')
            ->from($this->table('image_tag'))
            ->groupBy('image_id')
            ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER)
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->where($qb->expr()->in('tag_id', ':tagIds'))
           ->andWhere($qb->expr()->in('image_id', ':imageIds'));
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param int[]    $ids
     * @param string[] $urlNames
     * @param string[] $names
     * @return list<array<string, mixed>>
     */
    public function findByIdUrlOrName(array $ids = [], array $urlNames = [], array $names = []): array
    {
        if ($ids === [] && $urlNames === [] && $names === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('tags'));

        if ($ids !== []) {
            $qb->orWhere($qb->expr()->in('id', ':ids'))
               ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        }
        if ($urlNames !== []) {
            $qb->orWhere($qb->expr()->in('url_name', ':urlNames'))
               ->setParameter('urlNames', $urlNames, ArrayParameterType::STRING);
        }
        if ($names !== []) {
            $qb->orWhere($qb->expr()->in('name', ':names'))
               ->setParameter('names', $names, ArrayParameterType::STRING);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

    /**
     * Delete all image→tag links for the given image ids.
     * Called when images are deleted or when tag assignments are overwritten.
     *
     * @param int[] $imageIds
     */
    public function deleteImageTagsByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_tag'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete image→tag links restricted to both a set of images AND a set of tags.
     * Used by add_tags() to clear existing rows before re-inserting.
     *
     * @param int[] $imageIds
     * @param int[] $tagIds
     */
    public function deleteImageTagsByImageIdsAndTagIds(array $imageIds, array $tagIds): void
    {
        if ($imageIds === [] || $tagIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_tag'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->andWhere($qb->expr()->in('tag_id', ':tagIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
           ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete all image→tag links for the given tag ids.
     *
     * @param int[] $tagIds
     */
    public function deleteImageTagsByTagIds(array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_tag'));
        $qb->where($qb->expr()->in('tag_id', ':tagIds'))
           ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete tags by id.
     *
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('tags'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return the id of the tag whose name exactly matches $name, or null.
     */
    public function findIdByExactName(string $name): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('tags'))
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Return the id of the tag whose url_name exactly matches $urlName, or null.
     */
    public function findIdByUrlName(string $urlName): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('tags'))
            ->where('url_name = :urlName')
            ->setParameter('urlName', $urlName)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Return (image_id, tag_id) pairs for the given image ids.
     * Used to track which tag assignments changed (before/after comparison).
     *
     * @param int[] $imageIds
     * @return list<array<string, mixed>>
     */
    public function findImageTagPairs(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id', 'tag_id')
            ->from($this->table('image_tag'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return tags (id, name) that have no associated images and whose
     * last-modified date is older than 24 hours.
     *
     * @return list<array<string, mixed>>
     */
    public function findOrphanTags(): array
    {
        $yesterday = new \DateTimeImmutable()->modify('-1 day')->format('Y-m-d H:i:s');
        return $this->conn->createQueryBuilder()
            ->select('t.id', 't.name')
            ->from($this->table('tags'), 't')
            ->leftJoin('t', $this->table('image_tag'), 'it', 't.id = it.tag_id')
            ->where('it.tag_id IS NULL')
            ->andWhere('t.lastmodified < :yesterday')
            ->setParameter('yesterday', $yesterday)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return id+name rows for tags assigned to a single image.
     * Used by the batch-manager and picture-modify admin pages.
     *
     * @return list<array<string, mixed>>
     */
    public function findTagsByImageId(int $imageId): array
    {
        return $this->conn->createQueryBuilder()
            ->select('t.id', 't.name')
            ->from($this->table('image_tag'), 'it')
            ->innerJoin('it', $this->table('tags'), 't', 't.id = it.tag_id')
            ->where('it.image_id = :imageId')
            ->setParameter('imageId', $imageId)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** Total number of tags. */
    public function countAll(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('tags'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Total number of image–tag associations. */
    public function countImageTags(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('image_tag'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return per-tag image counts as a map of tag_id → counter.
     *
     * @return array<string, mixed>
     */
    public function getTagCounters(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('tag_id', 'COUNT(image_id) AS counter')
            ->from($this->table('image_tag'))
            ->groupBy('tag_id')
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $key = is_scalar($row['tag_id'] ?? null) ? (string) $row['tag_id'] : '';
            $result[$key] = $row['counter'];
        }
        return $result;
    }
}
