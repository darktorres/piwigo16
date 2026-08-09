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
     * Real DQL replacement for {@see \Piwigo\Category\
     * CategoryRepository::deleteSiteRow()} (removed) -- that method
     * existed only because `Category` (`L2aCoreDomain`)
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
     *
     * pgsql support pass: real bug found live -- sites.id is a Postgres
     * `smallint` column (Phase B's translation of MySQL's own `tinyint
     * unsigned`, per the migration's documented widening rule). MySQL
     * happily evaluates `id = 999999` as a plain false comparison against
     * a tinyint column with no protocol-level range check, but
     * PostgreSQL's extended query protocol enforces the bound
     * parameter's inferred smallint type strictly and rejects an
     * out-of-range value outright ("value ... is out of range for type
     * smallint") before the query can even run -- confirmed live via a
     * real `site=999999` admin request, an uncaught DBAL DriverException
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
     * Real DQL replacement for the raw DBAL read
     * {@see \Piwigo\Category\CategoryRepository::findSiteGalleriesUrls()}
     * used to do directly -- `Category` (`L2aCoreDomain`) can't depend on
     * `Site` (`L2bExtendedDomain`), so {@see \Piwigo\Category\CategoryService::getFulldirs()}
     * now takes {@see SiteGalleriesUrlLookupInterface} as an explicit
     * parameter instead.
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
            if (is_array($row) && (is_int($row['id']) || is_string($row['id'])) && is_string($row['galleriesUrl'] ?? null)) {
                $byId[$row['id']] = $row['galleriesUrl'];
            }
        }

        return $byId;
    }

    /**
     * Real DQL replacement for the raw DBAL read
     * {@see \Piwigo\Category\CategoryRepository::findGalleriesUrlForCategory()}
     * used to do directly -- same reasoning as {@see findAllGalleriesUrls()}
     * above. `Site` (`L2bExtendedDomain`) CAN depend downward on
     * `Category` (`L2aCoreDomain`), same direction
     * {@see findCategoryAndImageCountsBySite()} below already uses, so
     * this join is a legal same-repository DQL query, not a boundary
     * crossing.
     */
    #[Override]
    public function findGalleriesUrlForCategory(int|string $categoryId): ?string
    {
        $galleriesUrl = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('s.galleriesUrl')
            ->from(SiteEntity::class, 's')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 's.id = c.siteId')
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
     * @return array<int, array{nb_categories: int, nb_images: int}> keyed by site_id
     */
    public function findCategoryAndImageCountsBySite(): array
    {
        // Neither `categories` nor `images` has
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
