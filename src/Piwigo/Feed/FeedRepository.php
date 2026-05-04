<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for RSS/notification feed subscriptions (user_feed table). */
final class FeedRepository extends AbstractRepository
{
    /** Return true when a feed with the given id already exists. */
    public function existsById(string $feedId): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('user_feed'))
            ->where('id = :id')
            ->setParameter('id', $feedId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /** Insert a new feed subscription for the given user. last_check is NULL on creation. */
    public function insert(string $feedId, int $userId): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . $this->table('user_feed') . ' (id, user_id, last_check) VALUES (?, ?, NULL)',
            [$feedId, $userId]
        );
    }

    /**
     * Return the user_id and last_check for the given feed id, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $feedId): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('user_id', 'last_check')
            ->from($this->table('user_feed'))
            ->where('id = :id')
            ->setParameter('id', $feedId)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /** Update the last_check timestamp for the given feed. */
    public function updateLastCheck(string $feedId, string $datetime): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('user_feed'))
            ->set('last_check', ':datetime')
            ->where('id = :id')
            ->setParameter('datetime', $datetime)
            ->setParameter('id', $feedId)
            ->executeStatement();
    }
}
