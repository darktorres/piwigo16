<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<Username> */
final class UsernameTest extends StringVoContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return Username::class;
    }

    #[\Override]
    protected static function validSample(): string
    {
        return 'admin';
    }

    /** @return iterable<string, array{string}> */
    #[\Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'              => [''];
        yield 'leading space'      => [' admin'];
        yield 'trailing space'     => ['admin '];
        yield 'tab'                => ["admin\t"];
        yield 'newline'            => ["admin\n"];
        yield 'null byte'          => ["adm\x00in"];
        yield 'embedded control'   => ["adm\x01in"];
        yield 'DEL char'           => ["adm\x7Fin"];
        yield 'over 100 chars'     => [str_repeat('a', 101)];
    }

    public function testCaseSensitiveBinaryCollation(): void
    {
        // utf8mb4_bin on `users.username` makes Foo and foo distinct accounts.
        // VO must reflect that: two distinct Username instances, equals() false.
        self::assertFalse(Username::from('Foo')->equals(Username::from('foo')));
    }
}
