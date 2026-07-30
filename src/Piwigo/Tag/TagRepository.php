<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Tag\Projection\Tag;
use Piwigo\Tag\Projection\TagBrief;

/**
 * Persistence layer for the tag domain, including the Tag+Image+Category
 * cross-domain queries (image_tag/image_category joins) that P23 batch 8c
 * ported out of `include/functions_tag.inc.php` -- the Category/Image
 * domain blocker this class's docblock used to cite (task #343) no longer
 * applies now that both exist as typed modules (P19).
 *
 * @extends EntityRepository<TagEntity>
 */
final class TagRepository extends EntityRepository
{
    /**
     * Named findAllTags(), not findAll() -- EntityRepository::findAll()
     * returns list<TagEntity>, an incompatible override this class's own
     * projection-shape return can't satisfy.
     *
     * @return list<Tag>
     */
    public function findAllTags(): array
    {
        $entities = $this->createQueryBuilder('t')
            ->getQuery()
            ->getResult();

        return array_map(self::toProjection(...), $entities);
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
     * @return list<Tag>
     */
    public function findByIdsUrlNamesOrNames(array $ids, array $urlNames, array $names): array
    {
        if ($ids === [] && $urlNames === [] && $names === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('t');
        $orExpr = $qb->expr()
            ->orX();

        if ($ids !== []) {
            $orExpr->add($qb->expr()->in('t.id', ':ids'));
            $qb->setParameter('ids', array_map(intval(...), $ids));
        }
        if ($urlNames !== []) {
            $orExpr->add($qb->expr()->in('t.urlName', ':urlNames'));
            $qb->setParameter('urlNames', $urlNames);
        }
        if ($names !== []) {
            $orExpr->add($qb->expr()->in('t.name', ':names'));
            $qb->setParameter('names', $names);
        }

        $entities = $qb->where($orExpr)
            ->getQuery()
            ->getResult();

        return array_map(self::toProjection(...), $entities);
    }

    /**
     * Count of distinct images per tag, restricted to visible/permitted
     * images. $fandFSql is a raw, already-built SQL WHERE-continuation
     * fragment (PermissionService::getSqlConditionFandF()) -- same
     * "hand-written SQL on complex dynamic queries" allowance as
     * CalendarRepository::findImageIds()'s own fragment params. Also
     * cross-domain (image_category isn't Tag's own table) -- plain DBAL
     * via the entity manager's own connection, not DQL.
     *
     * @param array<int, int|string> $tagIds empty means "no tag_id filter" (every tag counted)
     * @return array<int, int> [tag_id => counter]
     */
    public function countImagesPerTag(array $tagIds, string $fandFSql): array
    {
        $imageCategoryTable = Tables::imageCategory();
        $imageTagTable = Tables::imageTag();

        $query = <<<SQL
            SELECT tag_id, COUNT(DISTINCT(it.image_id)) AS counter
            FROM {$imageCategoryTable} ic
                INNER JOIN {$imageTagTable} it
                ON ic.image_id=it.image_id
            WHERE 1=1
            {$fandFSql}
            SQL;

        if ($tagIds !== []) {
            $tagIdsCsv = implode(',', $tagIds);
            $query .= <<<SQL

                AND tag_id IN ({$tagIdsCsv})

                SQL;
        }

        $query .= <<<SQL

            GROUP BY tag_id
            SQL;

        $counters = [];
        foreach ($this->getEntityManager()->getConnection()->executeQuery($query)->fetchAllAssociative() as $row) {
            $counters[is_numeric($row['tag_id']) ? (int) $row['tag_id'] : 0] = is_numeric($row['counter']) ? (int) $row['counter'] : 0;
        }

        return $counters;
    }

    /**
     * Full tag rows for the given tag ids -- >= 1000 ids intentionally
     * falls back to every tag (matches the original's own "IN() clause too
     * large" avoidance), letting the caller filter down by its own id set.
     *
     * @param list<TagId> $ids
     * @return list<Tag>
     */
    public function findByIdsOrAll(array $ids): array
    {
        $qb = $this->createQueryBuilder('t');

        if (count($ids) < 1000) {
            $qb->where($qb->expr()->in('t.id', ':ids'))
                ->setParameter('ids', array_map(static fn (TagId $id): int => $id->value, $ids), ArrayParameterType::INTEGER);
        }

        $entities = $qb->getQuery()
            ->getResult();

        return array_map(self::toProjection(...), $entities);
    }

    /**
     * @param list<int> $items
     * @param list<int> $excludedTagIds
     * @return list<array{id: int, name: string, url_name: string, lastmodified: string, counter: int}>
     */
    public function findCommonTags(array $items, int $maxTags, array $excludedTagIds): array
    {
        $imageTagTable = Tables::imageTag();
        $tagsTable = Tables::tags();
        $itemsCsv = implode(',', $items);

        $query = <<<SQL
            SELECT t.*, count(*) AS counter
            FROM {$imageTagTable}
                INNER JOIN {$tagsTable} t ON tag_id = id
            WHERE image_id IN ({$itemsCsv})
            SQL;

        if ($excludedTagIds !== []) {
            $excludedTagIdsCsv = implode(',', $excludedTagIds);
            $query .= <<<SQL

                AND tag_id NOT IN ({$excludedTagIdsCsv})
                SQL;
        }

        $query .= <<<SQL

            GROUP BY t.id
            ORDER BY
            SQL;

        $query .= $maxTags > 0
            ? ' counter DESC LIMIT ' . $maxTags
            : ' NULL';

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'name' => is_string($row['name']) ? $row['name'] : '',
                'url_name' => is_string($row['url_name']) ? $row['url_name'] : '',
                'lastmodified' => is_string($row['lastmodified']) ? $row['lastmodified'] : '',
                'counter' => is_numeric($row['counter']) ? (int) $row['counter'] : 0,
            ],
            $rows
        );
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
        $imagesTable = Tables::images();

        $ids = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT id FROM {$imagesTable} i {$joinSql} {$whereSql} {$groupHavingSql} {$orderBySql}
                SQL
            )->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * Tags (id + name) linked to no photo, and not modified in the last day
     * (grace period so a tag freshly created/detached isn't immediately
     * swept up).
     *
     * @return list<TagBrief>
     */
    public function findOrphanTags(): array
    {
        $tagsTable = Tables::tags();
        $imageTagTable = Tables::imageTag();

        $query = <<<SQL
            SELECT
                id,
                name
            FROM {$tagsTable}
                LEFT JOIN {$imageTagTable} ON id = tag_id
            WHERE tag_id IS NULL
                AND lastmodified < SUBDATE(NOW(), INTERVAL 1 DAY)
            SQL;
        return array_map(
            TagBrief::fromRow(...),
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery($query)
                ->fetchAllAssociative()
        );
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<array{image_id: int, tag_id: TagId}>
     */
    public function findTagIdsByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('it')
            ->from(ImageTagEntity::class, 'it')
            ->where('it.imageId IN (:imageIds)')
            ->setParameter('imageIds', array_map(intval(...), $imageIds))
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (ImageTagEntity $it): array => [
                'image_id' => $it->imageId,
                'tag_id' => $it->tagId,
            ],
            $entities,
        );
    }

    /**
     * @param list<TagId> $tagIds
     * @return list<int>
     */
    public function findImageIdsForTagIds(array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('it')
            ->from(ImageTagEntity::class, 'it')
            ->where('it.tagId IN (:tagIds)')
            ->setParameter('tagIds', array_map(static fn (TagId $id): int => $id->value, $tagIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return array_map(static fn (ImageTagEntity $it): int => $it->imageId, $entities);
    }

    /**
     * @param list<TagId> $tagIds
     */
    public function deleteImageTagByTagIds(array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(ImageTagEntity::class, 'it')
            ->where('it.tagId IN (:tagIds)')
            ->setParameter('tagIds', array_map(static fn (TagId $id): int => $id->value, $tagIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param array<int, int|string> $imageIds real callers pass
     *   array_keys()'d image-id-keyed maps
     */
    public function deleteImageTagByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(ImageTagEntity::class, 'it')
            ->where('it.imageId IN (:imageIds)')
            ->setParameter('imageIds', array_map(intval(...), $imageIds))
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param array<int|string> $imageIds real caller (TagService::addTags())
     *   doesn't guarantee a list -- key type is never read below
     * @param list<TagId> $tagIds
     */
    public function deleteImageTagByImageAndTagIds(array $imageIds, array $tagIds): void
    {
        if ($imageIds === [] || $tagIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(ImageTagEntity::class, 'it')
            ->where('it.imageId IN (:imageIds)')
            ->andWhere('it.tagId IN (:tagIds)')
            ->setParameter('imageIds', array_values(array_map(intval(...), $imageIds)))
            ->setParameter('tagIds', array_map(static fn (TagId $id): int => $id->value, $tagIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param list<TagId> $tagIds
     */
    public function deleteByIds(array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(TagEntity::class, 't')
            ->where('t.id IN (:tagIds)')
            ->setParameter('tagIds', array_map(static fn (TagId $id): int => $id->value, $tagIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    public function findIdByName(string $name): ?TagId
    {
        $entity = $this->findOneBy([
            'name' => $name,
        ]);

        return $entity?->id;
    }

    public function findIdByUrlName(string $urlName): ?TagId
    {
        $entity = $this->findOneBy([
            'urlName' => $urlName,
        ]);

        return $entity?->id;
    }

    /**
     * $whereSql is a raw, already-built SQL WHERE-continuation fragment
     * (plugin-supplied extended-description sub-name matching) -- same
     * fragment-passing shape as countImagesPerTag()'s $fandFSql.
     */
    public function findIdByWhereFragment(string $whereSql): ?TagId
    {
        $tagsTable = Tables::tags();

        $id = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT id
                FROM {$tagsTable}
                WHERE {$whereSql}
                SQL)->fetchOne();

        return is_numeric($id) ? TagId::from((int) $id) : null;
    }

    public function insert(string $name, string $urlName): TagId
    {
        // lastmodified set explicitly rather than left to the schema's own
        // DEFAULT CURRENT_TIMESTAMP, which reads the real DB-server clock --
        // invisible to Env::now()'s PIWIGO_TEST_NOW freeze.
        $entity = new TagEntity($name, $urlName, Env::now()->format('Y-m-d H:i:s'));

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        assert($entity->id !== null);

        return $entity->id;
    }

    /**
     * Same insert as {@see insert()} but deliberately does NOT set
     * `lastmodified` explicitly -- matches the original
     * `tag_id_from_tag_name()`'s own `\Piwigo\Db\MysqliDb::massInserts()` call, which (unlike
     * `create_tag()`'s `\Piwigo\Db\MysqliDb::singleInsert()`) leaves it to the schema's DEFAULT
     * CURRENT_TIMESTAMP. Preserved as-is rather than unified with
     * `insert()`: a real behavioral difference between the two original
     * functions, not an oversight to silently "fix" here. Stays plain DBAL
     * (bypassing the entity) -- a Doctrine persist() always writes every
     * mapped column, so it can't leave lastmodified to the DB's own
     * DEFAULT the way this raw INSERT does.
     */
    public function insertWithoutTimestamp(string $name, string $urlName): TagId
    {
        $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->insert(Tables::tags())
            ->values([
                'name' => ':name',
                'url_name' => ':urlName',
            ])
            ->setParameter('name', $name)
            ->setParameter('urlName', $urlName)
            ->executeStatement();

        return TagId::from((int) $this->getEntityManager()
            ->getConnection()
            ->lastInsertId());
    }

    /**
     * Executes an arbitrary, already fully-built `SELECT id, name` query and
     * returns the raw rows -- real callers (PictureModifyPageRenderer/
     * BatchManagerUnitPageRenderer/FilterPanelRenderer) each build their own
     * WHERE clause against Tables::tags()/Tables::imageTag() and hand the
     * complete query in, matching the original get_taglist()'s own shape.
     *
     * 'name' is read straight into TagService::getTagList()'s own
     * EventDispatcher::triggerChange() call (by-design mixed), so a
     * precise column-name shape wouldn't buy much beyond what's already
     * documented.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchTagListRows(string $query): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($query)
            ->fetchAllAssociative();
    }

    /**
     * @param  list<array{image_id: int|string, tag_id: int|string}>  $inserts
     */
    public function massInsertImageTags(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->massInsert(Tables::imageTag(), array_keys($inserts[0]), $inserts);
        $em->clear();
    }

    /**
     * Image count per tag, every tag/image counted regardless of
     * permissions -- Admin\TagsPageRenderer's own "permissions are not
     * taken into account" listing, unlike {@see countImagesPerTag()}
     * above (that one restricts to visible/permitted images via an
     * image_category JOIN, for the public-facing WS listing).
     *
     * @return array<int, int> [tag_id => counter]
     */
    public function countImagesPerTagUnrestricted(): array
    {
        $imageTagTable = Tables::imageTag();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT tag_id, COUNT(image_id) AS counter
                FROM {$imageTagTable}
                GROUP BY tag_id
                SQL);

        $counters = [];
        foreach ($rows as $row) {
            $tagId = $row['tag_id'];
            if (! is_numeric($tagId)) {
                continue;
            }

            $counters[(int) $tagId] = is_numeric($row['counter']) ? (int) $row['counter'] : 0;
        }

        return $counters;
    }

    /**
     * Comma-joined tag ids per image, for images linked to any of $tagIds
     * -- Ws\PwgTags::getImages()'s own "OR mode" per-image tag list.
     *
     * @param  list<int>  $tagIds
     * @param  list<int>  $imageIds
     * @return array<int, string> keyed by image_id, value a comma-joined tag id list
     */
    public function findCommaJoinedTagIdsByImageIds(array $tagIds, array $imageIds): array
    {
        if ($tagIds === [] || $imageIds === []) {
            return [];
        }

        $imageTagTable = Tables::imageTag();
        $tagIdsCsv = implode(',', $tagIds);
        $imageIdsCsv = implode(',', $imageIds);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT image_id, GROUP_CONCAT(tag_id) AS tag_ids
                FROM {$imageTagTable}
                WHERE tag_id IN ({$tagIdsCsv})
                    AND image_id IN ({$imageIdsCsv})
                GROUP BY image_id
                SQL);

        $byImageId = [];
        foreach ($rows as $row) {
            $imageId = $row['image_id'];
            if (! is_numeric($imageId)) {
                continue;
            }

            $byImageId[(int) $imageId] = is_scalar($row['tag_ids'] ?? null) ? (string) $row['tag_ids'] : '';
        }

        return $byImageId;
    }

    public function findById(TagId $id): ?Tag
    {
        $entity = $this->find($id);

        return $entity === null ? null : self::toProjection($entity);
    }

    public function existsById(int $id): bool
    {
        $tagsTable = Tables::tags();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$tagsTable}
                WHERE id = {$id}
                SQL);

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * @param  list<int>  $ids
     */
    public function countExistingIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $tagsTable = Tables::tags();
        $idsCsv = implode(',', $ids);

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$tagsTable}
                WHERE id IN ({$idsCsv})
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function existsByName(string $name): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::tags())
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Every tag name except $excludeId's own -- Ws\PwgTags::rename()'s
     * own "is the new name already taken by a different tag" check.
     *
     * @return list<string>
     */
    public function findOtherNames(int $excludeId): array
    {
        return array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $this->getEntityManager()
                ->getConnection()
                ->createQueryBuilder()
                ->select('name')
                ->from(Tables::tags())
                ->where('id != :excludeId')
                ->setParameter('excludeId', $excludeId)
                ->executeQuery()
                ->fetchFirstColumn()
        );
    }

    private static function toProjection(TagEntity $entity): Tag
    {
        assert($entity->id !== null);

        return new Tag($entity->id, $entity->name, $entity->urlName, $entity->lastmodified);
    }

    /**
     * Total row count of `tags` -- Admin\InstallationStats's own
     * "nb_tags" summary figure.
     */
    public function countAll(): int
    {
        $tagsTable = Tables::tags();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*) FROM {$tagsTable}
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Total row count of `image_tag` -- Admin\InstallationStats's own
     * "nb_image_tag" summary figure.
     */
    public function countAllImageTagLinks(): int
    {
        $imageTagTable = Tables::imageTag();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*) FROM {$imageTagTable}
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }
}
