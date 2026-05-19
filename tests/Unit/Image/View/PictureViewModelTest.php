<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image\View;

use PHPUnit\Framework\TestCase;
use Piwigo\Image\Entity\Image;
use Piwigo\Image\View\PictureViewModel;

final class PictureViewModelTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(): array
    {
        return [
            'id'             => 42,
            'file'           => 'galleries/foo.png',
            'path'           => 'galleries/foo.png',
            'hit'            => 7,
            'level'          => 0,
            'name'           => 'foo title',
            'comment'        => 'foo desc',
            'lastmodified'   => '2026-05-18 12:34:56',
            'date_creation'  => '2026-04-01 09:00:00',
            'date_available' => '2026-04-02 09:00:00',
        ];
    }

    public function testFromImagePopulatesSrcAndDerivedFields(): void
    {
        $img = Image::fromRow(self::row());
        $vm  = PictureViewModel::fromImage($img);

        self::assertSame($img, $vm->image);
        self::assertSame(42, $vm->srcImage->id);
        self::assertSame('png', $vm->pathExt);
        self::assertSame('png', $vm->fileExt);
        // `derivatives` is populated from ImageStdParams::getDefinedTypeMap();
        // that registry is empty in unit-test context (no config bootstrap),
        // so the field exists but is an empty array. The integration is
        // exercised through the picture-page smoke tests.
        self::assertSame([], $vm->derivatives);
        self::assertSame('', $vm->url);
        self::assertSame('', $vm->title);
        self::assertNull($vm->elementPath);
    }

    public function testWithersProduceNewInstances(): void
    {
        $img = Image::fromRow(self::row());
        $vm  = PictureViewModel::fromImage($img);

        $withUrl   = $vm->withUrl('/picture/42');
        $withTitle = $withUrl->withTitle('Foo', 'Foo&amp;');
        $withCur   = $withTitle->withCurrentExtras('/root/galleries/foo.png', null, '/action/42/e');

        // Original untouched (readonly with-er semantics).
        self::assertSame('', $vm->url);
        self::assertSame('/picture/42', $withUrl->url);
        self::assertSame('Foo', $withTitle->title);
        self::assertSame('Foo&amp;', $withTitle->titleEsc);
        self::assertSame('/root/galleries/foo.png', $withCur->elementPath);
        self::assertNull($withCur->elementUrl);
        self::assertSame('/action/42/e', $withCur->downloadUrl);
    }

    public function testToArrayMirrorsRowShapePlusDerivedFields(): void
    {
        $img = Image::fromRow(self::row());
        $vm  = PictureViewModel::fromImage($img)
            ->withUrl('/picture/42')
            ->withTitle('Foo', 'Foo&amp;');

        $arr = $vm->toArray();

        // Core image fields preserved as scalars.
        self::assertSame(42, $arr['id']);
        self::assertSame('galleries/foo.png', $arr['file']);
        self::assertSame('galleries/foo.png', $arr['path']);
        self::assertSame(7, $arr['hit']);
        self::assertSame('foo title', $arr['name']);
        self::assertSame('foo desc', $arr['comment']);
        self::assertSame('2026-04-01 09:00:00', $arr['date_creation']);
        self::assertSame('2026-04-02 09:00:00', $arr['date_available']);

        // Derived view fields exposed alongside row fields.
        self::assertSame('/picture/42', $arr['url']);
        self::assertSame('Foo', $arr['TITLE']);
        self::assertSame('Foo&amp;', $arr['TITLE_ESC']);
        self::assertSame('png', $arr['path_ext']);
        self::assertSame('png', $arr['file_ext']);
        self::assertSame($vm->srcImage, $arr['src_image']);
        self::assertSame($vm->derivatives, $arr['derivatives']);
    }
}
