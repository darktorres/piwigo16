<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use InvalidArgumentException;
use Override;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/**
 * @extends StringVoContract<Username>
 */
final class UsernameTest extends StringVoContract
{
    #[Override]
    protected static function voClass(): string
    {
        return Username::class;
    }

    #[Override]
    protected static function validSample(): string
    {
        return 'admin';
    }

    #[Override]
    protected static function otherVoClass(): string
    {
        return ThemeId::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    #[Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty' => [''];
        yield 'leading space' => [' admin'];
        yield 'trailing space' => ['admin '];
        yield 'tab' => ["admin\t"];
        yield 'newline' => ["admin\n"];
        yield 'null byte' => ["adm\x00in"];
        yield 'embedded control' => ["adm\x01in"];
        yield 'DEL char' => ["adm\x7Fin"];
        yield 'over 100 chars' => [str_repeat('a', 101)];
        yield 'less-than' => ['<script>'];
        yield 'greater-than' => ['admin>'];
        yield 'ampersand' => ['admin&co'];
        yield 'double quote' => ['admin"'];
        yield 'single quote' => ["admin'"];
    }

    public function testCaseSensitiveBinaryCollation(): void
    {
        // utf8mb4_bin on `users.username` makes Foo and foo distinct accounts.
        // VO must reflect that: two distinct Username instances, equals() false.
        self::assertFalse(Username::from('Foo')->equals(Username::from('foo')));
    }

    public function testFromAcceptsTheExactBoundaryLengthOf100(): void
    {
        // 'over 100 chars' in invalidSamples() above is 101 chars --
        // genuinely over the limit either way, so it can't tell `> 100`
        // apart from a mutated `>= 100`. Only the exact boundary can.
        $exactly100 = str_repeat('a', 100);

        self::assertSame($exactly100, Username::from($exactly100)->value);
    }

    public function testFromRejectsAValueGenuinelyOverTheLengthBoundaryWithItsOwnMessage(): void
    {
        // Pins the exact message so a mutated concatenation (dropping the
        // prefix/suffix/limit or swapping operand order) is caught.
        $tooLong = str_repeat('a', 101);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("Username exceeds 100 chars: '{$tooLong}'");
        Username::from($tooLong);
    }

    public function testFromRejectsAnHtmlSpecialCharacterWithItsOwnMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must not contain');
        Username::from('<script>alert(1)</script>');
    }

    public function testFromAcceptsUnicodeAndPunctuationOutsideTheHtmlSpecialCharacterClass(): void
    {
        // The P44-H restriction is deliberately narrow: legitimate
        // non-ASCII names, spaces, and punctuation outside '<>&"\''
        // stay valid.
        $name = 'Jean-François (test), déjà-vu';
        self::assertSame($name, Username::from($name)->value);
    }
}
