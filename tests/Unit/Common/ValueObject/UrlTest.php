<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\Url;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<Url> */
final class UrlTest extends StringVoContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return Url::class;
    }

    #[\Override]
    protected static function validSample(): string
    {
        return 'https://piwigo.example.com/i/foo.jpg';
    }

    #[\Override]
    protected static function otherVoClass(): string
    {
        return Username::class;
    }

    /** @return iterable<string, array{string}> */
    #[\Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'         => [''];
        yield 'bare host'     => ['piwigo.example.com'];
        yield 'no scheme'     => ['//piwigo.example.com/i/foo.jpg'];
        yield 'whitespace'    => ['https://piwigo example.com'];
        yield 'garbage'       => ['not a url'];
    }

    public function testFromRejectsEmptyStringWithItsOwnMessage(): void
    {
        // The shared contract's invalidSamples()-driven test only ever
        // asserts the generic exception class -- an empty string also
        // fails filter_var(FILTER_VALIDATE_URL), so a mutated
        // `$value === ''` would still throw, just with the *other*
        // ("Invalid URL: ...") message.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Url must not be empty');
        Url::from('');
    }
}
