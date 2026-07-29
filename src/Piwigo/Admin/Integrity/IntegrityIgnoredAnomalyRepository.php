<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity;

use Doctrine\ORM\EntityRepository;

/**
 * Persistence layer for `integrity_ignored_anomalies`. Scoped per
 * Piwigo version (composite PK anomaly_id+piwigo_version) -- a "set
 * membership" table like every other ignore-list this migration touches,
 * so syncForVersion() is find-or-create/delete-if-unwanted, never a blind
 * persist() (re-ignoring an already-ignored anomaly across repeated
 * CheckIntegrity::check() calls in the same session must not throw).
 *
 * @extends EntityRepository<IntegrityIgnoredAnomalyEntity>
 */
final class IntegrityIgnoredAnomalyRepository extends EntityRepository
{
    /**
     * @return list<string>
     */
    public function findIgnoredAnomalyIdsForVersion(string $piwigoVersion): array
    {
        $entities = $this->findBy([
            'piwigoVersion' => $piwigoVersion,
        ]);

        return array_map(static fn (IntegrityIgnoredAnomalyEntity $e): string => $e->anomalyId, $entities);
    }

    /**
     * Replaces the ignored set for $piwigoVersion with exactly
     * $anomalyIds -- inserts newly-ignored ids (ignored_at=$now), deletes
     * ids no longer in the set, and leaves already-ignored ids' own
     * ignored_at untouched (it records when each anomaly was first
     * ignored, not last re-synced).
     *
     * @param list<string> $anomalyIds
     */
    public function syncForVersion(string $piwigoVersion, array $anomalyIds, string $now): void
    {
        $em = $this->getEntityManager();

        $existing = $this->findBy([
            'piwigoVersion' => $piwigoVersion,
        ]);
        $existingIds = array_map(static fn (IntegrityIgnoredAnomalyEntity $e): string => $e->anomalyId, $existing);

        foreach ($anomalyIds as $anomalyId) {
            if (! in_array($anomalyId, $existingIds, true)) {
                $em->persist(new IntegrityIgnoredAnomalyEntity($anomalyId, $piwigoVersion, $now));
            }
        }

        foreach ($existing as $entity) {
            if (! in_array($entity->anomalyId, $anomalyIds, true)) {
                $em->remove($entity);
            }
        }

        $em->flush();
    }

    /**
     * Deletes ignore rows for any Piwigo version, regardless of whether
     * it's the currently-running one -- old-version rows have no other
     * natural cleanup (unlike the former c13y_ignore blob, which never
     * accumulated across versions since it only ever held one).
     */
    public function purgeOlderThan(string $before): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.ignoredAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
