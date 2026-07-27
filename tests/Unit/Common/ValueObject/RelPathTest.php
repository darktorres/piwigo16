<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\RelPath;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<RelPath> */
final class RelPathTest extends StringVoContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return RelPath::class;
    }

    #[\Override]
    protected static function validSample(): string
    {
        return 'galleries/2024/IMG_0001.jpg';
    }

    /** @return iterable<string, array{string}> */
    #[\Override]
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
}
