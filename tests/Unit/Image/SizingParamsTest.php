<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use PHPUnit\Framework\TestCase;
use Piwigo\Image\ImageRect;
use Piwigo\Image\SizingParams;

final class SizingParamsTest extends TestCase
{
    public function testClassicIdentityNoScaleNeeded(): void
    {
        SizingParams::classic(400, 300)->compute([400, 300], null, $crop, $scale);
        self::assertNull($crop, 'no crop when image fits exactly');
        self::assertNull($scale, 'no scale when image fits exactly');
    }

    public function testClassicScaleDown(): void
    {
        SizingParams::classic(400, 300)->compute([800, 600], null, $crop, $scale);
        self::assertNull($crop);
        self::assertNotNull($scale);
        self::assertArrayHasKey(0, $scale);
        self::assertArrayHasKey(1, $scale);
        // compute() uses floor() which produces floats; compare as integers.
        self::assertSame(400, (int) $scale[0]);
        self::assertSame(300, (int) $scale[1]);
    }

    public function testClassicAlreadySmallerNoScale(): void
    {
        SizingParams::classic(400, 300)->compute([200, 150], null, $crop, $scale);
        self::assertNull($crop);
        self::assertNull($scale, 'image smaller than ideal — no resize needed');
    }

    public function testClassicConstrainedByWidth(): void
    {
        // Input 800×300, ideal 400×300: width is the constraining dimension
        SizingParams::classic(400, 300)->compute([800, 300], null, $crop, $scale);
        self::assertNull($crop);
        self::assertNotNull($scale);
        self::assertArrayHasKey(0, $scale);
        self::assertArrayHasKey(1, $scale);
        self::assertSame(400, (int) $scale[0]);
        self::assertSame(150, (int) $scale[1]);
    }

    public function testClassicConstrainedByHeight(): void
    {
        // Input 400×900, ideal 400×300: height constrains
        SizingParams::classic(400, 300)->compute([400, 900], null, $crop, $scale);
        self::assertNull($crop);
        self::assertNotNull($scale);
        self::assertArrayHasKey(1, $scale);
        self::assertSame(300, (int) $scale[1]);
    }

    public function testSquareCropsAndScales(): void
    {
        SizingParams::square(200)->compute([800, 600], null, $crop, $scale);
        self::assertInstanceOf(ImageRect::class, $crop, 'square sizing must crop non-square input');
        self::assertNotNull($scale);
        self::assertArrayHasKey(0, $scale);
        self::assertArrayHasKey(1, $scale);
        self::assertSame(200, (int) $scale[0]);
        self::assertSame(200, (int) $scale[1]);
    }

    public function testSquareAlreadySquareAtTargetNoCrop(): void
    {
        SizingParams::square(200)->compute([200, 200], null, $crop, $scale);
        self::assertNull($crop, 'already square at target size — no crop needed');
        self::assertNull($scale);
    }

    public function testSquareLargerSquareScalesDown(): void
    {
        SizingParams::square(200)->compute([800, 800], null, $crop, $scale);
        self::assertNull($crop, 'already square — no crop');
        self::assertNotNull($scale);
        self::assertArrayHasKey(0, $scale);
        self::assertArrayHasKey(1, $scale);
        self::assertSame(200, (int) $scale[0]);
        self::assertSame(200, (int) $scale[1]);
    }
}
