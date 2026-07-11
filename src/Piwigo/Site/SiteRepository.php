<?php

declare(strict_types=1);

namespace Piwigo\Site;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the site/gallery-root domain.
 */
final class SiteRepository extends AbstractRepository
{
    /**
     * Count sites with the given galleries_url.
     */
    public function countByUrl(string $galleriesUrl): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(id)')
            ->from(Tables::sites())
            ->where('galleries_url = :url')
            ->setParameter('url', $galleriesUrl)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function insert(string $galleriesUrl): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::sites())
            ->values([
                'galleries_url' => ':url',
            ])
            ->setParameter('url', $galleriesUrl)
            ->executeStatement();
    }

    /**
     * Returns a site's galleries_url, or null if the id doesn't exist.
     */
    public function findGalleriesUrlById(int $id): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('galleries_url')
            ->from(Tables::sites())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * Returns every site row.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::sites())
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
