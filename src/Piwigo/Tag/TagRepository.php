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
 * and for applying plugin hooks such as trigger_change('render_tag_name', …).
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
}
