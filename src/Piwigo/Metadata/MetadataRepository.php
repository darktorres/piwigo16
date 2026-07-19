<?php

declare(strict_types=1);

namespace Piwigo\Metadata;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the 2 genuinely data-touching functions formerly
 * in `admin/include/functions_metadata.php` (`sync_metadata()`,
 * `get_filelist()`, now `syncMetadata()`/`getFilelist()` on
 * {@see MetadataService}) -- the other 10 functions across that file and
 * `include/functions_metadata.inc.php` (both deleted in P23 sub-batch
 * 8b-1) were pure computation over raw EXIF/IPTC/SVG file data (parsing,
 * charset conversion, GPS math, keyword normalization) with no DB access
 * of their own; they live on {@see MetadataService} instead.
 */
final class MetadataRepository extends AbstractRepository
{
    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, path: string, representative_ext: string|null}>
     */
    public function findImagesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'path', 'representative_ext')
            ->from(Tables::images())
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'path' => is_string($row['path']) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext']) ? $row['representative_ext'] : null,
            ],
            $rows
        );
    }

    /**
     * @return list<int>
     */
    public function findCategoryIds(int $siteId, int|string $categoryId, bool $recursive): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::categories())
            ->where('site_id = :siteId')
            ->andWhere('dir IS NOT NULL')
            ->setParameter('siteId', $siteId);

        if (is_numeric($categoryId)) {
            if ($recursive) {
                $qb->andWhere('uppercats REGEXP :categoryPattern')
                    ->setParameter('categoryPattern', '(^|,)' . (int) $categoryId . '(,|$)');
            } else {
                $qb->andWhere('id = :categoryId')
                    ->setParameter('categoryId', (int) $categoryId);
            }
        }

        $ids = $qb->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * Returns image id => row, matching the original's
     * `hash_from_query($query, 'id')` shape.
     *
     * @param  list<int>  $categoryIds
     * @return array<int, array{id: int, path: string, representative_ext: string|null}>
     */
    public function findImagesByStorageCategoryIds(array $categoryIds, bool $onlyNew): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'path', 'representative_ext')
            ->from(Tables::images())
            ->where('storage_category_id IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);

        if ($onlyNew) {
            $qb->andWhere('date_metadata_update IS NULL');
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $id = is_numeric($row['id']) ? (int) $row['id'] : null;
            if ($id === null) {
                continue;
            }

            $result[$id] = [
                'id' => $id,
                'path' => is_string($row['path']) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext']) ? $row['representative_ext'] : null,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $updateFields
     * @param  list<array<string, mixed>>  $datas
     */
    public function massUpdateImages(array $updateFields, array $datas): void
    {
        $this->batchWriter()
            ->massUpdate(
                Tables::images(),
                [
                    'primary' => ['id'],
                    'update' => $updateFields,
                ],
                $datas,
                \Piwigo\Db\BatchWriter::SKIP_EMPTY
            );
    }
}
