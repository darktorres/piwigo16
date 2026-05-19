<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image\Entity;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\RelPath;
use Piwigo\Image\Entity\ImageIdFilename;

final class ImageIdFilenameTest extends TestCase
{
    public function testFromRowHappyPath(): void
    {
        $p = ImageIdFilename::fromRow(['id' => 42, 'file' => 'galleries/foo.jpg']);
        self::assertEquals(ImageId::from(42), $p->id);
        self::assertEquals(RelPath::from('galleries/foo.jpg'), $p->file);
    }

    public function testFromRowAcceptsNumericStringId(): void
    {
        $p = ImageIdFilename::fromRow(['id' => '42', 'file' => 'a.jpg']);
        self::assertSame(42, $p->id->value);
    }

    public function testFromRowRejectsMissingId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ImageIdFilename::fromRow(['file' => 'a.jpg']);
    }

    public function testFromRowRejectsNonNumericId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ImageIdFilename::fromRow(['id' => 'abc', 'file' => 'a.jpg']);
    }

    public function testFromRowRejectsMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ImageIdFilename::fromRow(['id' => 1]);
    }

    public function testFromRowRejectsEmptyFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ImageIdFilename::fromRow(['id' => 1, 'file' => '']);
    }
}
