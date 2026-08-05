<?php

declare(strict_types=1);

namespace Piwigo\Session;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;
use Piwigo\Core\Env;

/**
 * Persistence layer for the DB-backed PHP session store.
 *
 * @extends EntityRepository<SessionEntity>
 */
final class SessionRepository extends EntityRepository
{
    public function read(string $compositeId): string
    {
        $entity = $this->find($compositeId);
        if ($entity === null) {
            return '';
        }

        return $entity->data;
    }

    public function write(string $compositeId, string $data): void
    {
        // Env::now() rather than a bare `new \DateTimeImmutable()`, which
        // reads the real system clock -- invisible to Env::now()'s
        // PIWIGO_TEST_NOW freeze, so every login during a fixture
        // regeneration wrote a fresh, non-reproducible expiration.
        $expiration = DateTimeImmutable::createFromInterface(Env::now());

        $em = $this->getEntityManager();
        $entity = $this->find($compositeId);
        if ($entity === null) {
            $entity = new SessionEntity($compositeId, $data, $expiration);
            $em->persist($entity);
        } else {
            $entity->data = $data;
            $entity->expiration = $expiration;
        }

        $em->flush();
    }

    public function destroy(string $compositeId): void
    {
        $entity = $this->find($compositeId);
        if ($entity === null) {
            return;
        }

        $em = $this->getEntityManager();
        $em->remove($entity);
        $em->flush();
    }

    /**
     * Deletes every session whose expiration is older than $sessionLength
     * seconds, returning the number of deleted rows (matches the current
     * procedural pwg_session_gc()'s real \Piwigo\Db\MysqliDb::changes()-backed return
     * value -- not discarded the way the reference implementation's
     * equivalent does). The cutoff is computed in PHP and bound as a plain
     * parameter -- cross-provider safe (no MySQL-only UNIX_TIMESTAMP()/
     * PostgreSQL-only interval syntax), unlike a raw
     * "NOW() - expiration > N seconds" comparison would require.
     *
     * Real bug, found via the mutation sweep (2026-08-01): the cutoff used
     * to be computed from the real wall clock, invisible to Env::now()'s
     * own PIWIGO_TEST_NOW freeze -- write() above already learned this
     * lesson (see its own comment) but gc() didn't apply it, so once real
     * time drifted away from a frozen PIWIGO_TEST_NOW, every session
     * written during that freeze looked far older than it really was and
     * got swept immediately, regardless of $sessionLength.
     */
    public function gc(int $sessionLength): int
    {
        $cutoff = DateTimeImmutable::createFromInterface(Env::now())
            ->modify('-' . $sessionLength . ' seconds');

        $em = $this->getEntityManager();
        $affected = $em->createQueryBuilder()
            ->delete(SessionEntity::class, 's')
            ->where('s.expiration < :cutoff')
            ->setParameter('cutoff', $cutoff, 'datetime_immutable')
            ->getQuery()
            ->execute();

        // A DQL DELETE bypasses the identity map -- any SessionEntity this
        // EntityManager already loaded this request (e.g. the request's
        // own active session, via read()/write() above) stays cached as
        // if it still existed, so a later find() for a row this just
        // deleted would return stale data. clear() (no longer scopable to
        // one entity class in ORM 3.x, see EntityManager::clear()'s own
        // signature) is safe here: gc() is a standalone, infrequent
        // background sweep, never interleaved with other entities this
        // EntityManager needs to keep attached mid-flush.
        $em->clear();

        return $affected;
    }

    /**
     * Delete all sessions for a given user.
     */
    public function deleteByUserId(int $userId): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(SessionEntity::class, 's')
            ->where('s.data LIKE :pattern')
            ->setParameter('pattern', '%pwg_uid|i:' . $userId . ';%')
            ->getQuery()
            ->execute();

        // Same identity-map staleness reasoning as gc() above.
        $em->clear();
    }
}
