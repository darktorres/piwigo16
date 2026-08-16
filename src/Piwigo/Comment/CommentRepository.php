<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Override;
use Piwigo\Comment\Projection\Comment;
use Piwigo\Comment\Projection\CommentDateRange;
use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Comment\Projection\CommentSummaryCounts;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\CommentCounterInterface;
use Piwigo\Core\Env;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Permission\SqlCondition;
use Piwigo\Users\UserEntity;
use UnexpectedValueException;

/**
 * Persistence layer for the comment domain: `comments` itself, plus a
 * thin cross-domain touch kept inline rather than promoted into a new
 * namespace dependency (same "thin cross-domain touch stays inline"
 * precedent as GroupRepository::findMemberUsernames() reading `users`
 * directly) -- usernameExists() (the guest-impersonation guard in
 * insert_user_comment()).
 *
 * Implements `CommentCounterInterface` (see that interface's own docblock)
 * so `Category\CategoryDefaultRenderer` (L2aCoreDomain) can depend on it
 * without a `deptrac analyse` `DependsOnDisallowedLayer` violation.
 *
 * @extends EntityRepository<CommentEntity>
 */
final class CommentRepository extends EntityRepository implements CommentCounterInterface
{
    /**
     * @param array{
     *   author: string,
     *   authorId: ?int,
     *   anonymousId: string,
     *   content: string,
     *   validated: bool,
     *   imageId: int,
     *   websiteUrl: ?string,
     *   email: ?string,
     * } $data
     */
    public function insert(array $data): CommentId
    {
        // Env::now() rather than SQL's NOW() -- see countRecentComments()'s
        // own docblock; date/validation_date must share the same time
        // reference that the flood-window comparison reads, not the real
        // DB-server clock.
        $now = SqlDateTime::from(Env::now()
            ->format('Y-m-d H:i:s'));

        $entity = new CommentEntity(
            imageId: ImageId::from($data['imageId']),
            date: $now,
            author: $data['author'],
            email: $data['email'],
            authorId: UserId::tryFrom($data['authorId']),
            anonymousId: $data['anonymousId'],
            websiteUrl: $data['websiteUrl'],
            content: $data['content'],
            validated: $data['validated'],
            validationDate: $data['validated'] ? $now : null,
        );

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        assert($entity->id instanceof CommentId);

        return $entity->id;
    }

    /**
     * Deletes the given comments. When $authorId is non-null, only rows
     * owned by that author are deleted (matches the original
     * delete_user_comment()'s non-admin restriction). Returns the number of
     * rows actually deleted.
     *
     * @param list<CommentId> $ids
     */
    public function delete(array $ids, ?int $authorId): int
    {
        if ($ids === []) {
            return 0;
        }

        $rawIds = array_map(static fn (CommentId $id): int => $id->value, $ids);

        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->delete(CommentEntity::class, 'c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $rawIds, ArrayParameterType::INTEGER);

        if ($authorId !== null) {
            $qb->andWhere('c.authorId = :authorId')
                ->setParameter('authorId', UserId::from($authorId));
        }

        $deleted = $qb->getQuery()
            ->execute();
        $this->getEntityManager()
            ->clear();

        return $deleted;
    }

    /**
     * Updates a single comment's editable fields. When $authorId is
     * non-null, only a row owned by that author is updated (matches the
     * original update_user_comment()'s non-admin restriction). Returns
     * whether a row was actually updated.
     *
     * @param array{content: string, websiteUrl: ?string, validated: bool} $data
     */
    public function update(CommentId $id, array $data, ?int $authorId): bool
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->update(CommentEntity::class, 'c')
            ->set('c.content', ':content')
            ->set('c.websiteUrl', ':websiteUrl')
            ->set('c.validated', ':validated')
            ->set('c.validationDate', ':validationDate')
            ->where('c.id = :id')
            ->setParameter('content', $data['content'])
            ->setParameter('websiteUrl', $data['websiteUrl'])
            ->setParameter('validated', $data['validated'])
            ->setParameter('validationDate', $data['validated'] ? Env::now()->format('Y-m-d H:i:s') : null)
            ->setParameter('id', $id);

        if ($authorId !== null) {
            $qb->andWhere('c.authorId = :authorId')
                ->setParameter('authorId', UserId::from($authorId));
        }

        $updated = $qb->getQuery()
            ->execute() > 0;
        $this->getEntityManager()
            ->clear();

        return $updated;
    }

