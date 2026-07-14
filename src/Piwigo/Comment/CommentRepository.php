<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the comment domain: `comments` itself, plus two
 * thin cross-domain touches kept inline rather than promoted into a new
 * namespace dependency (same "thin cross-domain touch stays inline"
 * precedent as GroupRepository::findMemberUsernames() reading `users`
 * directly) -- usernameExists() (the guest-impersonation guard in
 * insert_user_comment()) and clearNbCommentsCache() (`user_cache`).
 */
final class CommentRepository extends AbstractRepository
{
    /**
     * @param array{
     *   author: string,
     *   authorId: int,
     *   anonymousId: string,
     *   content: string,
     *   validated: bool,
     *   imageId: int,
     *   websiteUrl: ?string,
     *   email: ?string,
     * } $data
     */
    public function insert(array $data): int
    {
        // pwg_now() rather than SQL's NOW() -- see countRecentComments()'s
        // own docblock; date/validation_date must share the same time
        // reference that the flood-window comparison reads, not the real
        // DB-server clock.
        $now = pwg_now()
            ->format('Y-m-d H:i:s');

        $this->conn->createQueryBuilder()
            ->insert(Tables::comments())
            ->values([
                'author' => ':author',
                'author_id' => ':authorId',
                'anonymous_id' => ':anonymousId',
                'content' => ':content',
                'date' => ':now',
                'validated' => ':validated',
                'validation_date' => $data['validated'] ? ':now' : 'NULL',
                'image_id' => ':imageId',
                'website_url' => ':websiteUrl',
                'email' => ':email',
            ])
            ->setParameter('author', $data['author'])
            ->setParameter('authorId', $data['authorId'])
            ->setParameter('anonymousId', $data['anonymousId'])
            ->setParameter('content', $data['content'])
            ->setParameter('now', $now)
            ->setParameter('validated', $data['validated'] ? 'true' : 'false')
            ->setParameter('imageId', $data['imageId'])
            ->setParameter('websiteUrl', $data['websiteUrl'])
            ->setParameter('email', $data['email'])
            ->executeStatement();

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Deletes the given comments. When $authorId is non-null, only rows
     * owned by that author are deleted (matches the original
     * delete_user_comment()'s non-admin restriction). Returns the number of
     * rows actually deleted.
     *
     * @param array<int, int> $ids
     */
    public function delete(array $ids, ?int $authorId): int
    {
        if ($ids === []) {
            return 0;
        }

        $qb = $this->conn->createQueryBuilder()
            ->delete(Tables::comments())
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER);

        if ($authorId !== null) {
            $qb->andWhere('author_id = :authorId')
                ->setParameter('authorId', $authorId);
        }

        return (int) $qb->executeStatement();
    }

    /**
     * Updates a single comment's editable fields. When $authorId is
     * non-null, only a row owned by that author is updated (matches the
     * original update_user_comment()'s non-admin restriction). Returns
     * whether a row was actually updated.
     *
     * @param array{content: string, websiteUrl: ?string, validated: bool} $data
     */
    public function update(int $id, array $data, ?int $authorId): bool
    {
        $qb = $this->conn->createQueryBuilder()
            ->update(Tables::comments())
            ->set('content', ':content')
            ->set('website_url', ':websiteUrl')
            ->set('validated', ':validated')
            ->set('validation_date', $data['validated'] ? ':now' : 'NULL')
            ->where('id = :id')
            ->setParameter('content', $data['content'])
            ->setParameter('websiteUrl', $data['websiteUrl'])
            ->setParameter('validated', $data['validated'] ? 'true' : 'false')
            ->setParameter('now', pwg_now()->format('Y-m-d H:i:s'))
            ->setParameter('id', $id);

        if ($authorId !== null) {
            $qb->andWhere('author_id = :authorId')
                ->setParameter('authorId', $authorId);
        }

        return $qb->executeStatement() > 0;
    }

    /**
     * Raw author_id of a comment: `false` when no such comment exists,
     * `null` when the comment exists but has no owner (anonymous/guest
     * comment -- author_id allows NULL), otherwise the numeric-string id.
     * Doctrine's fetchOne() already distinguishes "no row" (false) from
     * "row with a NULL column" (null), which is exactly the distinction
     * get_comment_author_id() needs.
     */
    public function findAuthorId(int $id): string|null|false
    {
        $value = $this->conn->createQueryBuilder()
            ->select('author_id')
            ->from(Tables::comments())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();

        if ($value === false || $value === null) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<int, int> $ids
     */
    public function validate(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->conn->createQueryBuilder()
            ->update(Tables::comments())
            ->set('validated', ':validated')
            ->set('validation_date', ':now')
            ->where('id IN (:ids)')
            ->setParameter('validated', 'true')
            ->setParameter('now', pwg_now()->format('Y-m-d H:i:s'))
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * Number of comments posted by $authorId (and, for non-classic users,
     * also matching the $anonymousIdPrefix.* anonymous_id pattern) within
     * the last $antiFloodSeconds seconds. Used by the anti-flood check.
     */
    public function countRecentComments(int $authorId, ?string $anonymousIdPrefix, int $antiFloodSeconds): int
    {
        // pwg_now() rather than SQL's NOW() (the real DB-server clock) --
        // matches SessionRepository's own reasoning: invisible to
        // PIWIGO_TEST_NOW, so fixture comments dated relative to it would
        // read as "within the flood window" whenever real time drifted
        // away from the fixture's own dates.
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(1)')
            ->from(Tables::comments())
            ->where('date > SUBDATE(:now, INTERVAL :seconds SECOND)')
            ->andWhere('author_id = :authorId')
            ->setParameter('now', pwg_now()->format('Y-m-d H:i:s'))
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
     * the column's collation (this schema's `users` table uses a `_ci`
     * collation, so this is effectively case-insensitive), not on
     * anything this query controls. $usernameColumn is the configurable
     * DB column name (see $conf['user_fields']), not user-controlled.
     */
    public function usernameExists(string $usernameColumn, string $username): bool
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::users())
            ->where($usernameColumn . ' = :username')
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    public function clearNbCommentsCache(): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userCache())
            ->set('nb_available_comments', 'NULL')
            ->executeStatement();
    }

    /**
     * Number of comments on a single image (the picture page's comment
     * count), optionally restricted to validated ones (non-admin viewers).
     */
    public function countForImage(int $imageId, bool $onlyValidated): int
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*) AS nb_comments')
            ->from(Tables::comments())
            ->where('image_id = :imageId')
            ->setParameter('imageId', $imageId);

        if ($onlyValidated) {
            $qb->andWhere("validated = 'true'");
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Paginated comment listing for a single image, joined with the
     * commenting user's email column (looked up by DB primary key --
     * $conf['user_fields'] maps the generic 'id'/'email' names to the
     * actual column names, resolved by the caller since that's
     * config-domain knowledge, not persistence-domain).
     *
     * @param string $order 'ASC'|'asc'|'DESC'|'desc' only -- the caller must
     *   validate this before calling (matches the original's own
     *   in_array(strtoupper($x), ['ASC', 'DESC']) check), this method
     *   concatenates it directly into the query with no further validation.
     * @return list<array<string, mixed>>
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
        $qb = $this->conn->createQueryBuilder()
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
            $qb->andWhere("com.validated = 'true'");
        }

        /** @var list<array<string, mixed>> */
        return $qb->executeQuery()
            ->fetchAllAssociative();
    }
}
