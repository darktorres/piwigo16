<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\DBAL\ArrayParameterType;
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
        $refDate = (new \DateTimeImmutable())
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
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert($this->table('comments'), [
            'author'          => $data['author'],
            'author_id'       => $data['author_id'],
            'anonymous_id'    => $data['anonymous_id'],
            'content'         => $data['content'],
            'date'            => $now,
            'validated'       => $data['validated'] ? 'true' : 'false',
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
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
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
            ->setParameter('validated', $validated ? 'true' : 'false')
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
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('comments'))
            ->set('validated', ':validated')
            ->set('validation_date', ':now')
            ->setParameter('validated', 'true')
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
}
