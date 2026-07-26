<?php

declare(strict_types=1);

namespace Piwigo\Site;

use Doctrine\ORM\EntityRepository;
use Piwigo\Site\Projection\Site;

/**
 * Persistence layer for the site/gallery-root domain.
 *
 * @extends EntityRepository<SiteEntity>
 */
final class SiteRepository extends EntityRepository
{
    /**
     * Count sites with the given galleries_url.
     */
    public function countByUrl(string $galleriesUrl): int
    {
        $value = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.galleriesUrl = :url')
            ->setParameter('url', $galleriesUrl)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function insert(string $galleriesUrl): void
    {
        $em = $this->getEntityManager();
        $em->persist(new SiteEntity($galleriesUrl));
        $em->flush();
    }

    /**
     * Returns a site's galleries_url, or null if the id doesn't exist.
     */
    public function findGalleriesUrlById(int $id): ?string
    {
        return $this->find($id)?->galleriesUrl;
    }

    /**
     * Returns every site row.
     *
     * @return list<Site>
     */
    public function findAllSites(): array
    {
        return array_map(
            static fn (SiteEntity $entity): Site => new Site($entity->id ?? 0, $entity->galleriesUrl),
            $this->findAll(),
        );
    }
}
