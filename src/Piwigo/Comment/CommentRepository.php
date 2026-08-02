<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Piwigo\Comment\Projection\Comment;
use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Core\CommentCounterInterface;
use Piwigo\Core\Env;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;

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
        $now = Env::now()
            ->format('Y-m-d H:i:s');

        $entity = new CommentEntity(
            imageId: $data['imageId'],
            date: $now,
            author: $data['author'],
            email: $data['email'],
            authorId: $data['authorId'],
            anonymousId: $data['anonymousId'],
            websiteUrl: $data['websiteUrl'],
            content: $data['content'],
            validated: $data['validated'],
            validationDate: $data['validated'] ? $now : null,
        );

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        assert($entity->id !== null);

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
                ->setParameter('authorId', $authorId);
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
                ->setParameter('authorId', $authorId);
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
     * Item 14 DQL audit, corrected: the original note claimed
     * `SUBDATE(..., INTERVAL ... SECOND)` had no native DQL function --
     * wrong. `DATE_SUB(date, interval, unit)` is a real, built-in DQL
     * function (compiling through `AbstractPlatform::
     * getDateSubSecondsExpression()`, genuinely portable per-platform,
     * not MySQL-specific), just spelled with a different argument order
     * than MySQL's own `SUBDATE(date, INTERVAL n unit)` syntax. Converted
     * to real DQL.
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
            ->setParameter('authorId', $authorId);

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
     * on anything this query controls. $usernameColumn is the configurable
     * DB column name (see \Piwigo\Config\CurrentConfig::userFields()), not user-controlled.
     *
     * Item 14 DQL audit: stays on DBAL -- queries `users`, not this
     * repository's own CommentEntity, and $usernameColumn is a runtime
     * column name (multi-auth integration support), not a fixed DQL
     * property path.
     */
    public function usernameExists(string $usernameColumn, string $username): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::users())
            ->where($usernameColumn . ' = :username')
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Applies a set of already-built SqlCondition fragments to $qb as
     * ANDed WHERE clauses, binding each fragment's own parameters/types --
     * shared by every $whereClauses-accepting method below. Empty
     * fragments (SqlCondition::isEmpty()) are skipped rather than adding a
     * vacuous `AND ()`.
     *
     * @param array<array-key, SqlCondition> $conditions
     */
    private static function applyConditions(QueryBuilder $qb, array $conditions): void
    {
        foreach ($conditions as $condition) {
            if ($condition->isEmpty()) {
                continue;
            }

            $qb->andWhere($condition->sql);
            foreach ($condition->parameters as $name => $value) {
                $qb->setParameter($name, $value, $condition->types[$name] ?? ParameterType::STRING);
            }
        }
    }

    /**
     * Shared base condition list for the 4 CommentApiCriteria-accepting
     * methods below -- mirrors Ws\PwgComments::getList()'s own real
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
                new SqlCondition('1=1'),
                new SqlCondition('content LIKE :search', [
                    'search' => '%' . $criteria->search . '%',
                ], [
                    'search' => ParameterType::STRING,
                ]),
            ];
        }

        $conditions = [new SqlCondition('1=1')];

        if ($includeAuthorId && $criteria->authorId !== null && $criteria->authorId !== 0) {
            $conditions[] = new SqlCondition('author_id = :authorId', [
                'authorId' => $criteria->authorId,
            ], [
                'authorId' => ParameterType::INTEGER,
            ]);
        }

        if ($criteria->imageId !== null && $criteria->imageId !== 0) {
            $conditions[] = new SqlCondition('image_id = :imageId', [
                'imageId' => $criteria->imageId,
            ], [
                'imageId' => ParameterType::INTEGER,
            ]);
        }

        if ($criteria->minDate !== null) {
            $conditions[] = new SqlCondition('date >= :minDate', [
                'minDate' => $criteria->minDate,
            ], [
                'minDate' => ParameterType::STRING,
            ]);
        }

        if ($criteria->maxDate !== null) {
            $conditions[] = new SqlCondition('date <= :maxDate', [
                'maxDate' => $criteria->maxDate,
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

        $statusCondition = match ($criteria->status) {
            'pending' => new SqlCondition('validated = 0'),
            'validated' => new SqlCondition('validated = 1'),
            default => null,
        };

        if ($statusCondition !== null) {
            $conditions[] = $statusCondition;
        }

        return $conditions;
    }

    /**
     * DQL counterpart of {@see buildApiConditions()} -- SQL-modernization
     * audit, Item 14 Sub-phase B3: shared by the 3 DQL-based
     * CommentApiCriteria-consuming methods below
     * ({@see findAuthorCounts()}/{@see findSummaryCounts()}/
     * {@see findDateRange()}). {@see findListForAdminWs()} deliberately
     * keeps using the original SqlCondition/DBAL version above -- it has
     * its own separate, permanent blocker (a dynamic multi-auth
     * column-name join, same as {@see findForImage()}'s own), so tying it
     * to a DQL-only builder would just force it back onto a second,
     * redundant raw-SQL condition layer for no benefit. Splitting this
     * class's condition-building machinery in two (once judged not worth
     * doing, per the older docblock this replaces) is the honest design
     * here: 3 methods genuinely have no other blocker left, one never
     * will regardless of what this helper does.
     */
    private static function applyApiConditions(\Doctrine\ORM\QueryBuilder $qb, CommentApiCriteria $criteria, bool $includeAuthorId): void
    {
        if ($criteria->search !== null && $criteria->search !== '') {
            $qb->andWhere('c.content LIKE :search')
                ->setParameter('search', '%' . $criteria->search . '%');

            return;
        }

        if ($includeAuthorId && $criteria->authorId !== null && $criteria->authorId !== 0) {
            $qb->andWhere('c.authorId = :authorId')
                ->setParameter('authorId', $criteria->authorId);
        }

        if ($criteria->imageId !== null && $criteria->imageId !== 0) {
            $qb->andWhere('c.imageId = :imageId')
                ->setParameter('imageId', $criteria->imageId);
        }

        if ($criteria->minDate !== null) {
            $qb->andWhere('c.date >= :minDate')
                ->setParameter('minDate', $criteria->minDate);
        }

        if ($criteria->maxDate !== null) {
            $qb->andWhere('c.date <= :maxDate')
                ->setParameter('maxDate', $criteria->maxDate);
        }
    }

    /**
     * DQL counterpart of {@see buildApiConditionsWithStatus()} -- same
     * scope note as {@see applyApiConditions()} above.
     */
    private static function applyApiConditionsWithStatus(\Doctrine\ORM\QueryBuilder $qb, CommentApiCriteria $criteria, bool $includeAuthorId): void
    {
        self::applyApiConditions($qb, $criteria, $includeAuthorId);

        match ($criteria->status) {
            'pending' => $qb->andWhere('c.validated = :validated')
                ->setParameter('validated', false),
            'validated' => $qb->andWhere('c.validated = :validated')
                ->setParameter('validated', true),
            default => null,
        };
    }

    /**
     * Distinct comment count for the given permission/validation condition
     * fragments -- CommentService::getNbAvailableComments()'s own
     * PermissionService::getSqlConditionFandFAsCondition() output plus a
     * plain literal condition, combined here via applyConditions() and
     * bound. SQL-modernization audit: $whereClauses elements used to be
     * raw trusted-SQL strings spliced verbatim; now real SqlCondition
     * fragments, each with its own bound parameters.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), but stays on DBAL for its
     * other, still-real blocker: this method's own real caller
     * ({@see \Piwigo\Comment\CommentService::getNbAvailableComments()})
     * feeds it a `PermissionService::getSqlConditionFandFAsCondition()`
     * result (see this docblock's own opening line) -- same genuinely
     * dynamic, cross-cutting permission-condition blocker documented in
     * {@see \Piwigo\Image\ImageRepository::applyCondition()}'s own
     * docblock, outside Sub-phase B3/B4's scope.
     *
     * @param  list<SqlCondition>  $whereClauses
     */
    public function countAvailableWithConditions(array $whereClauses): int
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT com.id)')
            ->from(Tables::imageCategory(), 'ic')
            ->join('ic', Tables::comments(), 'com', 'ic.image_id = com.image_id');

        self::applyConditions($qb, $whereClauses);

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Further SQL-modernization audit, Item 14: converted to real DQL --
     * single-table, static WHERE, no join/aggregate DQL can't express.
     *
     * Number of comments on a single image (the picture page's comment
     * count), optionally restricted to validated ones (non-admin viewers).
     */
    public function countForImage(int $imageId, bool $onlyValidated): int
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
     * Further SQL-modernization audit, Item 14: converted to real DQL --
     * single-table, static WHERE/ORDER BY/LIMIT, no join/aggregate DQL
     * can't express.
     *
     * Paginated `id, date, author, content` summaries for a single image,
     * ordered by date ascending -- Ws\PwgImages::getInfo()'s own "related
     * comments" block, a different (narrower, no user join) shape from
     * findForImage() above.
     *
     * @return list<CommentSummary>
     */
    public function findSummariesForImage(int $imageId, bool $onlyValidated, int $limit, int $offset): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id', 'c.date', 'c.author', 'c.content')
            ->from(CommentEntity::class, 'c')
            ->where('c.imageId = :imageId')
            ->setParameter('imageId', $imageId)
            ->orderBy('c.date', 'ASC')
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
                throw new \UnexpectedValueException(sprintf('Expected c.id to hydrate as a CommentId, got %s', get_debug_type($id)));
            }

            $summaries[] = new CommentSummary(
                id: $id,
                date: is_string($row['date'] ?? null) ? $row['date'] : null,
                author: is_string($row['author'] ?? null) ? $row['author'] : null,
                content: is_string($row['content'] ?? null) ? $row['content'] : null,
            );
        }

        return $summaries;
    }

    /**
     * Further SQL-modernization audit, Item 14: converted to real DQL --
     * single-table, static WHERE/GROUP BY, no join DQL can't express;
     * imageId/COUNT(c.id) are plain integers, no custom Doctrine Type
     * involved (unlike c.id elsewhere in this class).
     *
     * Validated comment count per image, for a batch of images at once
     * (`CategoryDefaultRenderer`'s main-page thumbnail grid, one query
     * instead of one `countForImage()` call per thumbnail).
     *
     * @param  list<int|string>  $imageIds
     * @return array<string, int> keyed by image id
     */
    #[\Override]
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
            if (is_scalar($imageId) && is_numeric($nbComments)) {
                $byImageId[(string) $imageId] = (int) $nbComments;
            }
        }

        return $byImageId;
    }

    /**
     * Paginated comment listing for a single image, joined with the
     * commenting user's email column (looked up by DB primary key --
     * \Piwigo\Config\CurrentConfig::userFields() maps the generic 'id'/'email' names to the
     * actual column names, resolved by the caller since that's
     * config-domain knowledge, not persistence-domain).
     *
     * Item 14 DQL audit: stays on DBAL -- the LEFT JOIN condition
     * (`u.{$userIdColumn} = com.author_id`) uses a runtime column name
     * (multi-auth integration support), not a fixed DQL property path.
     *
     * @param string $order 'ASC'|'asc'|'DESC'|'desc' only -- the caller must
     *   validate this before calling (matches the original's own
     *   in_array(strtoupper($x), ['ASC', 'DESC']) check), this method
     *   concatenates it directly into the query with no further validation.
     * @return list<Comment>
     */
    public function findForImage(
        int $imageId,
        bool $onlyValidated,
        string $userIdColumn,
        string $userEmailColumn,
        string $order,
        int $limit,
        int $offset
    ): array {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select(
                'com.id',
                'com.author',
                'com.author_id',
                'u.' . $userEmailColumn . ' AS user_email',
                'com.date',
                'com.image_id',
                'com.website_url',
                'com.email',
                'com.content',
                'com.validated'
            )
            ->from(Tables::comments(), 'com')
            ->leftJoin('com', Tables::users(), 'u', 'u.' . $userIdColumn . ' = com.author_id')
            ->where('com.image_id = :imageId')
            ->orderBy('com.date', $order)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->setParameter('imageId', $imageId);

        if ($onlyValidated) {
            $qb->andWhere('com.validated = 1');
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(Comment::fromRow(...), $rows);
    }

    /**
     * Cross-category "all comments" listing (comments.php's own front-end
     * page) -- $whereClauses are already-built SqlCondition fragments
     * (permission/status/search/author/keyword filters, same "caller
     * composes fragments" contract as {@see countAvailableWithConditions()}),
     * combined via {@see applyConditions()}. $userIdColumn/$userEmailColumn
     * resolve \Piwigo\Config\CurrentConfig::userFields() same as
     * {@see findForImage()}'s identical parameters. $sortByColumn/
     * $sortOrder concatenate directly into ORDER BY with no further
     * validation -- caller must restrict these to a known-safe set first
     * (confirmed: Controller\CommentsController's own real caller
     * validates both against small fixed allowlists before this point),
     * same contract as {@see findForImage()}'s own $order.
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
     * Item 14 DQL audit: stays on DBAL -- joins the never-entity-mapped
     * `image_category`, a dynamic-column-name `users` join, MySQL-specific
     * `SQL_CALC_FOUND_ROWS`/`ANY_VALUE()` (neither has a DQL equivalent),
     * and dynamic caller-supplied SqlCondition fragments -- several
     * independent, genuine DQL blockers on the same query.
     *
     * @param list<SqlCondition> $whereClauses
     * @return PaginatedResult<array<string, mixed>>
     */
    public function findAllWithConditions(
        array $whereClauses,
        string $userIdColumn,
        string $userEmailColumn,
        string $sortByColumn,
        string $sortOrder,
        int|string $limit,
        int $offset
    ): PaginatedResult {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select(
                'SQL_CALC_FOUND_ROWS com.id AS comment_id',
                'com.image_id',
                'ANY_VALUE(ic.category_id) AS category_id',
                'com.author',
                'com.author_id',
                'u.' . $userEmailColumn . ' AS user_email',
                'com.email',
                'com.date',
                'com.website_url',
                'com.content',
                'com.validated',
            )
            ->from(Tables::imageCategory(), 'ic')
            ->innerJoin('ic', Tables::comments(), 'com', 'ic.image_id = com.image_id')
            ->leftJoin('com', Tables::users(), 'u', 'u.' . $userIdColumn . ' = com.author_id')
            ->groupBy('comment_id')
            ->orderBy($sortByColumn, $sortOrder)
            ->addOrderBy('comment_id', $sortOrder);

        self::applyConditions($qb, $whereClauses);

        if ($limit !== 'all') {
            $qb->setMaxResults((int) $limit)
                ->setFirstResult($offset);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        // FOUND_ROWS() reflects the immediately-preceding query on the
        // same connection/session -- must run right after, no query
        // in between.
        $total_raw = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('FOUND_ROWS()')
            ->executeQuery()
            ->fetchOne();

        return new PaginatedResult($rows, is_numeric($total_raw) ? (int) $total_raw : null);
    }

    /**
     * Total/validated/pending counts matching $criteria -- Ws\PwgComments::
     * getList()'s own summary block. Deliberately ignores $criteria->status:
     * this computes all/validated/pending counts itself via SUM(), so it
     * needs the status-unfiltered condition set, unlike the 3 sibling
     * methods below.
     *
     * SQL-modernization audit, Item 14 Sub-phase B3: converted to real
     * DQL -- MySQL's `sum(validated = 1)`/`sum(validated = 0)` boolean-
     * expression-as-integer idiom is rewritten as the standard DQL
     * `SUM(CASE WHEN ... THEN 1 ELSE 0 END)`, and
     * {@see applyApiConditions()} resolves the condition-building blocker
     * this method's own docblock used to cite.
     *
     * @return array{all_comments: mixed, validated: mixed, pending: mixed}|null
     */
    public function findSummaryCounts(CommentApiCriteria $criteria): ?array
    {
        $qb = $this->createQueryBuilder('c')
            ->select(
                'COUNT(c.id) AS all_comments',
                'SUM(CASE WHEN c.validated = true THEN 1 ELSE 0 END) AS validated',
                'SUM(CASE WHEN c.validated = false THEN 1 ELSE 0 END) AS pending',
            );

        self::applyApiConditions($qb, $criteria, includeAuthorId: true);

        $row = $qb->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return [
            'all_comments' => $row['all_comments'],
            'validated' => $row['validated'],
            'pending' => $row['pending'],
        ];
    }

    /**
     * Paginated admin comment listing (joined with the commenting image
     * and user) matching $criteria -- Ws\PwgComments::getList()'s own row
     * listing. $userIdColumn/$userUsernameColumn resolve
     * \Piwigo\Config\CurrentConfig::userFields(), same reasoning as
     * {@see findForImage()}'s own equivalents.
     *
     * Item 14 DQL audit: stays on DBAL -- dynamic-column-name `users` join
     * (same blocker as findForImage()), a permanent blocker DQL can never
     * express (see this plan's own "Out of scope" section). Keeps using
     * the SqlCondition/DBAL-based buildApiConditionsWithStatus() rather
     * than {@see applyApiConditionsWithStatus()}'s DQL version -- see that
     * method's own docblock for why.
     *
     * @return list<array<string, mixed>>
     */
    public function findListForAdminWs(
        CommentApiCriteria $criteria,
        string $userIdColumn,
        string $userUsernameColumn,
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
                $userUsernameColumn . ' AS username',
                'ui.status',
                'c.content',
                'i.path',
                'i.representative_ext',
                'i.file',
                'i.date_available',
                'validated',
                'c.anonymous_id',
            )
            ->from(Tables::comments(), 'c')
            ->innerJoin('c', Tables::images(), 'i', 'i.id = c.image_id')
            ->leftJoin('c', Tables::users(), 'u', 'u.' . $userIdColumn . ' = c.author_id')
            ->leftJoin('c', Tables::userInfos(), 'ui', 'ui.user_id = c.author_id')
            ->orderBy('c.date', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        self::applyConditions($qb, self::buildApiConditionsWithStatus($criteria, includeAuthorId: true));

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Earliest/latest `date` matching $criteria -- Ws\PwgComments::
     * getList()'s own "filters" date range.
     *
     * SQL-modernization audit, Item 14 Sub-phase B3: converted to real
     * DQL -- MIN()/MAX() were themselves already standard DQL functions;
     * {@see applyApiConditionsWithStatus()} resolves the condition-building
     * blocker this method's own docblock used to cite.
     *
     * @return array{started_at: mixed, ended_at: mixed}|null
     */
    public function findDateRange(CommentApiCriteria $criteria): ?array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('MIN(c.date) AS started_at', 'MAX(c.date) AS ended_at');

        self::applyApiConditionsWithStatus($qb, $criteria, includeAuthorId: true);

        $row = $qb->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return [
            'started_at' => $row['started_at'],
            'ended_at' => $row['ended_at'],
        ];
    }

    /**
     * Per-author comment counts matching $criteria -- Ws\PwgComments::
     * getList()'s own "filters.nb_authors" breakdown. Deliberately ignores
     * $criteria->authorId -- "how many comments per author" scoped to a
     * single author would be trivially 1, defeating the point of the
     * breakdown; mirrors the original's own
     * unset($where_clauses['author_id']) intent as real code instead of
     * an array-key convention.
     *
     * author isn't functionally dependent on the GROUP BY column
     * (author_id) -- this connection doesn't strip ONLY_FULL_GROUP_BY the
     * way the legacy mysqli connection did, so picking exactly one row's
     * worth of `author` per author_id needs an explicit aggregate.
     * `MIN(author)` (standard SQL-92, portable across every DBAL platform)
     * replaces the originally-ported `ANY_VALUE()` (MySQL-only) --
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2. Changes
     * "arbitrary pick" to "deterministic pick" (a behavior improvement, not
     * just a portability shim); confirmed no test asserts on which
     * specific `author` value comes back, only `author_id`/`nb_authors`.
     *
     * SQL-modernization audit, Item 14 Sub-phase B3: converted to real
     * DQL -- {@see applyApiConditionsWithStatus()} resolves the condition-
     * building blocker this method's own docblock used to cite.
     *
     * @return list<array<string, mixed>>
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

            $result[] = [
                'author' => $row['author'] ?? null,
                'author_id' => $row['author_id'] ?? null,
                'nb_authors' => $row['nb_authors'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Further SQL-modernization audit, Item 14: converted to real DQL --
     * single-table, no WHERE/join DQL can't express.
     *
     * Total row count of `comments` -- Ws\PwgCore::getInfos()'s own
     * "nb_comments" summary figure.
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
     * Further SQL-modernization audit, Item 14: converted to real DQL --
     * single-table, static WHERE, no join DQL can't express.
     *
     * Total count of unvalidated (pending) comments -- Ws\PwgCore::
     * getInfos()'s own "nb_unvalidated_comments" summary figure.
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
