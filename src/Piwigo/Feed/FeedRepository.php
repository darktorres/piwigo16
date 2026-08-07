<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Feed\Projection\FeedInfo;

/**
 * Persistence layer for the per-user RSS feed identifier domain.
 *
 * @extends EntityRepository<FeedEntity>
 */
final class FeedRepository extends EntityRepository
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
}
