<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use PHPUnit\Framework\TestCase;
use Piwigo\Image\ImageRect;

final class ImageRectTest extends TestCase
{
    public function testConstructorSetsInitialBounds(): void
    {
        $r = new ImageRect([800, 600]);
        self::assertSame(0, $r->l);
        self::assertSame(0, $r->t);
        self::assertSame(800, $r->r);
        self::assertSame(600, $r->b);
    }

    public function testWidthAndHeight(): void
    {
        $r = new ImageRect([1920, 1080]);
        self::assertSame(1920, $r->width());
        self::assertSame(1080, $r->height());
    }

    public function testCropHorizontalNoCoi(): void
    {
        $r = new ImageRect([800, 600]);
        $r->cropH(100, null);
        self::assertSame(700, $r->width(), 'width should shrink by 100');
        // Center crop: 50 from left, 50 from right
        self::assertSame(50, $r->l);
        self::assertSame(750, $r->r);
    }

    public function testCropVerticalNoCoi(): void
    {
        $r = new ImageRect([800, 600]);
        $r->cropV(60, null);
        self::assertSame(540, $r->height(), 'height should shrink by 60');
        self::assertSame(30, $r->t);
        self::assertSame(570, $r->b);
    }

    public function testCropHorizontalWithCoiUsesStub(): void
    {
        // DerivativeEncoding::charToFraction() stub returns 0.5 (center) for any char,
        // so COI at center = no change in split vs. null COI
        $rNoCoi = new ImageRect([800, 600]);
        $rNoCoi->cropH(100, null);

        $rWithCoi = new ImageRect([800, 600]);
        $rWithCoi->cropH(100, 'CCCC');

        self::assertSame($rNoCoi->width(), $rWithCoi->width());
    }

    public function testCropLargerThanDimensionNoOp(): void
    {
        $r = new ImageRect([800, 600]);
        $r->cropH(800, null);
        // width() <= pixels at this point, so no-op
        self::assertSame(800, $r->width());
    }

    public function testChainedCrops(): void
    {
        $r = new ImageRect([800, 600]);
        $r->cropH(100, null);
        $r->cropV(60, null);
        self::assertSame(700, $r->width());
        self::assertSame(540, $r->height());
    }
}
