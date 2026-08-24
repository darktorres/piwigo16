<?php

declare(strict_types=1);

namespace Piwigo\Site;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Override;
use Piwigo\Category\CategoryEntity;
use Piwigo\Category\SiteGalleriesUrlLookupInterface;
use Piwigo\Image\ImageEntity;
use Piwigo\Site\Projection\Site;
use Piwigo\Site\Projection\SiteCategoryImageCounts;

/**
 * Persistence layer for the site/gallery-root domain.
 *
 * @extends EntityRepository<SiteEntity>
 */
final class SiteRepository extends EntityRepository implements SiteGalleriesUrlLookupInterface
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
     * Deletes a site row -- trivial DQL, since it lives in the domain
     * that actually owns the table. {@see \Piwigo\Category\
     * CategoryService::deleteSite()} dispatches {@see \Piwigo\Category\
     * Event\DeleteSite} instead of calling this directly: the event-based
     * decoupling is the intended shape here, not a layer-constraint
     * workaround -- `Site` and `Category` are both `L2aCoreDomain`. The
     * listener is registered in {@see \Piwigo\Bootstrap\RequestBootstrap}.
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
     *
     * sites.id is a Postgres `smallint` column (a translation of MySQL's
     * own `tinyint unsigned`, per the migration's documented widening
     * rule). MySQL happily evaluates `id = 999999` as a plain false
     * comparison against a tinyint column with no protocol-level range
     * check, but PostgreSQL's extended query protocol enforces the bound
     * parameter's inferred smallint type strictly and rejects an
     * out-of-range value outright ("value ... is out of range for type
     * smallint") before the query can even run -- a real `site=999999`
     * admin request hits this as an uncaught DBAL DriverException
     * escaping past this controller's own graceful "site X does not
     * exist" handling. No real row can ever have an id outside
     * smallint's own -32768..32767 range, so short-circuiting to "not
     * found" here is correct, not just error-silencing.
     */
    public function findGalleriesUrlById(int $id): ?string
    {
        if ($id < -32768 || $id > 32767) {
            return null;
        }

        return $this->find($id)?->galleriesUrl;
    }

    /**
     * All sites' galleries_url, id => galleries_url. {@see \Piwigo\
     * Category\CategoryService::getFulldirs()} takes this via
     * {@see SiteGalleriesUrlLookupInterface} as an explicit parameter
     * rather than a constructor dependency -- see that interface's own
     * docblock.
     *
     * @return array<int|string, string> id => galleries_url
     */
    #[Override]
    public function findAllGalleriesUrls(): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('s.id', 's.galleriesUrl')
            ->from(SiteEntity::class, 's')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            $galleriesUrl = is_array($row) ? ($row['galleriesUrl'] ?? null) : null;
            if (is_array($row) && (is_int($row['id']) || is_string($row['id'])) && is_string($galleriesUrl)) {
                $byId[$row['id']] = $galleriesUrl;
            }
        }

        return $byId;
    }

    /**
     * $categoryId's own site's galleries_url, via the site_id FK join.
     * `Site` and `Category` are both `L2aCoreDomain`, so this join is a
     * legal same-layer, same-repository DQL query, not a boundary
     * crossing.
     */
    #[Override]
    public function findGalleriesUrlForCategory(int $categoryId): ?string
    {
        $galleriesUrl = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('s.galleriesUrl')
            ->from(SiteEntity::class, 's')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 's.id = c.site')
            ->where('c.id = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);

        return is_string($galleriesUrl) ? $galleriesUrl : null;
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
     * @return array<int, SiteCategoryImageCounts> keyed by site_id
     */
    public function findCategoryAndImageCountsBySite(): array
    {
        // `ImageEntity::$storageCategory` is the owning side of the
        // association, but this query's FROM is CategoryEntity, not
        // ImageEntity -- an explicit Join::WITH is still needed (a natural
        // association join would require the join to start from the
        // owning side), just with the bare association path on the image
        // side instead of the old scalar column. `CategoryEntity::$site`
        // is an association too now -- `IDENTITY(c.site)` extracts the raw
        // FK id without hydrating `SiteEntity` (a bare path in `SELECT`
        // would hydrate it instead); the bare path still works unchanged
        // in `WHERE`/`GROUP BY`, resolving to the same raw column.
        // ImageEntity's id stays a plain scalar.
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('IDENTITY(c.site) AS site_id', 'COUNT(DISTINCT c.id) AS nb_categories', 'COUNT(i.id) AS nb_images')
            ->from(CategoryEntity::class, 'c')
            ->leftJoin(ImageEntity::class, 'i', Join::WITH, 'c.id = i.storageCategory')
            ->where('c.site IS NOT NULL')
            ->groupBy('c.site')
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
                $bySiteId[(int) $siteId] = new SiteCategoryImageCounts(
                    categories: (int) $nbCategories,
                    images: (int) $nbImages,
                );
            }
        }

        return $bySiteId;
    }
}
