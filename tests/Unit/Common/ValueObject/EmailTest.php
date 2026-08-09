<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Override;
use InvalidArgumentException;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<Email> */
final class EmailTest extends StringVoContract
{
    #[Override]
    protected static function voClass(): string
    {
        return Email::class;
    }

    #[Override]
    protected static function validSample(): string
    {
        return 'user@example.com';
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
        yield 'empty'                => [''];
        yield 'no at sign'           => ['user.example.com'];
        yield 'no local part'        => ['@example.com'];
        yield 'no domain'            => ['user@'];
        yield 'whitespace'           => ['user @example.com'];
        yield 'control char'         => ["user\x01@example.com"];
        // Exactly at the 255-char boundary (250 + '@x.io' = 255) --
        // rejected by filter_var()'s own local-part-length rule, NOT by
        // Email::from()'s own MAX_LENGTH check (`> 255`, not `>=`). See
        // 'genuinely over 255 chars' below for that distinct branch.
        yield 'over 255 chars'       => [str_repeat('a', 250) . '@x.io'];
        yield 'genuinely over 255 chars' => [str_repeat('a', 251) . '@x.io'];
    }

    public function testAcceptsPlusTagAndSubdomain(): void
    {
        // Smoke-test the cases filter_var permits — locks in the canonical
        // shape so we notice if the underlying validator changes.
        self::assertNotNull(Email::tryFrom('user.name+tag@sub.example.com'));
        self::assertNotNull(Email::tryFrom('a@b.cc'));
    }

    public function testFromRejectsAValueGenuinelyOverTheLengthBoundaryWithItsOwnMessage(): void
    {
        // Distinct from the 'over 255 chars'/'genuinely over 255 chars'
        // invalidSamples() entries above (which only assert the generic
        // exception class via the shared contract) -- this pins the exact
        // message text, proving Email::from()'s own `> 255` check (not
        // filter_var()) is what rejected it, and that the message embeds
        // the real MAX_LENGTH/value rather than some mutated fragment.
        $tooLong = str_repeat('a', 256);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("Email exceeds 255 chars: '{$tooLong}'");
        Email::from($tooLong);
    }

    public function testFromRejectsTheExact255CharBoundaryViaFilterVarNotMaxLength(): void
    {
        // 'over 255 chars' in invalidSamples() above is exactly 255 chars
        // (250 + '@x.io') -- its own docblock already explains it's
        // rejected by filter_var()'s local-part-length rule, not
        // Email::from()'s own `strlen($value) > self::MAX_LENGTH` check
        // (255 is not *greater than* 255). A mutated `>=` would instead
        // reject it right there with the *other* message -- pinning the
        // exact message is what tells the two rejection paths apart.
        $atBoundary = str_repeat('a', 250) . '@x.io';
        self::assertSame(255, strlen($atBoundary));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("Invalid email address: '{$atBoundary}'");
        Email::from($atBoundary);
    }

    public function testEqualsReturnsFalseForADifferentEmailValue(): void
    {
        // testEqualsAndStringable() in the shared contract only ever
        // compares two *equal*-value instances, which can't distinguish
        // equals()'s `&&` from a mutated `||` (both yield true there).
        $a = Email::from('user@example.com');
        $b = Email::from('other@example.com');

        self::assertFalse($a->equals($b));
    }
}