    /**
     * Raw author_id of a comment: `false` when no such comment exists,
     * `null` when the comment exists but has no owner (anonymous/guest
     * comment -- author_id allows NULL), otherwise the numeric-string id.
     */
    public function findAuthorId(CommentId $id): string|null|false
    {
        $entity = $this->find($id);
        if ($entity === null) {
            return false;
        }

        return $entity->authorId === null ? null : (string) $entity->authorId;
    }

    /**
     * @param list<CommentId> $ids
     */
    public function validate(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $rawIds = array_map(static fn (CommentId $id): int => $id->value, $ids);

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(CommentEntity::class, 'c')
            ->set('c.validated', ':validated')
            ->set('c.validationDate', ':now')
            ->where('c.id IN (:ids)')
            ->setParameter('validated', true)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('ids', $rawIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Number of comments posted by $authorId (and, for non-classic users,
     * also matching the $anonymousIdPrefix.* anonymous_id pattern) within
     * the last $antiFloodSeconds seconds. Used by the anti-flood check.
     *
     * `DATE_SUB(date, interval, unit)` is a real, built-in DQL function
     * (compiling through `AbstractPlatform::
     * getDateSubSecondsExpression()`, genuinely portable per-platform,
     * not MySQL-specific), just spelled with a different argument order
     * than MySQL's own `SUBDATE(date, INTERVAL n unit)` syntax.
     */
    public function countRecentComments(int $authorId, ?string $anonymousIdPrefix, int $antiFloodSeconds): int
    {
        // Env::now() rather than SQL's NOW() (the real DB-server clock) --
        // matches SessionRepository's own reasoning: invisible to
        // PIWIGO_TEST_NOW, so fixture comments dated relative to it would
        // read as "within the flood window" whenever real time drifted
        // away from the fixture's own dates.
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where("c.date > DATE_SUB(:now, :seconds, 'second')")
            ->andWhere('c.authorId = :authorId')
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('seconds', $antiFloodSeconds)
            ->setParameter('authorId', UserId::from($authorId));

        if ($anonymousIdPrefix !== null) {
            $qb->andWhere('c.anonymousId LIKE :anonymousIdPrefix')
                ->setParameter('anonymousIdPrefix', $anonymousIdPrefix . '.%');
        }

        $value = $qb->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Whether a user with this exact username exists. Matches the
     * original's own plain `=` comparison -- case sensitivity depends on
     * the column's collation (this schema's `users` table defines `username`
     * with an explicit `utf8mb4_bin` collation, overriding the table's own
     * `utf8mb4_unicode_ci` default, so this comparison is case-sensitive), not
     * on anything this query controls.
     */
    public function usernameExists(string $username): bool
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(UserEntity::class, 'u')
            ->where('u.username = :username')
            ->setParameter('username', $username)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Applies a set of already-built SqlCondition fragments to $qb as ANDed
     * WHERE clauses -- shared by every $whereClauses-accepting method below.
     * Each fragment binds itself via {@see SqlCondition::applyTo()}, which
     * skips empties rather than adding a vacuous `AND ()`.
     *
     * @param array<array-key, SqlCondition> $conditions
     */
    private static function applyConditions(QueryBuilder|\Doctrine\ORM\QueryBuilder $qb, array $conditions): void
    {
        foreach ($conditions as $condition) {
            $condition->applyTo($qb);
        }
    }

    /**
     * Shared base condition list for the 4 CommentApiCriteria-accepting
     * methods below -- mirrors Ws\Comments::getList()'s own real
     * behavior: a non-empty $criteria->search resets every other filter
     * (its own "reset all filters during search" comment), otherwise each
     * of authorId/imageId/minDate/maxDate applies independently when set.
     * $includeAuthorId is false only for findAuthorCounts()'s own call --
     * see that method's own docblock for why.
     *
     * @return list<SqlCondition>
     */
    private static function buildApiConditions(CommentApiCriteria $criteria, bool $includeAuthorId): array
    {
        if ($criteria->search !== null && $criteria->search !== '') {
            return [
                new SqlCondition('content LIKE :search', [
                    'search' => '%' . $criteria->search . '%',
                ], [
                    'search' => ParameterType::STRING,
                ]),
            ];
        }

        $conditions = [];

        if ($includeAuthorId && $criteria->authorId instanceof UserId) {
            $conditions[] = new SqlCondition('author_id = :authorId', [
                'authorId' => $criteria->authorId->value,
            ], [
                'authorId' => ParameterType::INTEGER,
            ]);
        }

        if ($criteria->imageId instanceof ImageId) {
            $conditions[] = new SqlCondition('image_id = :imageId', [
                'imageId' => $criteria->imageId->value,
            ], [
                'imageId' => ParameterType::INTEGER,
            ]);
        }

        if ($criteria->minDate instanceof SqlDateTime) {
            $conditions[] = new SqlCondition('date >= :minDate', [
                'minDate' => $criteria->minDate->value,
            ], [
                'minDate' => ParameterType::STRING,
            ]);
        }

        if ($criteria->maxDate instanceof SqlDateTime) {
            $conditions[] = new SqlCondition('date <= :maxDate', [
                'maxDate' => $criteria->maxDate->value,
            ], [
                'maxDate' => ParameterType::STRING,
            ]);
        }

        return $conditions;
    }

    /**
     * {@see buildApiConditions()} plus $criteria->status's own condition,
     * appended on top -- the 3 sibling methods (unlike findSummaryCounts(),
     * which computes all/validated/pending itself and needs the
     * status-unfiltered set) all filter by status the same way.
     *
     * @return list<SqlCondition>
     */
    private static function buildApiConditionsWithStatus(CommentApiCriteria $criteria, bool $includeAuthorId): array
    {
        $conditions = self::buildApiConditions($criteria, $includeAuthorId);

        // A bare 0/1 integer literal spliced into raw SQL text is rejected
        // outright against a genuine PostgreSQL boolean column ("operator
        // does not exist: boolean = integer"). The identical value bound
        // as a real parameter is correctly coerced by the driver on both
        // platforms, so binding rather than splicing fixes this portably
        // with no per-platform branch needed.
        $statusCondition = match ($criteria->status) {
            'pending' => new SqlCondition('validated = :validated', [
                'validated' => 0,
            ], [
                'validated' => ParameterType::INTEGER,
            ]),
            'validated' => new SqlCondition('validated = :validated', [
                'validated' => 1,
            ], [
                'validated' => ParameterType::INTEGER,
            ]),
            default => null,
        };

        if ($statusCondition instanceof SqlCondition) {
            $conditions[] = $statusCondition;
        }

        return $conditions;
    }

    /**
     * DQL counterpart of {@see buildApiConditions()} -- shared by the 3
     * DQL-based CommentApiCriteria-consuming methods below
     * ({@see findAuthorCounts()}/{@see findSummaryCounts()}/
     * {@see findDateRange()}). {@see findListForAdminWs()} deliberately
     * keeps using the original SqlCondition/DBAL version above -- it has
     * its own separate, permanent blocker (a dynamic multi-auth
     * column-name join, same as {@see findForImage()}'s own), so tying it
     * to a DQL-only builder would just force it back onto a second,
     * redundant raw-SQL condition layer for no benefit. Splitting this
     * class's condition-building machinery in two is the honest design
     * here: 3 methods genuinely have no other blocker left, one never
     * will regardless of what this helper does.
     *
     * Built via Doctrine's `Criteria`/`Criteria::expr()` and applied
     * through `QueryBuilder::addCriteria()` -- genuinely eligible here
     * (unlike every other Criteria-value-object-consuming method in this
     * codebase, all blocked by a JOIN/dynamic-column/`SELECT DISTINCT`
     * concern `Criteria` can't express): single mapped entity
     * (`CommentEntity`, alias `c`), no JOIN, and `addCriteria()` only
     * touches WHERE/ORDER/LIMIT, so the callers' own aggregate
     * `->select()`/`->groupBy()` calls are untouched. `contains()`
     * translates to `LIKE '%value%'`, per
     * `QueryExpressionVisitor::walkComparison()`'s own
     * `Comparison::CONTAINS` case.
     */
    private static function applyApiConditions(\Doctrine\ORM\QueryBuilder $qb, CommentApiCriteria $criteria, bool $includeAuthorId): void
    {
        $expr = Criteria::expr();

        if ($criteria->search !== null && $criteria->search !== '') {
            $qb->addCriteria(Criteria::create()->andWhere($expr->contains('content', $criteria->search)));

            return;
        }

        $criteriaObj = Criteria::create();

        if ($includeAuthorId && $criteria->authorId instanceof UserId) {
            $criteriaObj->andWhere($expr->eq('authorId', $criteria->authorId));
        }

        if ($criteria->imageId instanceof ImageId) {
            $criteriaObj->andWhere($expr->eq('imageId', $criteria->imageId));
        }

        if ($criteria->minDate instanceof SqlDateTime) {
            $criteriaObj->andWhere($expr->gte('date', $criteria->minDate));
        }

        if ($criteria->maxDate instanceof SqlDateTime) {
            $criteriaObj->andWhere($expr->lte('date', $criteria->maxDate));
        }

        $qb->addCriteria($criteriaObj);
    }

    /**
     * DQL counterpart of {@see buildApiConditionsWithStatus()} -- same
     * scope note as {@see applyApiConditions()} above.
     */
    private static function applyApiConditionsWithStatus(\Doctrine\ORM\QueryBuilder $qb, CommentApiCriteria $criteria, bool $includeAuthorId): void
    {
        self::applyApiConditions($qb, $criteria, $includeAuthorId);

        $validated = match ($criteria->status) {
            'pending' => false,
            'validated' => true,
            default => null,
        };

        if ($validated !== null) {
            $qb->addCriteria(Criteria::create()->andWhere(Criteria::expr()->eq('validated', $validated)));
        }
    }

    /**
     * Distinct comment count for the given permission/validation condition
     * fragments -- CommentService::getNbAvailableComments()'s own
     * PermissionCriteria::forbiddenCategoriesCondition()/imageAccessCondition()
     * output plus a plain literal condition, combined here via
     * applyConditions() and bound. $whereClauses elements are real
     * SqlCondition fragments, each with its own bound parameters.
     *
     * `image_category` is mapped ({@see \Piwigo\Image\ImageCategoryEntity}).
     * The caller ({@see \Piwigo\Comment\CommentService::getNbAvailableComments()})
     * passes DQL property paths (`ic.categoryId`/`ic.imageId`) instead of
     * raw column names.
     *
     * @param  list<SqlCondition>  $whereClauses
     */
    public function countAvailableWithConditions(array $whereClauses): int
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT com.id)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CommentEntity::class, 'com', Join::WITH, 'ic.imageId = com.imageId');

