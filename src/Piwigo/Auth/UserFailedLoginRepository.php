<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\UserId;

/**
 * @extends EntityRepository<UserFailedLoginEntity>
 */
final class UserFailedLoginRepository extends EntityRepository
{
    public function recordFailure(?int $userId, string $ip, string $now): void
    {
        $this->getEntityManager()
            ->persist(new UserFailedLoginEntity($userId !== null ? UserId::from($userId) : null, IpAddress::tryFrom($ip), $now));
        $this->getEntityManager()
            ->flush();
    }

    public function countRecentByUserId(int $userId, string $since): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.userId = :userId')
            ->andWhere('f.attemptedAt >= :since')
            ->setParameter('userId', UserId::from($userId))
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRecentByIp(string $ip, string $since): int
    {
        // Binding a raw string against f.ip (now ip_address_graceful-Typed)
        // would hit that Type's own convertToDatabaseValue(), which only
        // accepts null|IpAddress -- parse first, same "skip the query
        // rather than crash on unparseable input" convention as this
        // codebase's other Type-mapped-column WHERE bindings. The one
        // real caller (AuthService::pwgLogin()) already only calls this
        // when $ip !== '', so this is unreachable in practice, not just
        // theoretically safe.
        $ipVo = IpAddress::tryFrom($ip);
        if (! $ipVo instanceof IpAddress) {
            return 0;
        }

        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.ip = :ip')
            ->andWhere('f.attemptedAt >= :since')
            ->setParameter('ip', $ipVo)
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
