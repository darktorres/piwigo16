<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Image\Projection\ImageFormatPair;

/**
 * Persistence for the `image_format` table — alternate-extension files
 * (e.g. .webp/.avif derived from a source .jpg) tracked alongside their
 * parent image. Extracted from `ImageRepository` in F5-d/11: image_format
 * is a distinct aggregate that never JOINs with images at write time and
 * has its own lifecycle.
 */
final class ImageFormatRepository extends AbstractRepository
{
    /**
     * Return (format_id, image_id, ext, filesize) rows for specific format ids.
     *
     * @param  int[] $formatIds
     * @return list<ImageFormatPair>
     */
    public function findByFormatIds(array $formatIds): array
    {
        if ($formatIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('format_id', 'image_id', 'ext', 'filesize')
            ->from($this->table('image_format'));
        $qb->where($qb->expr()->in('format_id', ':formatIds'))
           ->setParameter('formatIds', $formatIds, ArrayParameterType::INTEGER);
        return array_map(ImageFormatPair::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Delete format rows by their format_id values.
     *
     * @param int[] $formatIds
     */
    public function deleteByFormatIds(array $formatIds): void
    {
        if ($formatIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_format'));
        $qb->where($qb->expr()->in('format_id', ':formatIds'))
           ->setParameter('formatIds', $formatIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return all (format_id, image_id, ext, filesize) rows from image_format.
     * Used to build a map of alternate format extensions per image.
     *
     * @return list<ImageFormatPair>
     */
    public function findAll(): array
    {
        return array_map(
            ImageFormatPair::fromRow(...),
            $this->conn->createQueryBuilder()
                ->select('format_id', 'image_id', 'ext', 'filesize')
                ->from($this->table('image_format'))
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    /**
     * Return (format_id, image_id, ext, filesize) rows for formats attached to the given images.
     *
     * @param  int[] $imageIds
     * @return list<ImageFormatPair>
     */
    public function findByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('format_id', 'image_id', 'ext', 'filesize')
            ->from($this->table('image_format'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        return array_map(ImageFormatPair::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
    }

    /** Total number of alternate formats. */
    public function countAll(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('image_format'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Total filesize (KB) of all alternate format files. */
    public function sumFilesizeKb(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('SUM(filesize)')
            ->from($this->table('image_format'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return an image_format row by format_id, or null when not found.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $formatId): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('image_format'))
            ->where('format_id = :id')
            ->setParameter('id', $formatId)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Per-extension totals across the image_format table — image-format
     * rows store ext explicitly rather than via SUBSTRING_INDEX.
     *
     * @return array<string, ExtensionStat>
     */
    public function findExtensionTotals(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT COUNT(*) AS ext_counter, ext, SUM(filesize) AS filesize'
            . ' FROM ' . $this->table('image_format') . ' GROUP BY ext',
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $ext = is_string($row['ext'] ?? null) ? $row['ext'] : '';
            $out[$ext] = new ExtensionStat(
                is_numeric($row['ext_counter'] ?? null) ? (int) $row['ext_counter'] : 0,
                is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : 0,
            );
        }
        return $out;
    }

    /**
     * Insert a batch of image_format rows atomically.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertRowsBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->insert($this->table('image_format'), $row);
            }
        });
    }

    /**
     * Find the existing image_format row for the given image/ext pair, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByImageAndExt(int $imageId, string $ext): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('image_format'))
            ->where('image_id = :imageId')
            ->andWhere('ext = :ext')
            ->setParameter('imageId', $imageId)
            ->setParameter('ext', $ext)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Update an image_format row.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $formatId, int $imageId, string $ext, array $fields): void
    {
        $this->conn->update(
            $this->table('image_format'),
            $fields,
            ['format_id' => $formatId, 'image_id' => $imageId, 'ext' => $ext],
        );
    }

    /**
     * Insert an image_format row and return the new id.
     *
     * @param array<string, mixed> $fields
     */
    public function insert(array $fields): int
    {
        $this->conn->insert($this->table('image_format'), $fields);
        return (int) $this->conn->lastInsertId();
    }
}
