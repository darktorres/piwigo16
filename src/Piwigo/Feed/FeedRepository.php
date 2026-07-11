<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use Doctrine\DBAL\Types\Types;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the per-user RSS feed identifier domain.
 */
final class FeedRepository extends AbstractRepository
{
    public function existsById(string $id): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userFeed())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int) $count > 0;
    }

    public function insert(string $id, int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::userFeed())
            ->values([
                'id' => ':id',
                'user_id' => ':user_id',
                'last_check' => ':last_check',
            ])
            ->setParameter('id', $id)
            ->setParameter('user_id', $userId)
            ->setParameter('last_check', null)
            ->executeStatement();
    }

    /**
     * Returns the owning user id and last-check timestamp for a feed
     * identifier, or null if the identifier doesn't exist.
     *
     * @return array{userId: int, lastCheck: ?\DateTimeImmutable}|null
     */
    public function findById(string $id): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('user_id', 'last_check')
            ->from(Tables::userFeed())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $lastCheckValue = $row['last_check'];

        return [
            'userId' => is_numeric($row['user_id']) ? (int) $row['user_id'] : 0,
            'lastCheck' => is_string($lastCheckValue) && $lastCheckValue !== '' ? new \DateTimeImmutable($lastCheckValue) : null,
        ];
    }

    /**
     * The timestamp is computed by the caller and bound as a parameter --
     * cross-provider safe, not SQL's NOW()/SUBDATE() (same reasoning as
     * SessionRepository::gc()).
     */
    public function updateLastCheck(string $id, \DateTimeImmutable $lastCheck): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userFeed())
            ->set('last_check', ':last_check')
            ->where('id = :id')
            ->setParameter('last_check', $lastCheck, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->executeStatement();
    }
}
