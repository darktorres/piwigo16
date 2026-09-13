<?php

declare(strict_types=1);

namespace Piwigo\Metadata;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\CategoryEntity;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Image\ImageEntity;
use Piwigo\Metadata\Projection\MetadataImage;

/**
 * Persistence layer backing `syncMetadata()`/`getFilelist()` on
 * {@see MetadataService} -- pure computation over raw EXIF/IPTC/SVG file
 * data (parsing, charset conversion, GPS math, keyword normalization) with
 * no DB access of its own lives on {@see MetadataService} instead.
 *
 * Owns no table itself -- every query here reads `images`/`categories`
 * (each owned elsewhere, Image\ImageEntity/Category\CategoryEntity), so
 * holds EntityManagerInterface directly rather than being resolved via
 * getRepository(), same shape as Auth\AuthRepository.
 *
 * Every real query here is bounded (fixed WHERE shapes against mapped
 * `ImageEntity`/`CategoryEntity`, `REGEXP()`'s own portable DQL function
 * for the recursive-uppercats case).
 */
final readonly class MetadataRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Partial select (id/path/representativeExt only) + array hydration,
     * not `select('i')`/`getResult()` -- this used to hydrate a full,
     * change-tracked `ImageEntity` per row purely to build a 3-field
     * `MetadataImage` and discard the entity immediately afterward.
     * Profiling a real sync at 10,000 images found this responsible for a
     * real chunk of `Doctrine\ORM\UnitOfWork::createEntity`/
     * `AbstractHydrator::gatherRowData`/`computeChangeSet` overhead for
     * objects nothing ever mutates or persists. Same technique
     * `ImageRepository::findIdsAndPathsByStorageCategoryIds()` already
     * established -- `id` still arrives as a real `ImageId` value object
     * even under `getArrayResult()` (DBAL's custom Type conversion applies
     * regardless of hydration mode), narrowed the same way via
     * {@see MetadataImage::fromRow()}.
     *
     * @param  list<int>  $ids
     * @return list<MetadataImage>
     */
    public function findImagesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('i.id', 'i.path', 'i.representativeExt')
            ->from(ImageEntity::class, 'i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $image = MetadataImage::fromRow($row);
            if ($image instanceof MetadataImage) {
                $result[] = $image;
            }
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    public function findCategoryIds(int $siteId, int|string $categoryId, bool $recursive): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.site = :siteId')
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
     * Partial select + array hydration, not full-entity `getResult()` --
     * see {@see findImagesByIds()}'s own docblock for why.
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
            ->select('i.id', 'i.path', 'i.representativeExt')
            ->from(ImageEntity::class, 'i')
            ->where('i.storageCategory IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);

        if ($onlyNew) {
            $qb->andWhere('i.dateMetadataUpdate IS NULL');
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $image = MetadataImage::fromRow($row);
            if ($image instanceof MetadataImage) {
                $result[$image->id] = $image;
            }
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
        $now = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($this->em->getConnection())
            ->massUpdate(
                'images',
                [
                    'primary' => ['id'],
                    'update' => [...$updateFields, 'lastmodified'],
                ],
                array_map(static fn (array $data): array => [
                    ...$data,
                    'lastmodified' => $now,
                ], $datas),
                BatchWriter::SKIP_EMPTY
            );

        $this->em->clear();
    }
}
