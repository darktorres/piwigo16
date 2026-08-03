<?php

declare(strict_types=1);

namespace Piwigo\Site;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Category\CategoryEntity;
use Piwigo\Image\ImageEntity;
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
     * Item 16E: real DQL replacement for {@see \Piwigo\Category\
     * CategoryRepository::deleteSiteRow()} (removed the same commit) --
     * that method existed only because `Category` (`L2aCoreDomain`)
     * can't depend on `Site` (`L2bExtendedDomain`) directly; the delete
     * itself is trivial once it lives in the domain that actually owns
     * the table. {@see \Piwigo\Category\CategoryService::deleteSite()}
     * dispatches {@see \Piwigo\Event\Site\DeleteSite} instead of calling
     * this directly -- the listener is registered in
     * {@see \Piwigo\Bootstrap\RequestBootstrap}.
     */
    public function delete(int $id): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(SiteEntity::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();

        // A DQL bulk DELETE bypasses the UnitOfWork's own remove()
        // tracking -- any SiteEntity this EntityManager already had
        // cached for $id (e.g. insert()'s own persist() earlier in the
        // same request) would otherwise read back stale.
        $em->clear();
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
        // Item 14 DQL audit: converted. Neither `categories` nor `images` has
        // an ORM association between them (storage_category_id is a plain
        // int column, not a mapped ManyToOne), so the join is expressed as
        // an arbitrary cross-entity DQL JOIN ... WITH, same pattern already
        // used by GroupRepository::getAccessibleCategoryIdsForUser(). Neither
        // CategoryEntity::$siteId nor ImageEntity's id/storageCategoryId use
        // a custom Doctrine Type, so array-hydrated values are plain scalars,
        // same as the previous raw-DBAL row shape.
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.siteId AS site_id', 'COUNT(DISTINCT c.id) AS nb_categories', 'COUNT(i.id) AS nb_images')
            ->from(CategoryEntity::class, 'c')
            ->leftJoin(ImageEntity::class, 'i', Join::WITH, 'c.id = i.storageCategoryId')
            ->where('c.siteId IS NOT NULL')
            ->groupBy('c.siteId')
            ->getQuery()
            ->getArrayResult();

        $bySiteId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

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
