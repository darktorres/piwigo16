<?php

declare(strict_types=1);

namespace Piwigo\Site;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for the site/gallery-root domain. */
final class SiteRepository extends AbstractRepository
{
    /** Count sites with the given galleries_url. */
    public function countByUrl(string $galleriesUrl): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(id)')
            ->from($this->table('sites'))
            ->where('galleries_url = :url')
            ->setParameter('url', $galleriesUrl)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Insert a new site with the given galleries_url and return its id. */
    public function insert(string $galleriesUrl): int
    {
        $this->conn->insert($this->table('sites'), ['galleries_url' => $galleriesUrl]);
        return (int) $this->conn->lastInsertId();
    }

    /** Return the galleries_url for the given site id, or null if not found. */
    public function findGalleriesUrlById(int $id): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('galleries_url')
            ->from($this->table('sites'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return all site rows.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('sites'))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return id → galleries_url for every site.
     *
     * @return array<int, string>
     */
    public function findIdToGalleriesUrlMap(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'galleries_url')
            ->from($this->table('sites'))
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $out[$idInt] = is_string($row['galleries_url']) ? $row['galleries_url'] : '';
        }
        return $out;
    }
}
