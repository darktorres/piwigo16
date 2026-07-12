<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the tag domain's self-contained slice: plain reads
 * of the `tags` table itself.
 *
 * get_nb_available_tags()/get_available_tags()/get_image_ids_for_tags()/
 * get_common_tags() stay procedural for now -- all four are fundamentally
 * Tag+Image+Category cross-domain queries (image_tag/image_category joins,
 * get_sql_condition_FandF() permission filtering) that need the Category/
 * Image domain, which doesn't exist as a typed module yet (P19). Same
 * blocker as build_user()/getuserdata() in the Users domain -- see
 * task #343.
 */
final class TagRepository extends AbstractRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::tags())
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Tags matching any of $ids, $urlNames, or $names. Returns [] when all
     * three are empty (matches the original: no WHERE clause built at all
     * means no query is run).
     *
     * @param array<int|string, int|string> $ids functions_url.inc.php's
     *   parse_section_url() passes raw preg_match() capture strings, never
     *   cast to int -- only used in an IN() SQL context, so numeric
     *   strings work identically
     * @param array<int|string, string> $urlNames
     * @param array<int|string, string> $names
     * @return list<array<string, mixed>>
     */
    public function findByIdsUrlNamesOrNames(array $ids, array $urlNames, array $names): array
    {
        if ($ids === [] && $urlNames === [] && $names === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::tags());

        $whereClauses = [];
        if ($ids !== []) {
            $whereClauses[] = 'id IN (' . implode(',', array_map(strval(...), $ids)) . ')';
        }
        if ($urlNames !== []) {
            $qb->setParameter('urlNames', $urlNames, \Doctrine\DBAL\ArrayParameterType::STRING);
            $whereClauses[] = 'url_name IN (:urlNames)';
        }
        if ($names !== []) {
            $qb->setParameter('names', $names, \Doctrine\DBAL\ArrayParameterType::STRING);
            $whereClauses[] = 'name IN (:names)';
        }

        return $qb->where(implode(' OR ', $whereClauses))
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
