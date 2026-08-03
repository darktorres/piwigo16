<?php

declare(strict_types=1);

namespace Piwigo\Metadata;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\CategoryEntity;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageEntity;
use Piwigo\Metadata\Projection\MetadataImage;

/**
 * Persistence layer for the 2 genuinely data-touching functions formerly
 * in `admin/include/functions_metadata.php` (`sync_metadata()`,
 * `get_filelist()`, now `syncMetadata()`/`getFilelist()` on
 * {@see MetadataService}) -- the other 10 functions across that file and
 * `include/functions_metadata.inc.php` (both deleted in P23 sub-batch
 * 8b-1) were pure computation over raw EXIF/IPTC/SVG file data (parsing,
 * charset conversion, GPS math, keyword normalization) with no DB access
 * of their own; they live on {@see MetadataService} instead.
 *
 * Owns no table itself -- every query here reads `images`/`categories`
 * (each owned elsewhere, Image\ImageEntity/Category\CategoryEntity), so
 * holds EntityManagerInterface directly rather than being resolved via
 * getRepository(), same shape as Auth\AuthRepository.
 *
 * Further SQL-modernization audit, Item 16A: `findImagesByIds()`/
 * `findCategoryIds()`/`findImagesByStorageCategoryIds()` -- never audited
 * by Item 14/15 -- converted to real DQL. Every real query here was
 * already bounded (fixed WHERE shapes against mapped `ImageEntity`/
 * `CategoryEntity`, `REGEXP()`'s own portable DQL function for the
 * recursive-uppercats case), no architecture needed.
 */
final readonly class MetadataRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return list<MetadataImage>
     */
    public function findImagesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $images = $this->em->createQueryBuilder()
            ->select('i')
            ->from(ImageEntity::class, 'i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return array_map(static fn (ImageEntity $i): MetadataImage => MetadataImage::fromEntity($i), $images);
    }

    /**
     * @return list<int>
     */
    public function findCategoryIds(int $siteId, int|string $categoryId, bool $recursive): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.siteId = :siteId')
            ->andWhere('c.dir IS NOT NULL')
            ->setParameter('siteId', $siteId);

        if (is_numeric($categoryId)) {
            if ($recursive) {
                $qb->andWhere('REGEXP(c.uppercats, :categoryPattern) = true')
                    ->setParameter('categoryPattern', '(^|,)' . (int) $categoryId . '(,|$)');
            } else {
                $qb->andWhere('c.id = :categoryId')
                    ->setParameter('categoryId', (int) $categoryId);
            }
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Returns image id => row, matching the original's
     * `hash_from_query($query, 'id')` shape.
     *
     * @param  list<int>  $categoryIds
     * @return array<int, MetadataImage>
     */
    public function findImagesByStorageCategoryIds(array $categoryIds, bool $onlyNew): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('i')
            ->from(ImageEntity::class, 'i')
            ->where('i.storageCategoryId IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);

        if ($onlyNew) {
            $qb->andWhere('i.dateMetadataUpdate IS NULL');
        }

        $images = $qb->getQuery()
            ->getResult();

        $result = [];
        foreach ($images as $imageEntity) {
            $image = MetadataImage::fromEntity($imageEntity);
            $result[$image->id] = $image;
        }

        return $result;
    }

    /**
     * $datas' values are genuinely arbitrary by design -- EXIF/IPTC
     * metadata spans strings, dates, and floats (GPS coordinates), and
     * $updateFields (the actual column set) varies per call; same
     * rationale as BatchWriter's own already-documented column=>value bag.
     *
     * @param  list<string>  $updateFields
     * @param  list<array<string, mixed>>  $datas
     */
    public function massUpdateImages(array $updateFields, array $datas): void
    {
        new BatchWriter($this->em->getConnection())
            ->massUpdate(
                Tables::images(),
                [
                    'primary' => ['id'],
                    'update' => $updateFields,
                ],
                $datas,
                BatchWriter::SKIP_EMPTY
            );

        $this->em->clear();
    }
}
