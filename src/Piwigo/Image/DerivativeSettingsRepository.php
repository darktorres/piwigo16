<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\EntityRepository;

/**
 * Persistence layer for the single `derivative_settings` row. Every write
 * is an upsert keyed on {@see DerivativeSettingsEntity::SINGLETON_ID} --
 * this is a "set membership" table (the settings either exist or they
 * don't), not an append-only log, so a blind persist() would throw on the
 * second save of a request lifetime that already has a row.
 *
 * @extends EntityRepository<DerivativeSettingsEntity>
 */
final class DerivativeSettingsRepository extends EntityRepository
{
    public function load(): ?DerivativeSettingsEntity
    {
        return $this->find(DerivativeSettingsEntity::SINGLETON_ID);
    }

    /**
     * @param array<string, mixed> $watermarkJson
     * @param array<string, int> $customJson
     */
    public function save(int $defaultQuality, array $watermarkJson, array $customJson): void
    {
        $em = $this->getEntityManager();
        $entity = $this->load();

        if (! $entity instanceof DerivativeSettingsEntity) {
            $entity = new DerivativeSettingsEntity(
                DerivativeSettingsEntity::SINGLETON_ID,
                $defaultQuality,
                $watermarkJson,
                $customJson,
            );
            $em->persist($entity);
        } else {
            $entity->defaultQuality = $defaultQuality;
            $entity->watermarkJson = $watermarkJson;
            $entity->customJson = $customJson;
        }

        $em->flush();
    }
}
