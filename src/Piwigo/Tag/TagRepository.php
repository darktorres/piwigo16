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
