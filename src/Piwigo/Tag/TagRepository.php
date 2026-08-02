<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;
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
     * Applies a permission/filter `SqlCondition` to $qb via `andWhere()`,
     * binding every one of its parameters -- same shared-helper shape as
     * `Notification\NotificationRepository::applyCondition()`
     * (`Comment\CommentRepository::applyConditions()`'s plural sibling,
     * for the single-condition case).
     */
    private static function applyCondition(QueryBuilder $qb, SqlCondition $condition): void
    {
        if ($condition->isEmpty()) {
            return;
        }

        $qb->andWhere($condition->sql);
        foreach ($condition->parameters as $name => $value) {
            $qb->setParameter($name, $value, $condition->types[$name] ?? ParameterType::STRING);
        }
    }

    /**
     * Count of distinct images per tag, restricted to visible/permitted
     * images. Cross-domain (image_category isn't Tag's own table) --
     * plain DBAL `QueryBuilder` via the entity manager's own connection,
     * not DQL.
     *
     * SQL-modernization audit: $fandFSql (a raw, already-built
     * `PermissionService::getSqlConditionFandF()` fragment) replaced with
     * a bound `SqlCondition` (its `getSqlConditionFandFAsCondition()`
     * sibling) -- {@see TagService::getAvailableTags()}, this method's
     * one real caller, migrated in the same pass. $tagIds' own CSV splice
     * also bound.
     *
     * @param array<int, int|string> $tagIds empty means "no tag_id filter" (every tag counted)
     * @return array<int, int> [tag_id => counter]
     */
    public function countImagesPerTag(array $tagIds, SqlCondition $condition): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('tag_id', 'COUNT(DISTINCT(it.image_id)) AS counter')
            ->from(Tables::imageCategory(), 'ic')
            ->innerJoin('ic', Tables::imageTag(), 'it', 'ic.image_id = it.image_id')
            ->groupBy('tag_id');

        self::applyCondition($qb, $condition);

        if ($tagIds !== []) {
            $qb->andWhere($qb->expr()->in('tag_id', ':tagIds'))
                ->setParameter('tagIds', array_map(intval(...), $tagIds), ArrayParameterType::INTEGER);
        }

        $counters = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
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
     * SQL-modernization audit: $itemsCsv/$excludedTagIdsCsv splices
     * bound. The original's trailing `ORDER BY NULL` (a MySQL
     * query-optimizer hint meaning "don't sort") when $maxTags <= 0 has
     * no `QueryBuilder` equivalent and none is needed -- omitting
     * `ORDER BY` entirely is the same "unspecified order" behavior.
     *
     * @param list<int> $items
     * @param list<int> $excludedTagIds
     * @return list<array{id: int, name: string, url_name: string, lastmodified: string, counter: int}>
     */
    public function findCommonTags(array $items, int $maxTags, array $excludedTagIds): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('t.*', 'count(*) AS counter')
            ->from(Tables::imageTag(), 'it')
            ->innerJoin('it', Tables::tags(), 't', 'it.tag_id = t.id')
            ->groupBy('t.id');

        $qb->where($qb->expr()->in('it.image_id', ':items'))
            ->setParameter('items', $items, ArrayParameterType::INTEGER);

        if ($excludedTagIds !== []) {
            $qb->andWhere($qb->expr()->notIn('it.tag_id', ':excludedTagIds'))
                ->setParameter('excludedTagIds', $excludedTagIds, ArrayParameterType::INTEGER);
        }

        if ($maxTags > 0) {
            $qb->orderBy('counter', 'DESC')
                ->setMaxResults($maxTags);
        }

        $rows = $qb->executeQuery()
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
     * Further SQL-modernization audit, Item 4: $joinSql/$whereSql/
     * $groupHavingSql used to be fully assembled by TagService::
     * getImageIdsForTags() itself and handed here pre-built -- now typed,
     * the repository builds its own join/base-where/group-having
     * internally from $tagIds/$mode/$usePermissions/$permissionCondition.
     * $extraImagesWhereSql/$extraParams/$extraTypes/$orderBySql stay raw,
     * caller-supplied opaque fragments -- the one legitimate exception,
     * not the norm: Ws\PwgTags::getImages() passes WsHelper::
     * stdImageSqlFilter()'s own SqlCondition->sql straight through as the
     * public WS API's genuine generic image-filter feature (f_min_rate
     * etc.), which can't be modeled as a fixed set of typed params.
     *
     * @param list<int> $tagIds already-unwrapped TagId values
     * @param array<string, mixed> $extraParams
     * @param array<string, ArrayParameterType|ParameterType> $extraTypes
     * @return list<int>
     */
    public function findImageIdsForTags(
        array $tagIds,
        string $mode,
        bool $usePermissions,
        SqlCondition $permissionCondition,
        string $extraImagesWhereSql = '',
        array $extraParams = [],
        array $extraTypes = [],
        string $orderBySql = ''
    ): array {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::images(), 'i');

        if ($usePermissions) {
            $qb->innerJoin('i', Tables::imageCategory(), 'ic', 'id=ic.image_id');
        }

        $qb->innerJoin('i', Tables::imageTag(), 'it', 'id=it.image_id')
            ->where($qb->expr()->in('tag_id', ':tagIds'))
            ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER)
            ->groupBy('id');

        self::applyCondition($qb, $permissionCondition);

        if ($extraImagesWhereSql !== '') {
            // Parenthesized: $extraImagesWhereSql (e.g. WsHelper::
            // stdImageSqlFilter()'s own fragment) can itself contain a
            // top-level OR, which andWhere()'s plain string concatenation
            // wouldn't otherwise scope correctly against the conditions
            // already applied above.
            $qb->andWhere('(' . $extraImagesWhereSql . ')');
            foreach ($extraParams as $name => $value) {
                $qb->setParameter($name, $value, $extraTypes[$name] ?? ParameterType::STRING);
            }
        }

        if ($mode === 'AND' && count($tagIds) > 1) {
            $qb->having('COUNT(DISTINCT tag_id) = :tagCount')
                ->setParameter('tagCount', count($tagIds));
        }

        if ($orderBySql !== '') {
            $qb->orderBy(str_replace('ORDER BY ', '', $orderBySql));
        }

        $ids = $qb->executeQuery()
            ->fetchFirstColumn();

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

    /**
     * Tag ids whose name matches $pattern (already a complete SQL LIKE
     * pattern, e.g. '%word%') -- Further SQL-modernization audit, Item 7:
     * retargeted here from SearchRepository's own generic findIdsByClause(),
     * SearchService::searchAllwords()'s own "all words" search feature
     * (distinct from quick-search's separate token-based tag lookup).
     *
     * @return list<int>
     */
    public function findIdsByNameLike(string $pattern): array
    {
        $ids = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::tags())
            ->where('name LIKE :pattern')
            ->setParameter('pattern', $pattern)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    public function findIdByUrlName(string $urlName): ?TagId
    {
        $entity = $this->findOneBy([
            'urlName' => $urlName,
        ]);

        return $entity?->id;
    }

    /**
     * First tag id whose `name` matches any of $patterns via SQL `LIKE`
     * -- TagService::tagIdFromTagName()'s own "search by extended
     * description (plugin sub name)" step, backing a plugin's
     * `get_tag_name_like_where` EventDispatcher hook.
     *
     * SQL-modernization audit: replaces the former findIdByWhereFragment(),
     * which took an already-built raw SQL WHERE-continuation fragment
     * straight from the plugin hook -- a real, unescaped SQL injection
     * when the ExtendedDescription plugin is active, confirmed against
     * its actual piwigo16-plugins source (`ed_name_like_where()`): it
     * splices the tag name into `name LIKE '...'` with zero escaping.
     * The hook's own contract changes here from "return raw SQL
     * fragments" to "return LIKE pattern VALUES" -- every pattern is now
     * a bound parameter, so no plugin handler can inject SQL structure
     * anymore regardless of what string it returns. Not a compat break
     * in practice: no 17.x plugin implements this hook yet (it's a
     * greenfield rewrite hook), so there's nothing real to migrate.
     *
     * @param list<string> $patterns
     */
    public function findIdByNameLikeAnyPattern(array $patterns): ?TagId
    {
        if ($patterns === []) {
            return null;
        }

        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::tags());

        $likeExprs = [];
        foreach ($patterns as $i => $pattern) {
            $placeholder = 'pattern' . $i;
            $likeExprs[] = $qb->expr()->like('name', ':' . $placeholder);
            $qb->setParameter($placeholder, $pattern);
        }

        $id = $qb->where($qb->expr()->or(...$likeExprs))
            ->executeQuery()
            ->fetchOne();

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
     * Renames a tag -- Ws\PwgTags::rename()'s own single-tag name/url_name
     * update. Goes through the ORM entity (unlike insert()/
     * insertWithoutTimestamp() above) since this mutates an already-persisted
     * row rather than creating one -- Doctrine's change-tracking only
     * writes the name/url_name columns that actually changed, `lastmodified`
     * untouched, same as the original raw UPDATE.
     */
    public function updateNameAndUrlName(TagId $id, string $name, string $urlName): void
    {
        $entity = $this->find($id);
        if ($entity === null) {
            return;
        }

        $entity->name = $name;
        $entity->urlName = $urlName;
        $this->getEntityManager()
            ->flush();
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
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<array<string, mixed>>
     */
    public function fetchTagListRows(string $query, array $params = [], array $types = []): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($query, $params, $types)
            ->fetchAllAssociative();
    }

    /**
     * $ignore matches Category\CategoryRepository::massInsertGroupAccess()'s
     * own `ignore` convention -- Ws\PwgTags::merge() needs INSERT IGNORE so
     * an image already tagged with the destination tag doesn't collide with
     * one it's picking up from a merged-away tag.
     *
     * @param  list<array{image_id: int|string, tag_id: int|string}>  $inserts
     */
    public function massInsertImageTags(array $inserts, bool $ignore = false): void
    {
        if ($inserts === []) {
            return;
        }

        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->massInsert(Tables::imageTag(), array_keys($inserts[0]), $inserts, [
                'ignore' => $ignore,
            ]);
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

        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('image_id', 'GROUP_CONCAT(tag_id) AS tag_ids')
            ->from(Tables::imageTag())
            ->where('tag_id IN (:tagIds)')
            ->andWhere('image_id IN (:imageIds)')
            ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER)
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->groupBy('image_id')
            ->executeQuery()
            ->fetchAllAssociative();

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
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::tags())
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchOne();

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

        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::tags())
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchOne();

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
