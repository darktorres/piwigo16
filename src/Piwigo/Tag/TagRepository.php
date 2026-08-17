<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageFilterCriteria;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Permission\SqlCondition;
use Piwigo\Tag\Projection\ImageTagLink;
use Piwigo\Tag\Projection\Tag;
use Piwigo\Tag\Projection\TagBrief;
use Piwigo\Tag\Projection\TagIdName;

/**
 * Persistence layer for the tag domain, including Tag+Image+Category
 * cross-domain queries (image_tag/image_category joins).
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

    /**
     * Count of distinct images per tag, restricted to visible/permitted
     * images. Cross-domain (image_category isn't Tag's own table): both
     * `image_category` ({@see \Piwigo\Image\ImageCategoryEntity}) and
     * `image_tag` ({@see ImageTagEntity}) are mapped entities, so this
     * runs as real DQL, with `Join::WITH` covering the join since there's
     * no formal association between them.
     *
     * {@see PermissionCriteria} applies forbiddenCategoryIds/
     * visibleCategoryIds against `ic.category` and imageAccessIds
     * against `ic.image` -- bare owning-side association paths, not the
     * old scalar `categoryId`/`imageId` columns. `it.tagId` hydrates as a `TagId` VO under
     * `getArrayResult()`, the same gotcha documented on
     * {@see findTagsForImage()}. $tagIds is bound as a parameter, not
     * spliced.
     *
     * @param array<int, int|string> $tagIds empty means "no tag_id filter" (every tag counted)
     * @return array<int, int> [tag_id => counter]
     */
    public function countImagesPerTag(array $tagIds, PermissionCriteria $criteria): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('it.tagId', 'COUNT(DISTINCT it.imageId) AS counter')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(ImageTagEntity::class, 'it', Join::WITH, 'ic.image = it.imageId')
            ->groupBy('it.tagId');

        SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.category'),
            $criteria->visibleCategoriesCondition('ic.category'),
            $criteria->visibleImagesCondition('ic.image'),
            $criteria->imageAccessCondition('ic.image'),
        )->applyTo($qb);

        if ($tagIds !== []) {
            $qb->andWhere('it.tagId IN (:tagIds)')
                ->setParameter('tagIds', array_map(intval(...), $tagIds), ArrayParameterType::INTEGER);
        }

        $counters = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $tagId = $row['tagId'] ?? null;
            $tagIdInt = $tagId instanceof TagId ? $tagId->value : (is_numeric($tagId) ? (int) $tagId : 0);
            $counters[$tagIdInt] = is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0;
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
     * The original's trailing `ORDER BY NULL` (a MySQL query-optimizer
     * hint meaning "don't sort") when $maxTags <= 0 has no `QueryBuilder`
     * equivalent and none is needed -- omitting `ORDER BY` entirely is the
     * same "unspecified order" behavior.
     *
     * DQL's `SelectExpression` grammar allows a bare `IdentificationVariable`
     * (a full entity, `t`) mixed with a scalar aggregate (`COUNT(...) AS
     * counter`) in one SELECT list, hydrating each row as `[0 =>
     * TagEntity, 'counter' => int]` under default `getResult()` hydration.
     *
     * Stays a raw array shape, deliberately. `TagService::getCommonTags()`
     * (the one real caller) mutates
     * `$row['name']` in place after a `RenderTagName` event dispatch, then
     * `array_merge()`s the result with `TagService::getAvailableTags()`'s
     * own separately-cached, differently-sourced rows of this exact same
     * shape (`SearchFilterRenderer::render()`'s own "force missing tags
     * into the list" fallback) -- two independently-built, mutated,
     * merged row streams feeding one combined list across 6 real call
     * sites (WS responses and template assignments both). A safe
     * conversion to a typed DTO needs `getAvailableTags()`'s own
     * row-building/caching converted too -- a Tag-domain-wide, not
     * single-method, change.
     *
     * @param list<int> $items
     * @param list<int> $excludedTagIds
     * @return list<array{id: int, name: string, url_name: string, lastmodified: string, counter: int}>
     */
    public function findCommonTags(array $items, int $maxTags, array $excludedTagIds): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('t', 'COUNT(it.imageId) AS counter')
            ->from(TagEntity::class, 't')
            ->innerJoin(ImageTagEntity::class, 'it', Join::WITH, 't.id = it.tagId')
            ->where('it.imageId IN (:items)')
            ->setParameter('items', $items, ArrayParameterType::INTEGER)
            ->groupBy('t.id');

        if ($excludedTagIds !== []) {
            $qb->andWhere('it.tagId NOT IN (:excludedTagIds)')
                ->setParameter('excludedTagIds', $excludedTagIds, ArrayParameterType::INTEGER);
        }

        if ($maxTags > 0) {
            $qb->orderBy('counter', 'DESC')
                ->setMaxResults($maxTags);
        }

        $rows = $qb->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $tag = $row[0];
            $result[] = [
                'id' => $tag->id instanceof TagId ? $tag->id->value : 0,
                'name' => $tag->name,
                'url_name' => $tag->urlName,
                'lastmodified' => $tag->lastmodified->value,
                'counter' => $row['counter'],
            ];
        }

        return $result;
    }

    /**
     * Stays on DBAL rather than DQL: conditionally joins the
     * never-entity-mapped `image_category`, and $orderBySqlBody is a raw,
     * caller-supplied SQL ORDER BY body (no `ORDER BY` keyword -- this
     * builder's own `orderBy()` prepends that itself), not a typed sort
     * object -- the one real caller ({@see \Piwigo\Tag\TagService::
     * getImageIdsForTags()}) falls back to `CurrentConfig::orderBy()`
     * (free-form admin-configurable text) whenever the caller-supplied
     * `$orderBy` is null/empty, the same "caller composes trusted ORDER
     * BY text" architecture as {@see \Piwigo\Common\ValueObject\PhotoSortField},
     * {@see \Piwigo\Group\GroupRepository::findWithMemberCounts()}, and
     * {@see \Piwigo\Image\ImageRepository::findBatchManagerThumbnails()}.
     * `ImageFilterCriteria::toSqlCondition()` also hardcodes raw
     * snake_case column names internally (not parameterized per DQL
     * property path the way {@see PermissionCriteria} is).
     *
     * When $usePermissions is true, $criteria is applied against
     * `ic.category_id` (forbidden/visible categories), `i.id` (visible
     * images), and `i.level` (max level).
     *
     * @param list<int> $tagIds already-unwrapped TagId values
     * @return list<int>
     */
    public function findImageIdsForTags(
        array $tagIds,
        string $mode,
        bool $usePermissions,
        PermissionCriteria $criteria,
        ?ImageFilterCriteria $filterCriteria = null,
        string $orderBySqlBody = ''
    ): array {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from('images', 'i');

        if ($usePermissions) {
            $qb->innerJoin('i', 'image_category', 'ic', 'id=ic.image_id');
        }

        $qb->innerJoin('i', 'image_tag', 'it', 'id=it.image_id')
            ->where($qb->expr()->in('tag_id', ':tagIds'))
            ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER)
            ->groupBy('id');

        if ($usePermissions) {
            // visible_images's own old fallthrough into forbidden_images
            // (fieldName 'id' -> the images-table's own level check) -- see
            // PermissionCriteria's own docblock.
            SqlCondition::combine(
                'AND',
                $criteria->forbiddenCategoriesCondition('ic.category_id'),
                $criteria->visibleCategoriesCondition('ic.category_id'),
                $criteria->visibleImagesCondition('i.id'),
                $criteria->maxLevelCondition('i.level'),
            )->applyTo($qb);
        }

        if ($filterCriteria instanceof ImageFilterCriteria && ! $filterCriteria->isEmpty()) {
            // toSqlCondition()'s own fragment is already self-contained
            // (its own clauses are wrapped in one outer set of parens), so
            // andWhere()'s plain string concatenation scopes it correctly
            // against the conditions already applied above with no
            // further wrapping needed.
            $filterCondition = $filterCriteria->toSqlCondition('i.');
            $qb->andWhere($filterCondition->sql);
            foreach ($filterCondition->parameters as $name => $value) {
                $qb->setParameter($name, $value, $filterCondition->types[$name] ?? ParameterType::STRING);
            }
        }

        if ($mode === 'AND' && count($tagIds) > 1) {
            $qb->having('COUNT(DISTINCT tag_id) = :tagCount')
                ->setParameter('tagCount', count($tagIds));
        }

        if ($orderBySqlBody !== '') {
            $qb->orderBy($orderBySqlBody);
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
     * `SUBDATE(NOW(), INTERVAL 1 DAY)`'s DQL equivalent is `DATE_SUB()`,
     * see {@see \Piwigo\Comment\CommentRepository::countRecentComments()}'s
     * own note. The anti-join (`LEFT JOIN image_tag ... WHERE tag_id IS
     * NULL`) has no formal ORM association between `ImageTagEntity`/
     * `TagEntity`, but DQL supports arbitrary joins without one via
     * `Join::WITH`.
     *
     * The cutoff uses DQL's own `CURRENT_TIMESTAMP()` (compiles to the DB
     * server's real `NOW()`) rather than a PHP-computed `Env::now()`
     * parameter -- deliberately, not an oversight: this method's caller
     * ({@see \Piwigo\Tests\Browser\TagsPageRendererTest}) backdates a tag
     * via raw SQL against the real MySQL clock
     * (`DATE_SUB(NOW(), INTERVAL 2 DAY)`), not a fixture date pinned to
     * `PIWIGO_TEST_NOW`. Using `Env::now()` here would compare the
     * real-clock-backdated row against a frozen-clock cutoff, drifting
     * apart as real time passes without `PIWIGO_TEST_NOW` being updated.
     * Contrast with {@see \Piwigo\Comment\CommentRepository::
     * countRecentComments()}, which deliberately uses `Env::now()` because
     * it compares against static, hardcoded fixture dates that need a
     * pinned anchor for determinism -- the opposite situation.
     *
     * @return list<TagBrief>
     */
    public function findOrphanTags(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id', 't.name')
            ->leftJoin(ImageTagEntity::class, 'it', Join::WITH, 't.id = it.tagId')
            ->where('it.tagId IS NULL')
            ->andWhere("t.lastmodified < DATE_SUB(CURRENT_TIMESTAMP(), 1, 'day')")
            ->getQuery()
            ->getArrayResult();

        $briefs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $briefs[] = TagBrief::fromRow([
                'id' => $id instanceof TagId ? $id->value : $id,
                'name' => $row['name'] ?? null,
            ]);
        }

        return $briefs;
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<ImageTagLink>
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
            ->setParameter('imageIds', array_map(intval(...), $imageIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (ImageTagEntity $it): ImageTagLink => new ImageTagLink($it->imageId->value, $it->tagId),
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

        return array_map(static fn (ImageTagEntity $it): int => $it->imageId->value, $entities);
    }

    /**
     * Every image id with at least one tag -- SearchService's own
     * quick-search wildcard token (a bare `tag:*`), the positive
     * counterpart of {@see \Piwigo\Image\ImageService::getIdsWithNoTag()}.
     *
     * @return list<int>
     */
    public function findAllTaggedImageIds(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('DISTINCT it.imageId')
                ->from(ImageTagEntity::class, 'it')
                ->getQuery()
                ->getSingleColumnResult()
        ));
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
            ->setParameter('imageIds', array_map(intval(...), $imageIds), ArrayParameterType::INTEGER)
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
            ->setParameter('imageIds', array_values(array_map(intval(...), $imageIds)), ArrayParameterType::INTEGER)
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
     * pattern, e.g. '%word%') -- backs SearchService::searchAllwords()'s
     * own "all words" search feature (distinct from quick-search's
     * separate token-based tag lookup).
     *
     * Single-table, static WHERE, no join DQL can't express. t.id
     * hydrates as a real TagId VO under DQL array hydration (its own
     * 'tag_id' custom Doctrine Type applies there too, same as
     * CommentRepository::findSummariesForImage()'s own documented finding
     * for CommentId) -- extracted back to a plain int below since that's
     * this method's own return contract.
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
     * `get_tag_name_like_where`'s own contract is "return LIKE pattern
     * VALUES", not raw SQL fragments -- every pattern is a bound
     * parameter, so no plugin handler can inject SQL structure regardless
     * of what string it returns.
     *
     * Single-table, dynamic OR-joined LIKE conditions (ORM's own
     * Expr::orX()/like(), same shape as findByIdsUrlNamesOrNames()'s
     * existing DQL orX() usage above), no join DQL can't express. Fetches
     * the full entity (not a t.id partial select) and reads ->id off it --
     * avoids the array-hydration/custom-Type question entirely by staying
     * on ordinary object hydration. setMaxResults(1) matches "just the
     * first matching row" semantics -- getOneOrNullResult() alone throws
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
        $entity = new TagEntity($name, $urlName, SqlDateTime::from(Env::now()->format('Y-m-d H:i:s')));

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        assert($entity->id instanceof TagId);

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
            ->insert('tags')
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
     * Renames a tag -- Ws\Tags::rename()'s own single-tag name/url_name
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
     * Backs Admin\PictureModifyPageRenderer/Admin\BatchManagerUnitPageRenderer's
     * shared image_tag-JOIN-tags-by-image_id shape.
     *
     * No formal ORM association exists between `ImageTagEntity`/
     * `TagEntity`, but DQL doesn't need one for an explicit `JOIN Entity
     * WITH condition` (`Join::WITH`, already used elsewhere in this
     * codebase for the identical "no association" shape). `t.id`
     * hydrates as a `TagId` VO under DQL array hydration (same gotcha
     * `findIdsByNameLike()` above already documents), unwrapped back to a
     * raw int before `toIdNameRow()` -- that helper's own `is_numeric()`
     * check would otherwise silently default a `TagId` object to 0 rather
     * than using its real value.
     *
     * @return list<TagIdName>
     */
    public function findTagsForImage(ImageId $imageId): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id', 't.name')
            ->innerJoin(ImageTagEntity::class, 'it', Join::WITH, 't.id = it.tagId')
            ->where('it.imageId = :imageId')
            ->setParameter('imageId', $imageId)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $result[] = self::toIdNameRow([
                'id' => $id instanceof TagId ? $id->value : $id,
                'name' => $row['name'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * Backs Admin\BatchManager\FilterPanelRenderer's own
     * `tags WHERE id IN (...)` shape.
     *
     * Single-table, static WHERE, no join DQL can't express. Deliberately
     * NOT reusing toIdNameRow() here: t.id hydrates as a TagId VO under
     * DQL (see findIdsByNameLike()'s own docblock), and toIdNameRow()'s
     * is_numeric() check would silently default that to 0 (a TagId object
     * is never is_numeric()) instead of throwing, so this reads t.id
     * explicitly instead.
     *
     * @param  list<int>  $ids
     * @return list<TagIdName>
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

            $tags[] = new TagIdName(
                id: $id->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
            );
        }

        return $tags;
    }

    /**
     * Raw Doctrine hydration input, before this method's own real job
     * (narrowing it into a typed TagIdName) runs -- the boundary itself,
     * not a gap. Same category as CategoryRepository::
     * narrowIdNameUppercatsRankRows()/SearchRepository::castRows().
     *
     * @param  array<string, mixed>  $row
     */
    private static function toIdNameRow(array $row): TagIdName
    {
        return new TagIdName(
            id: is_numeric($row['id']) ? (int) $row['id'] : 0,
            name: is_string($row['name']) ? $row['name'] : '',
        );
    }

    /**
     * $ignore matches Category\CategoryRepository::massInsertGroupAccess()'s
     * own `ignore` convention -- Ws\Tags::merge() needs INSERT IGNORE so
     * an image already tagged with the destination tag doesn't collide with
     * one it's picking up from a merged-away tag.
     *
     * Bulk multi-row INSERT via BatchWriter, deliberately not per-entity
     * persist() (an ORM insert writes one row per flush(), not a single
     * bulk statement).
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
            ->massInsert('image_tag', array_keys($inserts[0]), $inserts, [
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
     * Single-table (`image_tag`, mapped via ImageTagEntity), no WHERE/join
     * DQL can't express. it.tagId hydrates as a TagId VO (ImageTagEntity's
     * own TagIdType-mapped field, see that class's own docblock) -- read
     * via instanceof, not is_numeric() (which a TagId object always
     * fails, the same shape findTagsByIds()'s own docblock documents).
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
     * -- Ws\Tags::getImages()'s own "OR mode" per-image tag list.
     *
     * The rows are joined here rather than by `GROUP_CONCAT(it.tagId)`,
     * which this replaces. That aggregate truncates at
     * `group_concat_max_len` (1024 bytes by default), so an image carrying
     * roughly 170 or more tags silently lost the tail of its list -- and
     * because truncation cuts at a *byte* boundary, the final surviving
     * entry could be a fragment of a real id rather than a real id.
     * Joining in PHP has no such ceiling.
     *
     * It also drops this method's dependency on
     * {@see \Piwigo\Db\DqlFunction\GroupConcatFunction}, whose own docblock
     * records PostgreSQL/SQLite dispatch as unverified against a real
     * install.
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
            ->select('it.imageId AS image_id', 'it.tagId AS tag_id')
            ->from(ImageTagEntity::class, 'it')
            ->where('it.tagId IN (:tagIds)')
            ->andWhere('it.imageId IN (:imageIds)')
            ->setParameter('tagIds', $tagIds, ArrayParameterType::INTEGER)
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->orderBy('it.imageId', 'ASC')
            ->addOrderBy('it.tagId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $tagIdsByImageId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $imageId = $row['image_id'] ?? null;
            $imageIdInt = $imageId instanceof ImageId ? $imageId->value : (is_numeric($imageId) ? (int) $imageId : null);
            if ($imageIdInt === null) {
                continue;
            }

            $tagId = $row['tag_id'] ?? null;
            $tagIdInt = $tagId instanceof TagId ? $tagId->value : (is_numeric($tagId) ? (int) $tagId : null);
            if ($tagIdInt === null) {
                continue;
            }

            $tagIdsByImageId[$imageIdInt][] = $tagIdInt;
        }

        $byImageId = [];
        foreach ($tagIdsByImageId as $imageIdInt => $ids) {
            $byImageId[$imageIdInt] = implode(',', $ids);
        }

        return $byImageId;
    }

    public function findById(TagId $id): ?Tag
    {
        $entity = $this->find($id);

        return $entity === null ? null : self::toProjection($entity);
    }

    /**
     * Single-table, static WHERE, no join DQL can't express.
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
     * Single-table, static WHERE, no join DQL can't express.
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
     * Single-table, static WHERE, no join DQL can't express.
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
     * Every tag name except $excludeId's own -- Ws\Tags::rename()'s
     * own "is the new name already taken by a different tag" check.
     *
     * Single-table, static WHERE, no join DQL can't express. t.name is a
     * plain string column, no custom Doctrine Type involved (unlike t.id
     * elsewhere in this class).
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
        assert($entity->id instanceof TagId);

        return new Tag($entity->id, $entity->name, $entity->urlName, $entity->lastmodified->value);
    }

    /**
     * Total row count of `tags` -- Admin\InstallationStats's own
     * "nb_tags" summary figure.
     *
     * Single-table, no WHERE/join DQL can't express.
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
     * Single-table (mapped via ImageTagEntity), no WHERE/join DQL can't
     * express.
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
