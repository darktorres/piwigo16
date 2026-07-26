<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use Doctrine\ORM\EntityRepository;

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
        $em->persist(new FeedEntity($id, $userId));
        $em->flush();
    }

    /**
     * Returns the owning user id and last-check timestamp for a feed
     * identifier, or null if the identifier doesn't exist.
     *
     * @return array{userId: int, lastCheck: ?\DateTimeImmutable}|null
     */
    public function findById(string $id): ?array
    {
        $entity = $this->find($id);

        return $entity === null ? null : [
            'userId' => $entity->userId,
            'lastCheck' => $entity->lastCheck,
        ];
    }

    /**
     * The timestamp is computed by the caller and bound as a parameter --
     * cross-provider safe, not SQL's NOW()/SUBDATE() (same reasoning as
     * SessionRepository::gc()).
     */
    public function updateLastCheck(string $id, \DateTimeImmutable $lastCheck): void
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