        self::applyConditions($qb, $whereClauses);

        $value = $qb->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Number of comments on a single image (the picture page's comment
     * count), optionally restricted to validated ones (non-admin
     * viewers). Single-table, static WHERE -- no join or aggregate here
     * that DQL can't express.
     */
    public function countForImage(ImageId $imageId, bool $onlyValidated): int
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(CommentEntity::class, 'c')
            ->where('c.imageId = :imageId')
            ->setParameter('imageId', $imageId);

        if ($onlyValidated) {
            $qb->andWhere('c.validated = :validated')
                ->setParameter('validated', true);
        }

        $value = $qb->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Paginated `id, date, author, content` summaries for a single image,
     * ordered by date ascending -- Ws\Images::getInfo()'s own "related
     * comments" block, a different (narrower, no user join) shape from
     * findForImage() above. Single-table, static WHERE/ORDER BY/LIMIT --
     * no join or aggregate here that DQL can't express.
     *
     * `c.id` is a tiebreaker, not decoration: `comments.date` is a datetime
     * and two comments on one image share a timestamp readily (a bulk
     * import, or two posts in the same second). Paging an order that isn't
     * total lets a row appear on two pages or none as $offset advances --
     * the same reason findAllWithConditions()/findListForAdminWs() below
     * already append their own primary-key tiebreaker.
     *
     * @return list<CommentSummary>
     */
    public function findSummariesForImage(ImageId $imageId, bool $onlyValidated, int $limit, int $offset): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id', 'c.date', 'c.author', 'c.content')
            ->from(CommentEntity::class, 'c')
            ->where('c.imageId = :imageId')
            ->setParameter('imageId', $imageId)
            ->orderBy('c.date', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($onlyValidated) {
            $qb->andWhere('c.validated = :validated')
                ->setParameter('validated', true);
        }

        $summaries = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            // c.id hydrates as a real CommentId VO here (its own
            // 'comment_id' custom Doctrine Type applies to DQL array
            // hydration too, unlike a raw DBAL row's plain int) --
            // CommentSummary::fromRow()'s own contract documents a raw
            // DBAL row shape (int|string id), so its own id is read
            // directly instead of going through fromRow()'s narrower
            // int|numeric-string parsing.
            $id = $row['id'] ?? null;
            if (! $id instanceof CommentId) {
                throw new UnexpectedValueException(sprintf('Expected c.id to hydrate as a CommentId, got %s', get_debug_type($id)));
            }

            $rowDate = $row['date'] ?? null;

            $summaries[] = new CommentSummary(
                id: $id,
                date: $rowDate instanceof SqlDateTime ? $rowDate->value : (is_string($rowDate) ? $rowDate : null),
                author: is_string($row['author'] ?? null) ? $row['author'] : null,
                content: is_string($row['content'] ?? null) ? $row['content'] : null,
            );
        }

