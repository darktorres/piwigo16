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
     * @return list<array<string, mixed>>
     */
    public function findByWhereFragmentOrderedByDate(
        string $whereFragment,
        int $limit,
        int $offset,
        array $params = [],
        array $types = [],
    ): array {
        return $this->conn->executeQuery(
            'SELECT id, date, author, content FROM ' . $this->table('comments')
            . ' WHERE ' . $whereFragment . ' ORDER BY date LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
            $types,
        )->fetchAllAssociative();
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
}
