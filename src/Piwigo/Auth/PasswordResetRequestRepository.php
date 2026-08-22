<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\UserId;

/**
 * [P44-L] Same shape as {@see UserFailedLoginRepository} -- see that
 * class's own methods for the reasoning shared verbatim here (the
 * `ip_address_graceful`-Typed `f.ip` binding needing a parsed `IpAddress`
 * rather than a raw string, the `IpAddress::tryFrom()`-fails-so-skip-the-
 * query fallback).
 *
 * @extends EntityRepository<PasswordResetRequestEntity>
 */
final class PasswordResetRequestRepository extends EntityRepository
{
    public function recordRequest(?int $userId, string $ip, string $now): void
    {
        $this->getEntityManager()
            ->persist(new PasswordResetRequestEntity($userId !== null ? UserId::from($userId) : null, IpAddress::tryFrom($ip), $now));
        $this->getEntityManager()
            ->flush();
    }

    public function countRecentByUserId(int $userId, string $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.userId = :userId')
            ->andWhere('r.requestedAt >= :since')
            ->setParameter('userId', UserId::from($userId))
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRecentByIp(string $ip, string $since): int
    {
        $ipVo = IpAddress::tryFrom($ip);
        if (! $ipVo instanceof IpAddress) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.ip = :ip')
            ->andWhere('r.requestedAt >= :since')
            ->setParameter('ip', $ipVo)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function purgeOlderThan(string $before): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.requestedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
