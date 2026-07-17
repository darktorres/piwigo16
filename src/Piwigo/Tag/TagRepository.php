<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Piwigo\Core\Env;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the tag domain, including the Tag+Image+Category
 * cross-domain queries (image_tag/image_category joins) that P23 batch 8c
 * ported out of `include/functions_tag.inc.php` -- the Category/Image
 * domain blocker this class's docblock used to cite (task #343) no longer
 * applies now that both exist as typed modules (P19).
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

    /**
     * Count of distinct images per tag, restricted to visible/permitted
     * images. $fandFSql is a raw, already-built SQL WHERE-continuation
     * fragment (PermissionService::getSqlConditionFandF()) -- same
     * "hand-written SQL on complex dynamic queries" allowance as
     * CalendarRepository::findImageIds()'s own fragment params.
     *
     * @param array<int, int|string> $tagIds empty means "no tag_id filter" (every tag counted)
     * @return array<int, int> [tag_id => counter]
     */
    public function countImagesPerTag(array $tagIds, string $fandFSql): array
    {
        $query = '
SELECT tag_id, COUNT(DISTINCT(it.image_id)) AS counter
  FROM ' . Tables::imageCategory() . ' ic
    INNER JOIN ' . Tables::imageTag() . ' it
    ON ic.image_id=it.image_id
  WHERE 1=1
  ' . $fandFSql;

        if ($tagIds !== []) {
            $query .= '
    AND tag_id IN (' . implode(',', $tagIds) . ')
';
        }

        $query .= '
  GROUP BY tag_id';

        $counters = [];
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $counters[(int) $row['tag_id']] = (int) $row['counter'];
        }

        return $counters;
    }

    /**
     * Full tag rows for the given tag ids -- >= 1000 ids intentionally
     * falls back to every tag (matches the original's own "IN() clause too
     * large" avoidance), letting the caller filter down by its own id set.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findByIdsOrAll(array $ids): array
    {
        $query = 'SELECT * FROM ' . Tables::tags();

        if (count($ids) < 1000) {
            $query .= ' WHERE id IN (' . implode(',', $ids) . ')';
        }

        return $this->conn->executeQuery($query)
            ->fetchAllAssociative();
    }

    /**
     * @param list<int> $items
     * @param list<int> $excludedTagIds
     * @return list<array<string, mixed>>
     */
    public function findCommonTags(array $items, int $maxTags, array $excludedTagIds): array
    {
        $query = '
SELECT t.*, count(*) AS counter
  FROM ' . Tables::imageTag() . '
    INNER JOIN ' . Tables::tags() . ' t ON tag_id = id
  WHERE image_id IN (' . implode(',', $items) . ')';

        if ($excludedTagIds !== []) {
            $query .= '
    AND tag_id NOT IN (' . implode(',', $excludedTagIds) . ')';
        }

        $query .= '
  GROUP BY t.id
  ORDER BY ';

        $query .= $maxTags > 0
            ? 'counter DESC LIMIT ' . $maxTags
            : 'NULL';

        return $this->conn->executeQuery($query)
            ->fetchAllAssociative();
    }

    /**
     * $joinSql/$whereSql/$groupHavingSql/$orderBySql are raw, already-built
     * SQL fragments assembled by TagService::getImageIdsForTags() -- same
     * fragment-passing shape as CalendarRepository::findImageIds().
     *
     * @return list<int>
     */
    public function findImageIdsForTags(string $joinSql, string $whereSql, string $groupHavingSql, string $orderBySql): array
    {
        $ids = $this->conn->executeQuery(
            'SELECT id FROM ' . Tables::images() . ' i ' . $joinSql . ' ' . $whereSql . ' ' . $groupHavingSql . ' ' . $orderBySql
        )->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * Tags (id + name) linked to no photo, and not modified in the last day
     * (grace period so a tag freshly created/detached isn't immediately
     * swept up).
     *
     * @return list<array{id: string, name: string}>
     */
    public function findOrphanTags(): array
    {
        $query = '
SELECT
    id,
    name
  FROM ' . Tables::tags() . '
    LEFT JOIN ' . Tables::imageTag() . ' ON id = tag_id
  WHERE tag_id IS NULL
    AND lastmodified < SUBDATE(NOW(), INTERVAL 1 DAY)
;';
        $orphan_tags = [];
        foreach ($this->conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $orphan_tags[] = [
                'id' => is_scalar($row['id']) ? (string) $row['id'] : '',
                'name' => is_scalar($row['name']) ? (string) $row['name'] : '',
            ];
        }
        return $orphan_tags;
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<array<string, mixed>> [image_id, tag_id]
     */
    public function findTagIdsByImageIds(array $imageIds): array
    {
        $query = '
SELECT
    image_id,
    tag_id
  FROM ' . Tables::imageTag() . '
  WHERE image_id IN (' . implode(',', $imageIds) . ')
;';
        return $this->conn->executeQuery($query)
            ->fetchAllAssociative();
    }

    /**
     * @param array<int, int|string> $tagIds
     * @return list<int>
     */
    public function findImageIdsForTagIds(array $tagIds): array
    {
        $query = '
SELECT
    image_id
  FROM ' . Tables::imageTag() . '
  WHERE tag_id IN (' . implode(',', $tagIds) . ')
;';
        // image_id is Tables::imageTag()'s NOT NULL foreign key.
        return array_map(intval(...), $this->conn->executeQuery($query)->fetchFirstColumn());
    }

    /**
     * @param array<int, int|string> $tagIds
     */
    public function deleteImageTagByTagIds(array $tagIds): void
    {
        $this->conn->executeStatement('
DELETE
  FROM ' . Tables::imageTag() . '
  WHERE tag_id IN (' . implode(',', $tagIds) . ')
;');
    }

    /**
     * @param array<int, int|string> $imageIds real callers pass
     *   array_keys()'d image-id-keyed maps
     */
    public function deleteImageTagByImageIds(array $imageIds): void
    {
        $this->conn->executeStatement('
DELETE
  FROM ' . Tables::imageTag() . '
  WHERE image_id IN (' . implode(',', $imageIds) . ')
;');
    }

    /**
     * @param array<int|string> $imageIds real caller (TagService::addTags())
     *   doesn't guarantee a list -- key type is never read below
     * @param array<int|string> $tagIds
     */
    public function deleteImageTagByImageAndTagIds(array $imageIds, array $tagIds): void
    {
        $this->conn->executeStatement('
DELETE
  FROM ' . Tables::imageTag() . '
  WHERE image_id IN (' . implode(',', $imageIds) . ')
    AND tag_id IN (' . implode(',', $tagIds) . ')
;');
    }

    /**
     * @param array<int, int|string> $tagIds
     */
    public function deleteByIds(array $tagIds): void
    {
        $this->conn->executeStatement('
DELETE
  FROM ' . Tables::tags() . '
  WHERE id IN (' . implode(',', $tagIds) . ')
;');
    }

    public function findIdByName(string $name): ?int
    {
        $id = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::tags())
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($id) ? (int) $id : null;
    }

    public function findIdByUrlName(string $urlName): ?int
    {
        $id = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::tags())
            ->where('url_name = :urlName')
            ->setParameter('urlName', $urlName)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * $whereSql is a raw, already-built SQL WHERE-continuation fragment
     * (plugin-supplied extended-description sub-name matching) -- same
     * fragment-passing shape as countImagesPerTag()'s $fandFSql.
     */
    public function findIdByWhereFragment(string $whereSql): ?int
    {
        $id = $this->conn->executeQuery('
SELECT id
  FROM ' . Tables::tags() . '
  WHERE ' . $whereSql . '
;')->fetchOne();

        return is_numeric($id) ? (int) $id : null;
    }

    public function insert(string $name, string $urlName): int
    {
        // lastmodified set explicitly rather than left to the schema's own
        // DEFAULT CURRENT_TIMESTAMP, which reads the real DB-server clock --
        // invisible to Env::now()'s PIWIGO_TEST_NOW freeze.
        $this->conn->createQueryBuilder()
            ->insert(Tables::tags())
            ->values([
                'name' => ':name',
                'url_name' => ':urlName',
                'lastmodified' => ':lastmodified',
            ])
            ->setParameter('name', $name)
            ->setParameter('urlName', $urlName)
            ->setParameter('lastmodified', Env::now()->format('Y-m-d H:i:s'))
            ->executeStatement();

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Same insert as {@see insert()} but deliberately does NOT set
     * `lastmodified` explicitly -- matches the original
     * `tag_id_from_tag_name()`'s own `\Piwigo\Db\MysqliDb::massInserts()` call, which (unlike
     * `create_tag()`'s `\Piwigo\Db\MysqliDb::singleInsert()`) leaves it to the schema's DEFAULT
     * CURRENT_TIMESTAMP. Preserved as-is rather than unified with
     * `insert()`: a real behavioral difference between the two original
     * functions, not an oversight to silently "fix" here.
     */
    public function insertWithoutTimestamp(string $name, string $urlName): int
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::tags())
            ->values([
                'name' => ':name',
                'url_name' => ':urlName',
            ])
            ->setParameter('name', $name)
            ->setParameter('urlName', $urlName)
            ->executeStatement();

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Executes an arbitrary, already fully-built `SELECT id, name` query and
     * returns the raw rows -- real callers (PictureModifyPageRenderer/
     * BatchManagerUnitPageRenderer/FilterPanelRenderer) each build their own
     * WHERE clause against Tables::tags()/Tables::imageTag() and hand the
     * complete query in, matching the original get_taglist()'s own shape.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchTagListRows(string $query): array
    {
        return $this->conn->executeQuery($query)
            ->fetchAllAssociative();
    }
}
