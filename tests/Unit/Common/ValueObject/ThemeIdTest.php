<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<ThemeId> */
final class ThemeIdTest extends StringVoContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return ThemeId::class;
    }

    #[\Override]
    protected static function validSample(): string
    {
        return 'elegant';
    }

    /** @return iterable<string, array{string}> */
    #[\Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'              => [''];
        yield 'whitespace'         => ['my theme'];
        yield 'slash'              => ['../etc'];
        yield 'dot path'           => ['.'];
        yield 'unicode'            => ['café'];
        yield 'too long (65 chars)' => [str_repeat('a', 65)];
    }
}
