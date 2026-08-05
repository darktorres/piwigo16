<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Override;
use InvalidArgumentException;
use Piwigo\Common\ValueObject\RelPath;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<RelPath> */
final class RelPathTest extends StringVoContract
{
    #[Override]
    protected static function voClass(): string
    {
        return RelPath::class;
    }

    #[Override]
    protected static function validSample(): string
    {
        return 'galleries/2024/IMG_0001.jpg';
    }

    #[Override]
    protected static function otherVoClass(): string
    {
        return Username::class;
    }

    /** @return iterable<string, array{string}> */
    #[Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'                  => [''];
        yield 'absolute'               => ['/etc/passwd'];
        yield 'parent segment'         => ['galleries/../../../etc/passwd'];
        yield 'sole parent'            => ['..'];
        yield 'leading parent'         => ['../foo'];
        yield 'backslash'              => ['galleries\\foo.jpg'];
        yield 'null byte'              => ["galleries/foo\x00.jpg"];
        yield 'over 255 chars'         => [str_repeat('a', 256)];
    }

    public function testAcceptsDotPrefixAndDoubleDotInName(): void
    {
        // `./galleries/foo.jpg` is the historical Piwigo path form.
        self::assertNotNull(RelPath::tryFrom('./galleries/foo.jpg'));
        // `..bar` is a legal filename component (only the literal `..` segment is rejected).
        self::assertNotNull(RelPath::tryFrom('galleries/file..bar.jpg'));
    }

    public function testFromAcceptsTheExactBoundaryLengthOf255(): void
    {
        // 'over 255 chars' in invalidSamples() above is 256 chars --
        // genuinely over the limit either way, so it can't tell `> 255`
        // apart from a mutated `>= 255`. Only the exact boundary can.
        $exactly255 = str_repeat('a', 255);

        self::assertSame($exactly255, RelPath::from($exactly255)->value);
    }

    public function testFromRejectsAValueGenuinelyOverTheLengthBoundaryWithItsOwnMessage(): void
    {
        // Pins the exact message so a mutated concatenation (dropping the
        // prefix/suffix/limit or swapping operand order) is caught.
        $tooLong = str_repeat('a', 256);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("RelPath exceeds 255 chars: '{$tooLong}'");
        RelPath::from($tooLong);
    }
}