        return $summaries;
    }

    /**
     * Real DQL -- single-table, static WHERE/GROUP BY, no join DQL can't
     * express.
     * `imageId` uses the `image_id` custom Doctrine Type, so the selected
     * `c.imageId` hydrates as a real {@see ImageId} under
     * `getArrayResult()`, unwrapped below.
     *
     * Validated comment count per image, for a batch of images at once
     * (`CategoryDefaultRenderer`'s main-page thumbnail grid, one query
     * instead of one `countForImage()` call per thumbnail).
     *
     * @param  list<int|string>  $imageIds
     * @return array<int, int> keyed by image id -- PHP canonicalises a
     *   numeric-string array key back to an int key, so this is always
     *   int-keyed at runtime regardless of $imageIds' own element types.
     */
    #[Override]
    public function countValidatedByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.imageId', 'COUNT(c.id) AS nbComments')
            ->from(CommentEntity::class, 'c')
            ->where('c.validated = :validated')
            ->andWhere('c.imageId IN (:imageIds)')
            ->setParameter('validated', true)
            ->setParameter('imageIds', array_map(strval(...), $imageIds), ArrayParameterType::STRING)
            ->groupBy('c.imageId')
            ->getQuery()
            ->getArrayResult();

        $byImageId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $imageId = $row['imageId'] ?? null;
            $nbComments = $row['nbComments'] ?? null;
            if ($imageId instanceof ImageId && is_numeric($nbComments)) {
                $byImageId[(string) $imageId->value] = (int) $nbComments;
            }
        }

        return $byImageId;
    }

    /**
     * Paginated comment listing for a single image, joined with the
     * commenting user's email column (looked up by DB primary key).
     *
     * `users` is mapped ({@see \Piwigo\Users\UserEntity}), so no
     * multi-auth column indirection (`$userIdColumn`/`$userEmailColumn`
     * parameters) is needed here.
     *
     * `com.id` is a tiebreaker, not decoration: `comments.date` is a
     * datetime and two comments on one image share a timestamp readily, so
     * paging a non-total order lets a row appear on two pages or none as
     * $offset advances. It follows $order's own direction so the secondary
     * sort stays consistent with the primary one.
     *
     * @param string $order 'ASC'|'asc'|'DESC'|'desc' only -- the caller must
     *   validate this before calling (matches the original's own
     *   in_array(strtoupper($x), ['ASC', 'DESC']) check), this method
     *   passes it straight to DQL's own orderBy() with no further
     *   validation.
     * @return list<Comment>
     */
    public function findForImage(
        ImageId $imageId,
        bool $onlyValidated,
        string $order,
        int $limit,
        int $offset
    ): array {
        $qb = $this->createQueryBuilder('com')
            ->select(
                'com.id AS id',
                'com.author AS author',
                'com.authorId AS author_id',
                'u.mailAddress AS user_email',
                'com.date AS date',
                'com.imageId AS image_id',
                'com.websiteUrl AS website_url',
                'com.email AS email',
                'com.content AS content',
                'com.validated AS validated',
            )
            ->leftJoin(UserEntity::class, 'u', Join::WITH, 'u.id = com.authorId')
            ->where('com.imageId = :imageId')
            ->orderBy('com.date', $order)
            ->addOrderBy('com.id', $order)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->setParameter('imageId', $imageId);

        if ($onlyValidated) {
            $qb->andWhere('com.validated = :validated')
                ->setParameter('validated', true);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                // getArrayResult() hydrates 'author_id' through
                // CommentEntity::$authorId's own UserId Type -- unwrap
                // before fromRow()'s is_numeric() narrowing ever sees it,
                // the same Gotcha #1 CategoryEntity's own docblock warns
                // about for scalar/array selects of a custom-typed column.
                if (($row['author_id'] ?? null) instanceof UserId) {
                    $row['author_id'] = $row['author_id']->value;
                }
                $result[] = Comment::fromRow($row);
            }
        }

        return $result;
    }

    /**
     * Cross-category "all comments" listing (comments.php's own front-end
     * page) -- $whereClauses are already-built SqlCondition fragments
     * (permission/status/search/author/keyword filters, same "caller
     * composes fragments" contract as {@see countAvailableWithConditions()}),
     * combined via {@see applyConditions()}. $sortByColumn/$sortOrder
     * concatenate directly into ORDER BY with no further validation --
     * caller must restrict these to a known-safe set first
     * (Controller\CommentsController's own real caller validates both
     * against small fixed allowlists before this point), same contract as
     * {@see findForImage()}'s own $order.
     *
     * Deliberately returns raw rows, not a {@see Comment} Projection: the
     * `category_id`/`comment_id`-aliased shape here differs from
     * {@see findForImage()}'s own column list, and that Projection's own
     * docblock documents it as scoped to exactly that one caller.
     *
     * ANY_VALUE(ic.category_id): category_id comes from the JOINed
     * image_category table, not functionally dependent on the GROUP BY
     * column (comment_id) -- this connection doesn't strip
     * ONLY_FULL_GROUP_BY the way the legacy mysqli connection did, so this
     * needs the explicit opt-out to keep selecting exactly one arbitrary
     * category per comment, matching the original's own grouping/row
     * count exactly.
     *
     * `users` is mapped ({@see \Piwigo\Users\UserEntity}), always
     * `id`/`mail_address`. This query stays on DBAL rather than DQL -- it
     * joins the never-entity-mapped `image_category`, uses `ANY_VALUE()`
     * (has no DQL equivalent), and accepts dynamic caller-supplied
     * SqlCondition fragments -- several independent, genuine DQL blockers
     * remain.
     *
     * `u.mail_address` needs `ANY_VALUE()` too, not just
     * `ic.category_id`. MySQL's ONLY_FULL_GROUP_BY functional-dependency
     * check reasons transitively through the `u.id = com.author_id` join
     * (grouping by `com.id` determines `com.author_id`, which -- joined
     * against `users`' own primary key -- determines `u.mail_address`),
     * so MySQL tolerates the bare column unwrapped. PostgreSQL's
     * functional-dependency check only recognizes a table's own primary
     * key appearing in GROUP BY, never transitively through a join --
     * rejects it outright ("column u.mail_address must appear in the
     * GROUP BY clause or be used in an aggregate function"). `ANY_VALUE()`
     * is a real aggregate on both platforms (PostgreSQL 16+ and MySQL),
     * applied consistently to every non-grouped, non-`com.*` column.
     *
     * `COUNT(*) OVER() AS total_count` is computed in the same query as
     * the row data instead of a second round-trip. `GROUP BY comment_id`
     * here (not `DISTINCT`), so the window function (evaluated after
     * GROUP BY, before LIMIT/OFFSET) reports the correct post-grouping
     * row count -- unlike its incorrect behavior on `SELECT
     * DISTINCT` queries, see
     * {@see \Piwigo\Users\UserRepository::findListForWs()}'s own
     * docblock.
     *
     * Raw DBAL (not DQL) -- every numeric column below comes back as
     * whichever native PHP type the active driver hands back (a real int
     * under mysqli's own MYSQLI_OPT_INT_AND_FLOAT_NATIVE, a numeric
     * string under some pgsql paths), never a VO. `author`/`author_id`/
     * `email`/`date`/`website_url`/`content` are CommentEntity's own
     * nullable columns; `validated` is a non-nullable `bool` column, but
     * SqlDialect::getBoolean() (the one real caller's own consumer) is
     * this codebase's established "genuinely arbitrary boolean-ish input"
     * boundary, so it stays a driver-dependent bool|int here rather than
     * a plain bool.
     *
     * @param list<SqlCondition> $whereClauses
     * @return PaginatedResult<array{comment_id: int|string, image_id: int|string,
     *   category_id: int|string, author: ?string, author_id: int|string|null,
     *   user_email: ?string, email: ?string, date: ?string, website_url: ?string,
     *   content: ?string, validated: bool|int}>
     */
    public function findAllWithConditions(
        array $whereClauses,
        string $sortByColumn,
        string $sortOrder,
        int|string $limit,
        int $offset
    ): PaginatedResult {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select(
                'com.id AS comment_id',
                'com.image_id',
                'ANY_VALUE(ic.category_id) AS category_id',
                'com.author',
                'com.author_id',
                'ANY_VALUE(u.mail_address) AS user_email',
                'com.email',
                'com.date',
                'com.website_url',
                'com.content',
                'com.validated',
                'COUNT(*) OVER() AS total_count',
            )
            ->from('image_category', 'ic')
            ->innerJoin('ic', 'comments', 'com', 'ic.image_id = com.image_id')
            ->leftJoin('com', 'users', 'u', 'u.id = com.author_id')
            ->groupBy('comment_id')
            ->orderBy($sortByColumn, $sortOrder)
            ->addOrderBy('comment_id', $sortOrder);

        self::applyConditions($qb, $whereClauses);

        if ($limit !== 'all') {
            $qb->setMaxResults((int) $limit)
                ->setFirstResult($offset);
        }

        $rawRows = $qb->executeQuery()
            ->fetchAllAssociative();

        $total = $rawRows !== [] && is_numeric($rawRows[0]['total_count'] ?? null) ? (int) $rawRows[0]['total_count'] : 0;

        $rows = [];
        foreach ($rawRows as $row) {
            $commentId = $row['comment_id'] ?? null;
            $imageId = $row['image_id'] ?? null;
            $categoryId = $row['category_id'] ?? null;
            $authorId = $row['author_id'] ?? null;
            $validated = $row['validated'] ?? null;
            if ((! is_int($commentId) && ! is_string($commentId))
                || (! is_int($imageId) && ! is_string($imageId))
                || (! is_int($categoryId) && ! is_string($categoryId))
                || (! is_bool($validated) && ! is_int($validated))) {
                continue;
            }

            $rows[] = [
                'comment_id' => $commentId,
                'image_id' => $imageId,
                'category_id' => $categoryId,
                'author' => is_string($row['author'] ?? null) ? $row['author'] : null,
                'author_id' => (is_int($authorId) || is_string($authorId)) ? $authorId : null,
                'user_email' => is_string($row['user_email'] ?? null) ? $row['user_email'] : null,
                'email' => is_string($row['email'] ?? null) ? $row['email'] : null,
                'date' => is_string($row['date'] ?? null) ? $row['date'] : null,
                'website_url' => is_string($row['website_url'] ?? null) ? $row['website_url'] : null,
                'content' => is_string($row['content'] ?? null) ? $row['content'] : null,
                'validated' => $validated,
            ];
        }

        return new PaginatedResult($rows, $total);
    }

    /**
     * Total/validated/pending counts matching $criteria -- Ws\Comments::
     * getList()'s own summary block. Deliberately ignores $criteria->status:
     * this computes all/validated/pending counts itself via SUM(), so it
     * needs the status-unfiltered condition set, unlike the 3 sibling
     * methods below.
     *
     * Uses the standard DQL `SUM(CASE WHEN ... THEN 1 ELSE 0 END)` idiom
     * in place of MySQL's `sum(validated = 1)`/`sum(validated = 0)`
     * boolean-expression-as-integer shorthand.
     */
    public function findSummaryCounts(CommentApiCriteria $criteria): ?CommentSummaryCounts
    {
        $qb = $this->createQueryBuilder('c')
            ->select(
                'COUNT(c.id) AS all_comments',
                'SUM(CASE WHEN c.validated = true THEN 1 ELSE 0 END) AS validated',
                'SUM(CASE WHEN c.validated = false THEN 1 ELSE 0 END) AS pending',
            );

        self::applyApiConditions($qb, $criteria, includeAuthorId: true);

        $row = $qb->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return new CommentSummaryCounts(
            allComments: is_numeric($row['all_comments'] ?? null) ? (int) $row['all_comments'] : 0,
            validated: is_numeric($row['validated'] ?? null) ? (int) $row['validated'] : 0,
            pending: is_numeric($row['pending'] ?? null) ? (int) $row['pending'] : 0,
        );
    }

    /**
     * Paginated admin comment listing (joined with the commenting image
     * and user) matching $criteria -- Ws\Comments::getList()'s own row
     * listing.
     *
     * Stays on DBAL -- keeps using the SqlCondition/DBAL-based
     * buildApiConditionsWithStatus() rather than
     * {@see applyApiConditionsWithStatus()}'s DQL version; see that
     * method's own docblock for why.
     *
     * Raw DBAL, same driver-dependent int|string caveat as
     * findAllWithConditions() above for id-like columns. `username`/
     * `status` come from LEFT JOINs (null when no matching row); `path`/
     * `file` are ImageEntity's own non-nullable columns (INNER JOIN, so
     * always present); `date`/`date_available`/`representative_ext`/
     * `author`/`content` are nullable on their respective entities.
     *
     * @return list<array{id: int|string, image_id: int|string, date: ?string,
     *   author: ?string, author_id: int|string|null, username: ?string,
     *   status: ?string, content: ?string, path: string, representative_ext: ?string,
     *   file: string, date_available: ?string, validated: bool|int, anonymous_id: string}>
     */
    public function findListForAdminWs(
        CommentApiCriteria $criteria,
        int $offset,
        int $limit
    ): array {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select(
                'c.id',
                'c.image_id',
                'c.date',
                'c.author',
                'c.author_id',
                'u.username AS username',
                'ui.status',
                'c.content',
                'i.path',
                'i.representative_ext',
                'i.file',
                'i.date_available',
                'validated',
                'c.anonymous_id',
            )
            ->from('comments', 'c')
            ->innerJoin('c', 'images', 'i', 'i.id = c.image_id')
            ->leftJoin('c', 'users', 'u', 'u.id = c.author_id')
            ->leftJoin('c', 'user_infos', 'ui', 'ui.user_id = c.author_id')
            ->orderBy('c.date', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        self::applyConditions($qb, self::buildApiConditionsWithStatus($criteria, includeAuthorId: true));

        $rawRows = $qb->executeQuery()
            ->fetchAllAssociative();

        $rows = [];
        foreach ($rawRows as $row) {
            $id = $row['id'] ?? null;
            $imageId = $row['image_id'] ?? null;
            $authorId = $row['author_id'] ?? null;
            $path = $row['path'] ?? null;
            $file = $row['file'] ?? null;
            $validated = $row['validated'] ?? null;
            $anonymousId = $row['anonymous_id'] ?? null;
            if ((! is_int($id) && ! is_string($id))
                || (! is_int($imageId) && ! is_string($imageId))
                || (! is_bool($validated) && ! is_int($validated))
                || ! is_string($path) || ! is_string($file) || ! is_string($anonymousId)) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'image_id' => $imageId,
                'date' => is_string($row['date'] ?? null) ? $row['date'] : null,
                'author' => is_string($row['author'] ?? null) ? $row['author'] : null,
                'author_id' => (is_int($authorId) || is_string($authorId)) ? $authorId : null,
                'username' => is_string($row['username'] ?? null) ? $row['username'] : null,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                'content' => is_string($row['content'] ?? null) ? $row['content'] : null,
                'path' => $path,
                'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
                'file' => $file,
                'date_available' => is_string($row['date_available'] ?? null) ? $row['date_available'] : null,
                'validated' => $validated,
                'anonymous_id' => $anonymousId,
            ];
        }

        return $rows;
    }

    /**
     * Earliest/latest `date` matching $criteria -- Ws\Comments::
     * getList()'s own "filters" date range. MIN()/MAX() are standard DQL
     * functions, so this is straightforward once
     * {@see applyApiConditionsWithStatus()} builds the condition set.
     */
    public function findDateRange(CommentApiCriteria $criteria): ?CommentDateRange
    {
        $qb = $this->createQueryBuilder('c')
            ->select('MIN(c.date) AS started_at', 'MAX(c.date) AS ended_at');

        self::applyApiConditionsWithStatus($qb, $criteria, includeAuthorId: true);

        $row = $qb->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return new CommentDateRange(
            startedAt: is_string($row['started_at'] ?? null) ? $row['started_at'] : null,
            endedAt: is_string($row['ended_at'] ?? null) ? $row['ended_at'] : null,
        );
    }

    /**
     * Per-author comment counts matching $criteria -- Ws\Comments::
     * getList()'s own "filters.nb_authors" breakdown. Deliberately ignores
     * $criteria->authorId -- "how many comments per author" scoped to a
     * single author would be trivially 1, defeating the point of the
     * breakdown; mirrors the original's own
     * unset($where_clauses['author_id']) intent as real code instead of
     * an array-key convention.
     *
     * `author` isn't functionally dependent on the GROUP BY column
     * (`author_id`) -- this connection doesn't strip ONLY_FULL_GROUP_BY,
     * so picking exactly one row's worth of `author` per `author_id`
     * needs an explicit aggregate. `MIN(author)` (standard SQL-92,
     * portable across every DBAL platform) gives a deterministic pick;
     * no test asserts on which specific `author` value comes back, only
     * `author_id`/`nb_authors`.
     *
     * @return list<array{author: ?string, author_id: ?int, nb_authors: int}>
     */
    public function findAuthorCounts(CommentApiCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('MIN(c.author) AS author', 'c.authorId AS author_id', 'COUNT(c.id) AS nb_authors')
            ->groupBy('c.authorId');

        self::applyApiConditionsWithStatus($qb, $criteria, includeAuthorId: false);

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $author = $row['author'] ?? null;
            $authorId = $row['author_id'] ?? null;
            $nbAuthors = $row['nb_authors'] ?? null;

            $result[] = [
                'author' => is_string($author) ? $author : null,
                // getArrayResult() hydrates this through UserId (see
                // findForImage()'s own comment on the same gotcha).
                'author_id' => $authorId instanceof UserId ? $authorId->value : null,
                'nb_authors' => is_numeric($nbAuthors) ? (int) $nbAuthors : 0,
            ];
        }

        return $result;
    }

    /**
     * Total row count of `comments` -- Ws\Core::getInfos()'s own
     * "nb_comments" summary figure. Single-table, no WHERE or join here
     * that DQL can't express.
     */
    public function countAll(): int
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(CommentEntity::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Total count of unvalidated (pending) comments -- Ws\Core::
     * getInfos()'s own "nb_unvalidated_comments" summary figure.
     * Single-table, static WHERE -- no join here that DQL can't express.
     */
    public function countUnvalidated(): int
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(CommentEntity::class, 'c')
            ->where('c.validated = :validated')
            ->setParameter('validated', false)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }
}
