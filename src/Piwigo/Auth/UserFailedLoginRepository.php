<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<UserFailedLoginEntity>
 */
final class UserFailedLoginRepository extends EntityRepository
{
    public function recordFailure(?int $userId, string $ip, string $now): void
    {
        $this->getEntityManager()
            ->persist(new UserFailedLoginEntity($userId, $ip, $now));
        $this->getEntityManager()
            ->flush();
    }

    public function countRecentByUserId(int $userId, string $since): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.userId = :userId')
            ->andWhere('f.attemptedAt >= :since')
            ->setParameter('userId', $userId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRecentByIp(string $ip, string $since): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.ip = :ip')
            ->andWhere('f.attemptedAt >= :since')
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function purgeOlderThan(string $before): int
    {
        return $this->createQueryBuilder('f')
            ->delete()
            ->where('f.attemptedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
