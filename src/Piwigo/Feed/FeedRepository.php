<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Override;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Feed\Projection\FeedInfo;
use Piwigo\Users\UserRelatedTableSyncInterface;

/**
 * Persistence layer for the per-user RSS feed identifier domain.
 *
 * @extends EntityRepository<FeedEntity>
 */
final class FeedRepository extends EntityRepository implements UserRelatedTableSyncInterface
{
    public function existsById(string $id): bool
    {
        return $this->find($id) !== null;
    }

    public function insert(string $id, int $userId): void
    {
        $em = $this->getEntityManager();
        $em->persist(new FeedEntity($id, UserId::from($userId)));
        $em->flush();
    }

    /**
     * Returns the owning user id and last-check timestamp for a feed
     * identifier, or null if the identifier doesn't exist.
     */
    public function findById(string $id): ?FeedInfo
    {
        $entity = $this->find($id);

        return $entity === null ? null : new FeedInfo($entity->userId->value, $entity->lastCheck);
    }

    /**
     * The timestamp is computed by the caller and bound as a parameter --
     * cross-provider safe, not SQL's NOW()/SUBDATE() (same reasoning as
     * SessionRepository::gc()).
     */
    public function updateLastCheck(string $id, DateTimeImmutable $lastCheck): void
    {
        $entity = $this->find($id);
        if ($entity === null) {
            return;
        }

        $entity->lastCheck = $lastCheck;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * {@see UserRelatedTableSyncInterface} implementation.
     * `getSingleColumnResult()` never applies custom Doctrine Type
     * conversion, so `f.userId` comes back as a plain scalar despite being
     * `UserId`-typed.
     *
     * @return list<UserId>
     */
    #[Override]
    public function findDistinctUserIds(): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('DISTINCT f.userId')
            ->getQuery()
            ->getSingleColumnResult();

        $ids = [];
        foreach ($rows as $row) {
            $id = $row instanceof UserId ? $row : UserId::tryFrom($row);
            if ($id instanceof UserId) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * {@see UserRelatedTableSyncInterface} implementation.
     *
     * @param list<UserId> $userIds
     */
    #[Override]
    public function deleteForUserIds(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(FeedEntity::class, 'f')
            ->where('f.userId IN (:userIds)')
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }
}
