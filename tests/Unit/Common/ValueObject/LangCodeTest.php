<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Override;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<LangCode> */
final class LangCodeTest extends StringVoContract
{
    #[Override]
    protected static function voClass(): string
    {
        return LangCode::class;
    }

    #[Override]
    protected static function validSample(): string
    {
        return 'en_US';
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
        yield 'lowercase region'  => ['en_us'];
        yield 'uppercase language' => ['EN_US'];
        yield 'too short language' => ['e_US'];
        yield 'too long language'  => ['eng_US'];
        yield 'hyphen separator'   => ['en-US'];
        yield 'no separator'       => ['enUS'];
        yield 'empty'              => [''];
        yield 'trailing whitespace' => ['en_US '];
        yield 'leading whitespace'  => [' en_US'];
    }
}
