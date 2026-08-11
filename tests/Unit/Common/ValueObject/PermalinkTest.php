<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/**
 * @extends StringVoContract<Permalink>
 */
final class PermalinkTest extends StringVoContract
{
    #[Override]
    protected static function voClass(): string
    {
        return Permalink::class;
    }

    #[Override]
    protected static function validSample(): string
    {
        return 'events/2024-summer';
    }

    #[Override]
    protected static function otherVoClass(): string
    {
        return Username::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    #[Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty' => [''];
        yield 'leading slash' => ['/events'];
        yield 'trailing slash' => ['events/'];
        yield 'consecutive slashes' => ['events//summer'];
        yield 'space' => ['summer events'];
        yield 'unicode' => ['été'];
        yield 'purely numeric' => ['1234'];
        yield 'digit-dash prefix' => ['1234-summer'];
        yield 'over 64 chars' => [str_repeat('a', 65)];
    }

    public function testAcceptsHierarchicalAndSimpleSlugs(): void
    {
        self::assertNotNull(Permalink::tryFrom('simple-slug'));
        self::assertNotNull(Permalink::tryFrom('nested/path/here'));
        self::assertNotNull(Permalink::tryFrom('with_underscores'));
    }

    public function testFromRejectsEmptyStringWithItsOwnMessage(): void
    {
        // The shared contract's invalidSamples()-driven test only ever
        // asserts the generic exception class -- an empty string also
        // fails the very next check (the charset pattern requires at
        // least one char), so a mutated `$value === ''` would still
        // throw, just with the *other* message.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Permalink must not be empty');
        Permalink::from('');
    }

    public function testFromAcceptsTheExactBoundaryLengthOf64(): void
    {
        // 'over 64 chars' in invalidSamples() above is 65 chars --
        // genuinely over the limit either way, so it can't tell `> 64`
        // apart from a mutated `>= 64`. Only the exact boundary can.
        $exactly64 = str_repeat('a', 64);

        self::assertSame($exactly64, Permalink::from($exactly64)->value);
    }

    public function testFromRejectsAValueGenuinelyOverTheLengthBoundaryWithItsOwnMessage(): void
    {
        // Pins the exact message so a mutated concatenation (dropping the
        // prefix/suffix/limit or swapping operand order) is caught.
        $tooLong = str_repeat('a', 65);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("Permalink exceeds 64 chars: '{$tooLong}'");
        Permalink::from($tooLong);
    }
}
