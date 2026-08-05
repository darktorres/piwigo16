<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\EntityRepository;
use LogicException;

/**
 * Persistence layer for `derivative_size`. One row per named size,
 * unified across what ImageStdParams used to keep as two separate
 * piwigo_config keys (`derivatives`'s enabled map vs `disabled_derivatives`)
 * via the `enabled` column -- a size can only ever have one row, so
 * enabling a previously-disabled size updates that row in place rather
 * than creating a second one.
 *
 * syncEnabled()/syncDisabled() each own exactly one partition of the table
 * (`enabled = true` / `enabled = false` respectively) -- they upsert every
 * given row and delete any existing row in *their own* partition that
 * isn't in the given set, but never touch the other partition. This
 * matters because ImageStdParams::set_and_save()/set_and_save_disabled()
 * are two independently-callable methods (real caller:
 * ConfigurationSubController.php, two sequential but separate calls) --
 * a naive "delete everything not in this map" per call would transiently
 * wipe the other partition's rows between the two calls. Partitioning the
 * delete by `enabled` avoids that ordering dependency entirely.
 *
 * @extends EntityRepository<DerivativeSizeEntity>
 */
final class DerivativeSizeRepository extends EntityRepository
{
    /**
     * @return list<DerivativeSizeEntity>
     */
    public function findAllEnabled(): array
    {
        return $this->findBy([
            'enabled' => 1,
        ]);
    }

    /**
     * @return list<DerivativeSizeEntity>
     */
    public function findAllDisabled(): array
    {
        return $this->findBy([
            'enabled' => 0,
        ]);
    }

    /**
     * @param list<DerivativeSizeEntity> $sizes every one must have enabled=1
     */
    public function syncEnabled(array $sizes): void
    {
        $this->syncPartition($sizes, 1);
    }

    /**
     * @param list<DerivativeSizeEntity> $sizes every one must have enabled=0
     */
    public function syncDisabled(array $sizes): void
    {
        $this->syncPartition($sizes, 0);
    }

    /**
     * @param list<DerivativeSizeEntity> $sizes
     */
    private function syncPartition(array $sizes, int $enabled): void
    {
        $em = $this->getEntityManager();

        $wantedNames = [];
        foreach ($sizes as $size) {
            if ($size->enabled !== $enabled) {
                throw new LogicException('syncPartition(): every entity must already have enabled=' . $enabled);
            }

            $wantedNames[] = $size->name;

            $existing = $this->find($size->name);
            if ($existing === null) {
                $em->persist($size);
            } else {
                $existing->enabled = $size->enabled;
                $existing->maxWidth = $size->maxWidth;
                $existing->maxHeight = $size->maxHeight;
                $existing->maxCrop = $size->maxCrop;
                $existing->minWidth = $size->minWidth;
                $existing->minHeight = $size->minHeight;
                $existing->sharpen = $size->sharpen;
                $existing->lastModTime = $size->lastModTime;
            }
        }

        foreach ($this->findBy([
            'enabled' => $enabled,
        ]) as $existing) {
            if (! in_array($existing->name, $wantedNames, true)) {
                $em->remove($existing);
            }
        }

        $em->flush();
    }
}
