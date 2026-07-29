<?php

declare(strict_types=1);

namespace Piwigo\Site;

use Doctrine\ORM\EntityRepository;
use Piwigo\Db\Tables;
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

    /**
     * Category/image counts per site (`site_manager.php`'s own summary
     * columns) -- crosses into Category/Image tables, but the business
     * question ("how big is each site") is Site-domain reporting, same
     * "attribute to the question, not every table touched" precedent as
     * {@see \Piwigo\Permission\PermissionRepository}.
     *
     * @return array<int, array{nb_categories: int, nb_images: int}> keyed by site_id
     */
    public function findCategoryAndImageCountsBySite(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('c.site_id', 'COUNT(DISTINCT c.id) AS nb_categories', 'COUNT(i.id) AS nb_images')
            ->from(Tables::categories(), 'c')
            ->leftJoin('c', Tables::images(), 'i', 'c.id = i.storage_category_id')
            ->where('c.site_id IS NOT NULL')
            ->groupBy('c.site_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $bySiteId = [];
        foreach ($rows as $row) {
            $siteId = $row['site_id'] ?? null;
            $nbCategories = $row['nb_categories'] ?? null;
            $nbImages = $row['nb_images'] ?? null;
            if (is_numeric($siteId) && is_numeric($nbCategories) && is_numeric($nbImages)) {
                $bySiteId[(int) $siteId] = [
                    'nb_categories' => (int) $nbCategories,
                    'nb_images' => (int) $nbImages,
                ];
            }
        }

        return $bySiteId;
    }
}
