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
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), but stays on DBAL for its
     * other, still-real blocker: a dynamic caller-supplied SqlCondition
     * (`PermissionService::getSqlConditionFandFAsCondition()`, see this
     * docblock's own next paragraph) -- same genuinely dynamic,
     * cross-cutting permission-condition blocker documented in
     * {@see \Piwigo\Image\ImageRepository::applyCondition()}'s own
     * docblock, outside Sub-phase B3/B4's scope.
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
     * Item 14 DQL audit: stays on DBAL -- joins the never-entity-mapped
     * `image_tag`-as-a-plain-table (no association from TagEntity), and
     * `t.*` selects every tags column alongside a computed `counter`
     * aggregate, a shape DQL's entity-vs-scalar select split doesn't
     * cleanly express without listing every column by name.
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
     * Item 14 DQL audit: stays on DBAL -- conditionally joins the
     * never-entity-mapped `image_category`, plus the caller-supplied raw
     * $extraImagesWhereSql/$orderBySql fragments documented above.
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
     * Item 14 DQL audit, corrected: the original note claimed
     * `SUBDATE(NOW(), INTERVAL 1 DAY)` had no native DQL function -- wrong,
     * see {@see \Piwigo\Comment\CommentRepository::countRecentComments()}'s
     * own corrected note for the real `DATE_SUB()` equivalent. Stays on
     * DBAL regardless for the real remaining reason: the `LEFT JOIN
     * image_tag ... WHERE tag_id IS NULL` anti-join has no formal ORM
     * association between `ImageTagEntity`/`TagEntity` (same "not worth
     * the added complexity this pass" call already made for
     * {@see findTagsForImage()} above).
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
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, no join DQL can't express. t.id hydrates as a real TagId VO
     * under DQL array hydration (its own 'tag_id' custom Doctrine Type
     * applies there too, same as CommentRepository::findSummariesForImage()'s
     * own documented finding for CommentId) -- extracted back to a plain
     * int below since that's this method's own return contract.
     *
     * @return list<int>
     */
    public function findIdsByNameLike(string $pattern): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.name LIKE :pattern')
            ->setParameter('pattern', $pattern)
            ->getQuery()
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if ($id instanceof TagId) {
                $ids[] = $id->value;
            }
        }

        return $ids;
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
     * Item 14 DQL audit: converted to real DQL -- single-table, dynamic
     * OR-joined LIKE conditions (ORM's own Expr::orX()/like(), same shape
     * as findByIdsUrlNamesOrNames()'s existing DQL orX() usage above), no
     * join DQL can't express. Fetches the full entity (not a t.id partial
     * select) and reads ->id off it -- avoids the array-hydration/custom-
     * Type question entirely by staying on ordinary object hydration.
     * setMaxResults(1) matches the original's own "just the first matching
     * row" fetchOne() semantics -- getOneOrNullResult() alone throws
     * NonUniqueResultException for more than one match.
     *
     * @param list<string> $patterns
     */
    public function findIdByNameLikeAnyPattern(array $patterns): ?TagId
    {
        if ($patterns === []) {
            return null;
        }

        $qb = $this->createQueryBuilder('t');

        $conditions = [];
        foreach ($patterns as $i => $pattern) {
            $placeholder = 'pattern' . $i;
            $conditions[] = 't.name LIKE :' . $placeholder;
            $qb->setParameter($placeholder, $pattern);
        }

        $entity = $qb->where(implode(' OR ', $conditions))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof TagEntity ? $entity->id : null;
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
     * Further SQL-modernization audit, Item 10: fetchTagListRows() (a fully
     * generic "execute an already-built SELECT id, name query" escape
     * hatch) deleted -- its 3 real callers (Admin\PictureModifyPageRenderer/
     * Admin\BatchManagerUnitPageRenderer, both this exact
     * image_tag-JOIN-tags-by-image_id shape) replaced with this typed
     * method.
     *
     * Item 14 DQL audit: stays on DBAL -- no ORM association exists
     * between ImageTagEntity and TagEntity (ImageTagEntity's own docblock:
     * "TagRepository ... queries it directly via DQL/QueryBuilder rather
     * than through a dedicated repository class"), so this JOIN would need
     * DQL's class-level `JOIN Entity WITH condition` form against two
     * otherwise-unrelated entities -- real but meaningfully more complex
     * than every other conversion this pass, for a method this
     * repository's own Item 10 pass already gave a clean typed shape and
     * test coverage; not worth the added complexity this pass.
     *
     * @return list<array{id: int, name: string}>
     */
    public function findTagsForImage(int $imageId): array
    {
        $imageTagTable = Tables::imageTag();
        $tagsTable = Tables::tags();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT id, name
                FROM {$imageTagTable} AS it
                    JOIN {$tagsTable} AS t ON t.id = it.tag_id
                WHERE image_id = ?
                SQL
                , [$imageId], [ParameterType::INTEGER])
            ->fetchAllAssociative();

        return array_map(self::toIdNameRow(...), $rows);
    }

    /**
     * Further SQL-modernization audit, Item 10: fetchTagListRows()'s 3rd
     * real caller -- Admin\BatchManager\FilterPanelRenderer's own
     * `tags WHERE id IN (...)` shape.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, no join DQL can't express. Deliberately NOT reusing
     * toIdNameRow() here: t.id hydrates as a TagId VO under DQL (see
     * findIdsByNameLike()'s own docblock), and toIdNameRow()'s
     * is_numeric() check would silently default that to 0 (a TagId object
     * is never is_numeric()) instead of throwing -- the exact silent-bug
     * shape this class's own SQL-modernization audit has been eliminating
     * throughout, so this reads t.id explicitly instead.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string}>
     */
    public function findTagsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('t')
            ->select('t.id', 't.name')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $tags = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            if (! $id instanceof TagId) {
                continue;
            }

            $tags[] = [
                'id' => $id->value,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            ];
        }

        return $tags;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, name: string}
     */
    private static function toIdNameRow(array $row): array
    {
        return [
            'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
            'name' => is_string($row['name']) ? $row['name'] : '',
        ];
    }

    /**
     * $ignore matches Category\CategoryRepository::massInsertGroupAccess()'s
     * own `ignore` convention -- Ws\PwgTags::merge() needs INSERT IGNORE so
     * an image already tagged with the destination tag doesn't collide with
     * one it's picking up from a merged-away tag.
     *
     * Item 14 DQL audit: stays on DBAL -- bulk multi-row INSERT via
     * BatchWriter, deliberately not per-entity persist() (an ORM insert
     * writes one row per flush(), not a single bulk statement); not a real
     * DQL-vs-DBAL question, same as every other BatchWriter call site in
     * this codebase.
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
     * Item 14 DQL audit: converted to real DQL -- single-table
     * (`image_tag`, mapped via ImageTagEntity), no WHERE/join DQL can't
     * express. it.tagId hydrates as a TagId VO (ImageTagEntity's own
     * TagIdType-mapped field, see that class's own docblock) -- read via
     * instanceof, not is_numeric() (which a TagId object always fails,
     * the same silent-bug shape findTagsByIds()'s own docblock documents).
     *
     * @return array<int, int> [tag_id => counter]
     */
    public function countImagesPerTagUnrestricted(): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('it.tagId', 'COUNT(it.imageId) AS counter')
            ->from(ImageTagEntity::class, 'it')
            ->groupBy('it.tagId')
            ->getQuery()
            ->getArrayResult();

        $counters = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $tagId = $row['tagId'] ?? null;
            if (! $tagId instanceof TagId) {
                continue;
            }

            $counters[$tagId->value] = is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0;
        }

        return $counters;
    }

    /**
     * Comma-joined tag ids per image, for images linked to any of $tagIds
     * -- Ws\PwgTags::getImages()'s own "OR mode" per-image tag list.
     *
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 3: converted to
     * real DQL -- MySQL's `GROUP_CONCAT()` now has a portable custom DQL
     * function ({@see \Piwigo\Db\DqlFunction\GroupConcatFunction}, per-
     * platform dispatch, MySQL/MariaDB verified, PostgreSQL/SQLite
     * unverified against a real install -- see that class's own docblock).
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
            ->createQueryBuilder()
            ->select('it.imageId AS image_id', 'GROUP_CONCAT(it.tagId) AS tag_ids')
            ->from(ImageTagEntity::class, 'it')
            ->where('it.tagId IN (:tagIds)')
            ->andWhere('it.imageId IN (:imageIds)')
            ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER)
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->groupBy('it.imageId')
            ->getQuery()
            ->getArrayResult();

        $byImageId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $imageId = $row['image_id'] ?? null;
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

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, no join DQL can't express.
     */
    public function existsById(int $id): bool
    {
        $value = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, no join DQL can't express.
     *
     * @param  list<int>  $ids
     */
    public function countExistingIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $value = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, no join DQL can't express.
     */
    public function existsByName(string $name): bool
    {
        $value = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Every tag name except $excludeId's own -- Ws\PwgTags::rename()'s
     * own "is the new name already taken by a different tag" check.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, no join DQL can't express. t.name is a plain string column,
     * no custom Doctrine Type involved (unlike t.id elsewhere in this
     * class).
     *
     * @return list<string>
     */
    public function findOtherNames(int $excludeId): array
    {
        $names = $this->createQueryBuilder('t')
            ->select('t.name')
            ->where('t.id != :excludeId')
            ->setParameter('excludeId', $excludeId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $names
        ));
    }

    private static function toProjection(TagEntity $entity): Tag
    {
        assert($entity->id !== null);

        return new Tag($entity->id, $entity->name, $entity->urlName, $entity->lastmodified);
    }

    /**
     * Total row count of `tags` -- Admin\InstallationStats's own
     * "nb_tags" summary figure.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, no WHERE/
     * join DQL can't express.
     */
    public function countAll(): int
    {
        $value = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Total row count of `image_tag` -- Admin\InstallationStats's own
     * "nb_image_tag" summary figure.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table (mapped via
     * ImageTagEntity), no WHERE/join DQL can't express.
     */
    public function countAllImageTagLinks(): int
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(it.imageId)')
            ->from(ImageTagEntity::class, 'it')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }
}
