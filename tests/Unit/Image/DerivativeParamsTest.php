<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use PHPUnit\Framework\TestCase;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SizingParams;

final class DerivativeParamsTest extends TestCase
{
    private function makeParams(int $w, int $h, float $crop = 0): DerivativeParams
    {
        return new DerivativeParams(SizingParams::classic($w, $h));
    }

    public function testMaxWidthAndHeight(): void
    {
        $p = $this->makeParams(800, 600);
        self::assertSame(800, $p->maxWidth());
        self::assertSame(600, $p->maxHeight());
    }

    public function testComputeFinalSizeScalesDown(): void
    {
        $p = $this->makeParams(400, 300);
        $size = $p->computeFinalSize([800, 600]);
        // floor() in SizingParams::compute returns floats; compare as integers.
        self::assertSame(400, (int) $size[0]);
        self::assertSame(300, (int) $size[1]);
    }

    public function testComputeFinalSizeIdentityWhenSmaller(): void
    {
        $p = $this->makeParams(400, 300);
        $size = $p->computeFinalSize([200, 150]);
        self::assertSame([200, 150], $size, 'image smaller than ideal is returned as-is');
    }

    public function testIsIdentityTrueWhenFits(): void
    {
        $p = $this->makeParams(800, 600);
        self::assertTrue($p->isIdentity([400, 300]));
        self::assertTrue($p->isIdentity([800, 600]));
    }

    public function testIsIdentityFalseWhenExceedsIdeal(): void
    {
        $p = $this->makeParams(400, 300);
        self::assertFalse($p->isIdentity([800, 600]));
        self::assertFalse($p->isIdentity([400, 301]));
    }

    public function testWillWatermarkFalseWhenNotEnabled(): void
    {
        $p = $this->makeParams(800, 600);
        $p->use_watermark = false;
        self::assertFalse($p->willWatermark([800, 600]));
    }

    public function testDefaultProperties(): void
    {
        $p = new DerivativeParams(SizingParams::classic(100, 100));
        self::assertSame(0, $p->last_mod_time);
        self::assertFalse($p->use_watermark);
        self::assertEquals(0, $p->sharpen);
    }
}
