<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Comment\Projection\AdminListingRow;
use Piwigo\Comment\Projection\AuthorCount;
use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Comment\Projection\PictureCommentRow;
use Piwigo\Comment\Projection\RecentCommentRow;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for the comment domain.
 *
 * All queries use parameter binding — no raw string interpolation of
 * user-controlled content (author, comment text, email, website URL).
 */
final class CommentRepository extends AbstractRepository
{
    /**
     * Count users whose $fieldName column equals $username.
     * Used to prevent guests from impersonating existing users.
     *
     * $fieldName is a configured column name from Config::userFields() —
     * it is set by the site admin, not supplied by end users.
     */
    public function countByUsername(string $fieldName, string $username): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('users'))
            ->where($fieldName . ' = :username')
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Anti-flood check: count comments posted by $authorId after the
     * flood window ($antiFloodTime seconds ago).
     * Pass a non-empty $anonymousId to also filter by IP prefix (guests).
     */
    public function countRecentByAuthor(int $authorId, int $antiFloodTime, string $anonymousId = ''): int
    {
        $refDate = new \DateTimeImmutable()
            ->modify('-' . $antiFloodTime . ' seconds')
            ->format('Y-m-d H:i:s');

        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(1)')
            ->from($this->table('comments'))
            ->where('date > :refDate')
            ->andWhere('author_id = :authorId')
            ->setParameter('refDate', $refDate)
            ->setParameter('authorId', $authorId);

        if ($anonymousId !== '') {
            $qb->andWhere('anonymous_id LIKE :anonPattern')
               ->setParameter('anonPattern', $anonymousId . '.%');
        }

        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Insert a new comment and return its auto-generated id.
     *
     * @param array{
     *   author: string,
     *   author_id: int,
     *   anonymous_id: string,
     *   content: string,
     *   validated: bool,
     *   image_id: int,
     *   website_url?: string|null,
     *   email?: string|null,
     * } $data
     */
    public function insert(array $data): int
    {
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $this->conn->insert($this->table('comments'), [
            'author'          => $data['author'],
            'author_id'       => $data['author_id'],
            'anonymous_id'    => $data['anonymous_id'],
            'content'         => $data['content'],
            'date'            => $now,
            'validated'       => $data['validated'] ? 1 : 0,
            'validation_date' => $data['validated'] ? $now : null,
            'image_id'        => $data['image_id'],
            'website_url'     => $data['website_url'] ?? null,
            'email'           => $data['email'] ?? null,
        ]);
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Delete one or more comments. Returns the number of rows deleted.
     * Pass $authorId to restrict deletion to comments owned by that user.
     *
     * @param int|int[] $commentId
     */
    public function delete(int|array $commentId, ?int $authorId = null): int
    {
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('comments'));

        if (is_array($commentId)) {
            $qb->where($qb->expr()->in('id', ':ids'))
               ->setParameter('ids', $commentId, ArrayParameterType::INTEGER);
        } else {
            $qb->where('id = :id')
               ->setParameter('id', $commentId);
        }

        if ($authorId !== null) {
            $qb->andWhere('author_id = :authorId')
               ->setParameter('authorId', $authorId);
        }

        return (int) $qb->executeStatement();
    }

    /**
     * Update a comment's content, website_url, and validation status.
     * Pass $authorId to restrict the UPDATE to comments owned by that user.
     * Returns true if a row was actually changed.
     *
     * @param array{content: string, website_url?: string|null, validated: bool} $data
     */
    public function update(int $commentId, array $data, ?int $authorId = null): bool
    {
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $validated = $data['validated'];

        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('comments'))
            ->set('content', ':content')
            ->set('website_url', ':websiteUrl')
            ->set('validated', ':validated')
            ->set('validation_date', ':validationDate')
            ->where('id = :id')
            ->setParameter('content', $data['content'])
            ->setParameter('websiteUrl', $data['website_url'] ?? null)
            ->setParameter('validated', $validated ? 1 : 0)
            ->setParameter('validationDate', $validated ? $now : null)
            ->setParameter('id', $commentId);

        if ($authorId !== null) {
            $qb->andWhere('author_id = :authorId')
               ->setParameter('authorId', $authorId);
        }

        return $qb->executeStatement() > 0;
    }

    /**
     * Return the author_id for the given comment, or null if not found.
     */
    public function getAuthorId(int $commentId): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('author_id')
            ->from($this->table('comments'))
            ->where('id = :id')
            ->setParameter('id', $commentId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Mark one or more comments as validated.
     *
     * @param int|int[] $commentId
     */
    public function setValidated(int|array $commentId): void
    {
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('comments'))
            ->set('validated', ':validated')
            ->set('validation_date', ':now')
            ->setParameter('validated', 1)
            ->setParameter('now', $now);

        if (is_array($commentId)) {
            $qb->where($qb->expr()->in('id', ':ids'))
               ->setParameter('ids', $commentId, ArrayParameterType::INTEGER);
        } else {
            $qb->where('id = :id')
               ->setParameter('id', $commentId);
        }

        $qb->executeStatement();
    }

    /** Total number of comments (validated and unvalidated). */
    public function countAll(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('comments'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Count comments awaiting validation. */
    public function countUnvalidated(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('comments'))
            ->where('validated = 0')
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Clear the nb_available_comments cache for all users so the next
     * request recomputes it from the live comment count.
     */
    public function clearNbAvailableCommentsCache(): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('user_cache'))
            ->set('nb_available_comments', 'NULL')
            ->executeStatement();
    }

    /**
     * Count available comments for the user's permission state — joins
     * comments to image_category and applies the caller's permission filter.
     *
     * @param list<string>                                                $where
     * @param list<mixed>                                                 $params
     * @param list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     */
    public function countAvailableCommentsForUser(array $where, array $params, array $types): int
    {
        $sql = 'SELECT COUNT(DISTINCT(com.id)) FROM ' . $this->table('image_category') . ' AS ic'
            . ' INNER JOIN ' . $this->table('comments') . ' AS com ON ic.image_id = com.image_id'
            . ' WHERE ' . implode(' AND ', $where);
        $value = $this->conn->executeQuery($sql, $params, $types)->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Store the per-user nb_available_comments cache value. */
    public function setNbAvailableCommentsCache(int $userId, int $nb): void
    {
        $this->conn->update(
            $this->table('user_cache'),
            ['nb_available_comments' => $nb],
            ['user_id' => $userId],
        );
    }

    /**
     * Count comments matching a caller-built WHERE fragment with bound
     * params. Used by the WS getInfo endpoint which composes WHERE based on
     * visibility/admin-vs-public branching.
     *
     * @param  list<mixed>  $params
     * @param  list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     */
    public function countByWhereFragment(string $whereFragment, array $params = [], array $types = []): int
    {
        $value = $this->conn->executeQuery(
            'SELECT COUNT(id) AS nb_comments FROM ' . $this->table('comments') . ' WHERE ' . $whereFragment,
            $params,
            $types,
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return (id, date, author, content) for comments matching the caller's
     * WHERE fragment, ordered by date with the given LIMIT/OFFSET.
     *
     * @param  list<mixed>  $params
     * @param  list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     * @return list<CommentSummary>
     */
    public function findByWhereFragmentOrderedByDate(
        string $whereFragment,
        int $limit,
        int $offset,
        array $params = [],
        array $types = [],
    ): array {
        $rows = $this->conn->executeQuery(
            'SELECT id, date, author, content FROM ' . $this->table('comments')
            . ' WHERE ' . $whereFragment . ' ORDER BY date LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
            $types,
        )->fetchAllAssociative();
        return array_map(CommentSummary::fromRow(...), $rows);
    }

    /**
     * For each given image id, return its count of validated comments. Result
     * is keyed by image_id; images with no validated comments are omitted.
     *
     * @param list<int> $imageIds
     * @return array<int|string, int>
     */
    public function countValidatedByImageIdsKeyedByImageId(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id', 'COUNT(*) AS nb_comments')
            ->from($this->table('comments'))
            ->where('validated = 1')
            ->groupBy('image_id');
        $qb->andWhere($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key       = is_scalar($row['image_id']) ? (string) $row['image_id'] : '';
            $out[$key] = is_numeric($row['nb_comments']) ? (int) $row['nb_comments'] : 0;
        }
        return $out;
    }

    /**
     * Return (all_comments, validated, pending) counters matching the supplied
     * WHERE fragment list. Used by the admin comments-list API to render the
     * top summary row.
     *
     * @param list<string>                                $whereClauses
     * @param list<mixed>                                 $params
     * @param list<ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     * @return array{all_comments: int, validated: int, pending: int}
     */
    public function findCommentsSummary(array $whereClauses, array $params, array $types): array
    {
        if ($whereClauses === []) {
            $whereClauses = ['1=1'];
        }
        $sql = 'SELECT COUNT(*) AS all_comments,'
            . ' SUM(validated = 1) AS validated, SUM(validated = 0) AS pending'
            . ' FROM ' . $this->table('comments')
            . ' WHERE ' . implode(' AND ', $whereClauses);
        $row = $this->conn->executeQuery($sql, $params, $types)->fetchAssociative();
        if ($row === false) {
            return ['all_comments' => 0, 'validated' => 0, 'pending' => 0];
        }
        return [
            'all_comments' => is_numeric($row['all_comments'] ?? null) ? (int) $row['all_comments'] : 0,
            'validated'    => is_numeric($row['validated'] ?? null) ? (int) $row['validated'] : 0,
            'pending'      => is_numeric($row['pending'] ?? null) ? (int) $row['pending'] : 0,
        ];
    }

    /**
     * Paginated admin comments listing — comments joined with image, optional
     * user, and user_infos rows.
     *
     * @param list<string>                                $whereClauses
     * @param list<mixed>                                 $params
     * @param list<ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     * @return list<AdminListingRow>
     */
    public function findCommentsAdminList(
        array $whereClauses,
        array $params,
        array $types,
        string $usersTable,
        string $userIdField,
        string $usernameField,
        int $limit,
        int $offset,
    ): array {
        if ($whereClauses === []) {
            $whereClauses = ['1=1'];
        }
        $sql = 'SELECT c.id, c.image_id, c.date, c.author, c.author_id, ' . $usernameField . ' AS username,'
            . ' ui.status, c.content, i.path, i.representative_ext, i.file, i.date_available, validated, c.anonymous_id'
            . ' FROM ' . $this->table('comments') . ' AS c'
            . ' INNER JOIN ' . $this->table('images') . ' AS i ON i.id = c.image_id'
            . ' LEFT JOIN ' . $usersTable . ' AS u ON u.' . $userIdField . ' = c.author_id'
            . ' LEFT JOIN ' . $this->table('user_infos') . ' AS ui ON ui.user_id = c.author_id'
            . ' WHERE ' . implode(' AND ', $whereClauses)
            . ' ORDER BY c.date DESC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        return array_map(AdminListingRow::fromRow(...), $rows);
    }

    /**
     * MIN(date) / MAX(date) over the filtered comment set.
     *
     * @param list<string>                                $whereClauses
     * @param list<mixed>                                 $params
     * @param list<ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     * @return array{started_at: ?string, ended_at: ?string}
     */
    public function findCommentDateRange(array $whereClauses, array $params, array $types): array
    {
        if ($whereClauses === []) {
            $whereClauses = ['1=1'];
        }
        $row = $this->conn->executeQuery(
            'SELECT MIN(date) AS started_at, MAX(date) AS ended_at FROM ' . $this->table('comments')
            . ' WHERE ' . implode(' AND ', $whereClauses),
            $params,
            $types,
        )->fetchAssociative();
        if ($row === false) {
            return ['started_at' => null, 'ended_at' => null];
        }
        return [
            'started_at' => is_string($row['started_at'] ?? null) ? $row['started_at'] : null,
            'ended_at'   => is_string($row['ended_at'] ?? null) ? $row['ended_at'] : null,
        ];
    }

    /**
     * Author breakdown — (author, author_id, nb_authors) tuples grouped by author_id.
     *
     * @param list<string>                                $whereClauses
     * @param list<mixed>                                 $params
     * @param list<ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     * @return list<AuthorCount>
     */
    public function findCommentAuthorCounts(array $whereClauses, array $params, array $types): array
    {
        if ($whereClauses === []) {
            $whereClauses = ['1=1'];
        }
        $rows = $this->conn->executeQuery(
            'SELECT author, author_id, COUNT(*) AS nb_authors FROM ' . $this->table('comments')
            . ' WHERE ' . implode(' AND ', $whereClauses)
            . ' GROUP BY author_id',
            $params,
            $types,
        )->fetchAllAssociative();
        return array_map(AuthorCount::fromRow(...), $rows);
    }

    /**
     * Count comments matching $whereClauses (joined with AND), with the
     * standard "image_category JOIN comments LEFT JOIN users" plumbing.
     *
     * $usersTable and $userIdField are admin-configured.
     *
     * @param list<string>                                $whereClauses
     * @param list<mixed>                                 $params
     * @param list<ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     */
    public function countFilteredComments(
        array $whereClauses,
        array $params,
        array $types,
        string $usersTable,
        string $userIdField,
    ): int {
        $sql = 'SELECT COUNT(DISTINCT com.id)'
            . ' FROM ' . $this->table('image_category') . ' AS ic'
            . ' INNER JOIN ' . $this->table('comments') . ' AS com ON ic.image_id = com.image_id'
            . ' LEFT JOIN ' . $usersTable . ' AS u ON u.' . $userIdField . ' = com.author_id'
            . ' WHERE ' . implode("\n    AND ", $whereClauses);
        $value = $this->conn->executeQuery($sql, $params, $types)->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Paginated list of comments for the admin "Recent comments" page. Same
     * JOIN plumbing as countFilteredComments; ORDER BY composes $sortBy +
     * $sortOrder which the caller is responsible for whitelisting.
     *
     * Pass $limit = -1 to skip the LIMIT/OFFSET clause ("all").
     *
     * @param list<string>                                $whereClauses
     * @param list<mixed>                                 $params
     * @param list<ArrayParameterType|\Doctrine\DBAL\ParameterType> $types
     * @return list<RecentCommentRow>
     */
    public function findFilteredComments(
        array $whereClauses,
        array $params,
        array $types,
        string $usersTable,
        string $userIdField,
        string $userEmailField,
        string $sortBy,
        string $sortOrder,
        int $limit,
        int $offset,
    ): array {
        $sql = 'SELECT com.id AS comment_id, com.image_id, ic.category_id, com.author, com.author_id,'
            . ' u.' . $userEmailField . ' AS user_email, com.email, com.date, com.website_url, com.content, com.validated'
            . ' FROM ' . $this->table('image_category') . ' AS ic'
            . ' INNER JOIN ' . $this->table('comments') . ' AS com ON ic.image_id = com.image_id'
            . ' LEFT JOIN ' . $usersTable . ' AS u ON u.' . $userIdField . ' = com.author_id'
            . ' WHERE ' . implode("\n    AND ", $whereClauses)
            . ' GROUP BY comment_id'
            . ' ORDER BY ' . $sortBy . ' ' . $sortOrder;
        if ($limit >= 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        return array_map(RecentCommentRow::fromRow(...), $rows);
    }

    /**
     * COUNT(*) of comments on a single image, optionally filtered to only the
     * validated ones (validated = 1).
     */
    public function countByImageId(int $imageId, bool $validatedOnly): int
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('comments'))
            ->where('image_id = :imageId')
            ->setParameter('imageId', $imageId);
        if ($validatedOnly) {
            $qb->andWhere('validated = 1');
        }
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Paginated list of comment rows for one image, LEFT JOINed with the
     * configurable users table so callers can pick up the commenter's email.
     *
     * $usersTable, $userIdField and $userEmailField are admin-configured (not
     * user-supplied) — safe to interpolate. $order is 'ASC' | 'DESC' (caller
     * must validate; this method enforces the whitelist via fail-closed).
     *
     * @return list<PictureCommentRow>
     */
    public function findForImagePage(
        int $imageId,
        bool $validatedOnly,
        string $order,
        int $limit,
        int $offset,
        string $usersTable,
        string $userIdField,
        string $userEmailField,
    ): array {
        $orderSafe = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $sql = '
SELECT com.id, com.author, com.author_id, u.' . $userEmailField . ' AS user_email,
       com.date, com.image_id, com.website_url, com.email, com.content, com.validated
  FROM ' . $this->table('comments') . ' AS com
  LEFT JOIN ' . $usersTable . ' AS u ON u.' . $userIdField . ' = author_id
  WHERE com.image_id = ?';
        if ($validatedOnly) {
            $sql .= ' AND com.validated = 1';
        }
        $sql .= ' ORDER BY com.date ' . $orderSafe . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = $this->conn->executeQuery(
            $sql,
            [$imageId],
            [\Doctrine\DBAL\ParameterType::INTEGER],
        )->fetchAllAssociative();
        return array_map(PictureCommentRow::fromRow(...), $rows);
    }
}
