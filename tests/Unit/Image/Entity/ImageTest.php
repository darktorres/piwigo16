<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image\Entity;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\Md5Sum;
use Piwigo\Common\ValueObject\MysqlDate;
use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\RelPath;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Image\Entity\Image;

final class ImageTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function minimalRow(int $id = 1): array
    {
        // Only the strictly-required columns. Every nullable column omitted to
        // exercise the null-defaulting code paths.
        return [
            'id'           => $id,
            'file'         => 'galleries/foo.jpg',
            'hit'          => 0,
            'path'         => 'galleries/foo.jpg',
            'level'        => 0,
            'lastmodified' => '2026-05-18 12:34:56',
        ];
    }

    public function testFromRowMinimal(): void
    {
        $img = Image::fromRow(self::minimalRow(7));

        self::assertEquals(ImageId::from(7), $img->id);
        self::assertEquals(RelPath::from('galleries/foo.jpg'), $img->file);
        self::assertEquals(RelPath::from('galleries/foo.jpg'), $img->path);
        self::assertSame(0, $img->hit);
        self::assertSame(0, $img->level);
        self::assertEquals(MysqlDateTime::from('2026-05-18 12:34:56'), $img->lastModified);

        // Every nullable slot defaulted to null.
        self::assertNull($img->dateAvailable);
        self::assertNull($img->dateCreation);
        self::assertNull($img->name);
        self::assertNull($img->comment);
        self::assertNull($img->author);
        self::assertNull($img->filesize);
        self::assertNull($img->width);
        self::assertNull($img->height);
        self::assertNull($img->coi);
        self::assertNull($img->representativeExt);
        self::assertNull($img->dateMetadataUpdate);
        self::assertNull($img->ratingScore);
        self::assertNull($img->storageCategoryId);
        self::assertNull($img->md5sum);
        self::assertNull($img->addedBy);
        self::assertNull($img->rotation);
        self::assertNull($img->latitude);
        self::assertNull($img->longitude);
    }

    public function testFromRowFullyPopulated(): void
    {
        $row = array_merge(self::minimalRow(42), [
            'date_available'        => '2026-05-18 09:00:00',
            'date_creation'         => '2026-05-17 18:30:00',
            'name'                  => 'sunset',
            'comment'               => 'over the ocean',
            'author'                => 'jane',
            'hit'                   => 123,
            'filesize'              => 2_048_000,
            'width'                 => 4000,
            'height'                => 3000,
            'coi'                   => '5050',
            'representative_ext'    => 'jpg',
            'date_metadata_update'  => '2026-05-18',
            'rating_score'          => 4.25,
            'storage_category_id'   => 9,
            'level'                 => 4,
            'md5sum'                => str_repeat('a', 32),
            'added_by'              => 11,
            'rotation'              => 6,
            'latitude'              => 48.858333,
            'longitude'             => 2.294444,
        ]);

        $img = Image::fromRow($row);

        self::assertEquals(ImageId::from(42), $img->id);
        self::assertEquals(MysqlDateTime::from('2026-05-18 09:00:00'), $img->dateAvailable);
        self::assertEquals(MysqlDateTime::from('2026-05-17 18:30:00'), $img->dateCreation);
        self::assertSame('sunset', $img->name);
        self::assertSame('over the ocean', $img->comment);
        self::assertSame('jane', $img->author);
        self::assertSame(123, $img->hit);
        self::assertSame(2_048_000, $img->filesize);
        self::assertSame(4000, $img->width);
        self::assertSame(3000, $img->height);
        self::assertSame('5050', $img->coi);
        self::assertSame('jpg', $img->representativeExt);
        self::assertEquals(MysqlDate::from('2026-05-18'), $img->dateMetadataUpdate);
        self::assertSame(4.25, $img->ratingScore);
        self::assertEquals(CategoryId::from(9), $img->storageCategoryId);
        self::assertSame(4, $img->level);
        self::assertEquals(Md5Sum::from(str_repeat('a', 32)), $img->md5sum);
        self::assertEquals(UserId::from(11), $img->addedBy);
        self::assertSame(6, $img->rotation);
        self::assertSame(48.858333, $img->latitude);
        self::assertSame(2.294444, $img->longitude);
    }

    public function testToRowRoundTripsFromFullyPopulated(): void
    {
        $row = array_merge(self::minimalRow(42), [
            'date_available'        => '2026-05-18 09:00:00',
            'date_creation'         => '2026-05-17 18:30:00',
            'name'                  => 'sunset',
            'comment'               => 'over the ocean',
            'author'                => 'jane',
            'hit'                   => 123,
            'filesize'              => 2_048_000,
            'width'                 => 4000,
            'height'                => 3000,
            'coi'                   => '5050',
            'representative_ext'    => 'jpg',
            'date_metadata_update'  => '2026-05-18',
            'rating_score'          => 4.25,
            'storage_category_id'   => 9,
            'level'                 => 4,
            'md5sum'                => str_repeat('a', 32),
            'added_by'              => 11,
            'rotation'              => 6,
            'latitude'              => 48.858333,
            'longitude'             => 2.294444,
        ]);

        $round = Image::fromRow($row)->toRow();

        // Every key in the original row appears in the round-trip with the
        // same canonical value. (Numeric strings normalize to int/float.)
        foreach ($row as $k => $v) {
            self::assertSame($v, $round[$k], "key {$k} preserved");
        }
    }

    public function testToRowEmitsNullsForMissingOptionalFields(): void
    {
        $row = Image::fromRow(self::minimalRow(7))->toRow();

        self::assertSame(7, $row['id']);
        self::assertSame('galleries/foo.jpg', $row['file']);
        self::assertSame('galleries/foo.jpg', $row['path']);
        self::assertSame(0, $row['hit']);
        self::assertSame(0, $row['level']);
        self::assertSame('2026-05-18 12:34:56', $row['lastmodified']);
        self::assertNull($row['name']);
        self::assertNull($row['author']);
        self::assertNull($row['comment']);
        self::assertNull($row['date_available']);
        self::assertNull($row['date_creation']);
        self::assertNull($row['md5sum']);
        self::assertNull($row['added_by']);
        self::assertNull($row['storage_category_id']);
    }

    public function testFromRowRejectsMissingId(): void
    {
        $row = self::minimalRow();
        unset($row['id']);
        $this->expectException(\InvalidArgumentException::class);
        Image::fromRow($row);
    }

    public function testFromRowRejectsMissingLastmodified(): void
    {
        $row = self::minimalRow();
        unset($row['lastmodified']);
        $this->expectException(\InvalidArgumentException::class);
        Image::fromRow($row);
    }

    public function testFromRowRejectsMalformedId(): void
    {
        $row       = self::minimalRow();
        $row['id'] = 'not-a-number';
        $this->expectException(\InvalidArgumentException::class);
        Image::fromRow($row);
    }

    public function testNumericStringsCoerce(): void
    {
        // DBAL emits some integer columns as numeric strings; fromRow must
        // accept them transparently.
        $row = self::minimalRow();
        $row['hit']       = '99';
        $row['filesize']  = '500000';
        $row['width']     = '1024';
        $row['height']    = '768';
        $row['rotation']  = '3';

        $img = Image::fromRow($row);
        self::assertSame(99, $img->hit);
        self::assertSame(500000, $img->filesize);
        self::assertSame(1024, $img->width);
        self::assertSame(768, $img->height);
        self::assertSame(3, $img->rotation);
    }

    public function testAspectRatioLandscape(): void
    {
        $row = self::minimalRow() + ['width' => 4000, 'height' => 3000];
        $img = Image::fromRow($row);
        self::assertEqualsWithDelta(4 / 3, $img->aspectRatio(), 1e-9);
        self::assertTrue($img->isLandscape());
        self::assertFalse($img->isPortrait());
    }

    public function testAspectRatioPortrait(): void
    {
        $row = self::minimalRow() + ['width' => 3000, 'height' => 4000];
        $img = Image::fromRow($row);
        self::assertEqualsWithDelta(3 / 4, $img->aspectRatio(), 1e-9);
        self::assertTrue($img->isPortrait());
        self::assertFalse($img->isLandscape());
    }

    public function testAspectRatioSquare(): void
    {
        $row = self::minimalRow() + ['width' => 1000, 'height' => 1000];
        $img = Image::fromRow($row);
        self::assertSame(1.0, $img->aspectRatio());
        self::assertFalse($img->isPortrait());
        self::assertFalse($img->isLandscape());
    }

    public function testAspectRatioNullWhenDimensionsMissing(): void
    {
        $img = Image::fromRow(self::minimalRow());
        self::assertNull($img->aspectRatio());
        self::assertFalse($img->isPortrait());
        self::assertFalse($img->isLandscape());
    }

    public function testAspectRatioNullWhenHeightZero(): void
    {
        $row = self::minimalRow() + ['width' => 1000, 'height' => 0];
        $img = Image::fromRow($row);
        self::assertNull($img->aspectRatio());
    }
}
