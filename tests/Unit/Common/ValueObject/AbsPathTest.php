<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\AbsPath;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<AbsPath> */
final class AbsPathTest extends StringVoContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return AbsPath::class;
    }

    #[\Override]
    protected static function validSample(): string
    {
        return '/var/www/piwigo/upload/2024';
    }

    /** @return iterable<string, array{string}> */
    #[\Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'         => [''];
        yield 'relative'      => ['var/www'];
        yield 'parent segment' => ['/var/../etc'];
        yield 'backslash'     => ['/var\\www'];
        yield 'null byte'     => ["/var/www\x00"];
    }
}
