<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<Permalink> */
final class PermalinkTest extends StringVoContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return Permalink::class;
    }

    #[\Override]
    protected static function validSample(): string
    {
        return 'events/2024-summer';
    }

    /** @return iterable<string, array{string}> */
    #[\Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'                => [''];
        yield 'leading slash'        => ['/events'];
        yield 'trailing slash'       => ['events/'];
        yield 'consecutive slashes'  => ['events//summer'];
        yield 'space'                => ['summer events'];
        yield 'unicode'              => ['été'];
        yield 'purely numeric'       => ['1234'];
        yield 'digit-dash prefix'    => ['1234-summer'];
        yield 'over 64 chars'        => [str_repeat('a', 65)];
    }

    public function testAcceptsHierarchicalAndSimpleSlugs(): void
    {
        self::assertNotNull(Permalink::tryFrom('simple-slug'));
        self::assertNotNull(Permalink::tryFrom('nested/path/here'));
        self::assertNotNull(Permalink::tryFrom('with_underscores'));
    }
}
