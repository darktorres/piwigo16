<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Db\AbstractRepository;

use Doctrine\DBAL\ArrayParameterType;

/** Persistence layer for the image domain. */
final class ImageRepository extends AbstractRepository
{
    /**
     * Return image rows for the given ids.
     *
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }
}
