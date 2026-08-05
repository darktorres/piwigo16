<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Override;
use InvalidArgumentException;
use Piwigo\Common\ValueObject\AbsPath;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<AbsPath> */
final class AbsPathTest extends StringVoContract
{
    #[Override]
    protected static function voClass(): string
    {
        return AbsPath::class;
    }

    #[Override]
    protected static function validSample(): string
    {
        return '/var/www/piwigo/upload/2024';
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
        yield 'empty'         => [''];
        yield 'relative'      => ['var/www'];
        yield 'parent segment' => ['/var/../etc'];
        yield 'backslash'     => ['/var\\www'];
        yield 'null byte'     => ["/var/www\x00"];
    }

    public function testFromRejectsEmptyStringWithItsOwnMessage(): void
    {
        // The shared contract's invalidSamples()-driven test only ever
        // asserts the generic exception class -- an empty string also
        // fails the very next check (str_starts_with('', '/') is false),
        // so a mutated `$value === ''` (e.g. compared against some other
        // placeholder) would still throw, just with the *other* message.
        // Pinning the exact message proves the dedicated empty check is
        // what actually fired.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AbsPath must not be empty');
        AbsPath::from('');
    }
}
