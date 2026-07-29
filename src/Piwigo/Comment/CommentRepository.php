<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Comment\Projection\Comment;
use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Core\CommentCounterInterface;
use Piwigo\Core\Env;
use Piwigo\Db\Tables;

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
     */
    public function countRecentComments(int $authorId, ?string $anonymousIdPrefix, int $antiFloodSeconds): int
    {
        // Env::now() rather than SQL's NOW() (the real DB-server clock) --
        // matches SessionRepository's own reasoning: invisible to
        // PIWIGO_TEST_NOW, so fixture comments dated relative to it would
        // read as "within the flood window" whenever real time drifted
        // away from the fixture's own dates.
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(1)')
            ->from(Tables::comments())
            ->where('date > SUBDATE(:now, INTERVAL :seconds SECOND)')
            ->andWhere('author_id = :authorId')
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('seconds', $antiFloodSeconds)
            ->setParameter('authorId', $authorId);

        if ($anonymousIdPrefix !== null) {
            $qb->andWhere('anonymous_id LIKE :anonymousIdPrefix')
                ->setParameter('anonymousIdPrefix', $anonymousIdPrefix . '.%');
        }

        $value = $qb->executeQuery()
            ->fetchOne();

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
     * Distinct comment count for the given permission/validation condition
     * fragments -- CommentService::getNbAvailableComments()'s own
     * PermissionService::getSqlConditionFandF() output, already trusted SQL
     * (built server-side from permission ids, not user input), spliced
     * verbatim as raw WHERE fragments -- matches the original's own string
     * concatenation.
     *
     * @param  list<string>  $whereClauses
     */
    public function countAvailableWithConditions(array $whereClauses): int
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT com.id)')
            ->from(Tables::imageCategory(), 'ic')
            ->join('ic', Tables::comments(), 'com', 'ic.image_id = com.image_id');

        foreach ($whereClauses as $clause) {
            $qb->andWhere($clause);
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Number of comments on a single image (the picture page's comment
     * count), optionally restricted to validated ones (non-admin viewers).
     */
    public function countForImage(int $imageId, bool $onlyValidated): int
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*) AS nb_comments')
            ->from(Tables::comments())
            ->where('image_id = :imageId')
            ->setParameter('imageId', $imageId);

        if ($onlyValidated) {
            // A real tinyint(1) column now (Comment domain Stage 1a) -- a
            // numeric literal, not the old enum('true','false') string;
            // MySQL's non-numeric-string-to-int coercion would otherwise
            // silently convert 'true' to 0 too, inverting this filter to
            // count unvalidated comments instead (same bug class
            // Category's own commentable/visible retype found).
            $qb->andWhere('validated = 1');
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
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
            ->getConnection()
            ->createQueryBuilder()
            ->select('id', 'date', 'author', 'content')
            ->from(Tables::comments())
            ->where('image_id = :imageId')
            ->setParameter('imageId', $imageId)
            ->orderBy('date', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($onlyValidated) {
            $qb->andWhere('validated = 1');
        }

        return array_map(
            CommentSummary::fromRow(...),
            $qb->executeQuery()
                ->fetchAllAssociative()
        );
    }

    /**
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
            ->getConnection()
            ->createQueryBuilder()
            ->select('image_id', 'COUNT(*) AS nb_comments')
            ->from(Tables::comments())
            ->where('validated = 1')
            ->andWhere('image_id IN (:imageIds)')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::STRING)
            ->groupBy('image_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $byImageId = [];
        foreach ($rows as $row) {
            $imageId = $row['image_id'] ?? null;
            $nbComments = $row['nb_comments'] ?? null;
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
     * page) -- $whereClauses are already-built, trusted SQL fragments
     * (permission/status/search filters, same "caller composes trusted
     * fragments" contract as {@see countAvailableWithConditions()}),
     * ANDed together. $userIdColumn/$userEmailColumn resolve
     * \Piwigo\Config\CurrentConfig::userFields() same as
     * {@see findForImage()}'s identical parameters. $sortByColumn/
     * $sortOrder concatenate directly into ORDER BY with no further
     * validation -- caller must restrict these to a known-safe set first,
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
     * @param list<string> $whereClauses
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
        $conn = $this->getEntityManager()
            ->getConnection();

        $sql = '
SELECT SQL_CALC_FOUND_ROWS com.id AS comment_id,
   com.image_id,
   ANY_VALUE(ic.category_id) AS category_id,
   com.author,
   com.author_id,
   u.' . $userEmailColumn . ' AS user_email,
   com.email,
   com.date,
   com.website_url,
   com.content,
   com.validated
  FROM ' . Tables::imageCategory() . ' AS ic
INNER JOIN ' . Tables::comments() . ' AS com
ON ic.image_id = com.image_id
LEFT JOIN ' . Tables::users() . ' AS u
ON u.' . $userIdColumn . ' = com.author_id
  WHERE ' . implode('
AND ', $whereClauses) . '
  GROUP BY comment_id
  ORDER BY ' . $sortByColumn . ' ' . $sortOrder . ', comment_id ' . $sortOrder;

        if ($limit !== 'all') {
            $sql .= '
  LIMIT ' . $limit . ' OFFSET ' . $offset;
        }

        $sql .= '
;';

        $rows = $conn->fetchAllAssociative($sql);

        // FOUND_ROWS() reflects the immediately-preceding query on the
        // same connection/session.
        $total_raw = $conn->fetchOne('SELECT FOUND_ROWS()');

        return new PaginatedResult($rows, is_numeric($total_raw) ? (int) $total_raw : null);
    }

    /**
     * Total/validated/pending counts matching already-built $whereClauses
     * -- Ws\PwgComments::getList()'s own summary block, same "caller
     * composes trusted fragments" contract as
     * {@see countAvailableWithConditions()} above.
     *
     * @param  list<string>  $whereClauses
     * @return array{all_comments: mixed, validated: mixed, pending: mixed}|null
     */
    public function findSummaryCounts(array $whereClauses): ?array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative('
SELECT
  count(*) as all_comments,
  sum(validated = 1) as validated,
  sum(validated = 0) as pending
FROM ' . Tables::comments() . '
WHERE ' . implode(' AND ', $whereClauses) . '
;');

        if ($row === false) {
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
     * and user) matching already-built $whereClauses -- Ws\PwgComments::
     * getList()'s own row listing. $userIdColumn/$userUsernameColumn
     * resolve \Piwigo\Config\CurrentConfig::userFields(), same reasoning
     * as {@see findForImage()}'s own equivalents.
     *
     * @param  list<string>  $whereClauses
     * @return list<array<string, mixed>>
     */
    public function findListForAdminWs(
        array $whereClauses,
        string $userIdColumn,
        string $userUsernameColumn,
        int $offset,
        int $limit
    ): array {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    c.id,
    c.image_id,
    c.date,
    c.author,
    c.author_id,
    ' . $userUsernameColumn . ' AS username,
    ui.status,
    c.content,
    i.path,
    i.representative_ext,
    i.file,
    i.date_available,
    validated,
    c.anonymous_id
  FROM ' . Tables::comments() . ' AS c
    INNER JOIN ' . Tables::images() . ' AS i
      ON i.id = c.image_id
    LEFT JOIN ' . Tables::users() . ' AS u
      ON u.' . $userIdColumn . ' = c.author_id
    LEFT JOIN ' . Tables::userInfos() . ' AS ui
      ON ui.user_id = c.author_id
  WHERE ' . implode(' AND ', $whereClauses) . '
  ORDER BY c.date DESC, c.id DESC
  LIMIT ' . $offset . ', ' . $limit . '
;');
    }

    /**
     * Earliest/latest `date` matching already-built $whereClauses --
     * Ws\PwgComments::getList()'s own "filters" date range.
     *
     * @param  list<string>  $whereClauses
     * @return array{started_at: mixed, ended_at: mixed}|null
     */
    public function findDateRange(array $whereClauses): ?array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative('
SELECT
  MIN(date) AS started_at,
  MAX(date) AS ended_at
FROM ' . Tables::comments() . '
WHERE ' . implode(' AND ', $whereClauses) . '
;');

        if ($row === false) {
            return null;
        }

        return [
            'started_at' => $row['started_at'],
            'ended_at' => $row['ended_at'],
        ];
    }

    /**
     * Per-author comment counts matching already-built $whereClauses --
     * Ws\PwgComments::getList()'s own "filters.nb_authors" breakdown.
     * ANY_VALUE(author): author isn't functionally dependent on the GROUP
     * BY column (author_id) -- this connection doesn't strip
     * ONLY_FULL_GROUP_BY the way the legacy mysqli connection did, so this
     * needs the explicit opt-out to keep selecting exactly one arbitrary
     * author name per author_id, matching the original grouping/row count
     * exactly.
     *
     * @param  list<string>  $whereClauses
     * @return list<array<string, mixed>>
     */
    public function findAuthorCounts(array $whereClauses): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
  ANY_VALUE(author) AS author,
  author_id,
  count(*) as nb_authors
FROM ' . Tables::comments() . '
WHERE ' . implode(' AND ', $whereClauses) . '
GROUP BY author_id
;');
    }

    /**
     * Total row count of `comments` -- Ws\PwgCore::getInfos()'s own
     * "nb_comments" summary figure.
     */
    public function countAll(): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM ' . Tables::comments() . ';');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Total count of unvalidated (pending) comments -- Ws\PwgCore::
     * getInfos()'s own "nb_unvalidated_comments" summary figure.
     */
    public function countUnvalidated(): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM ' . Tables::comments() . ' WHERE validated=0;');

        return is_numeric($value) ? (int) $value : 0;
    }
}
