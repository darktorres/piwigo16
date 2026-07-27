<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\Email;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<Email> */
final class EmailTest extends StringVoContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return Email::class;
    }

    #[\Override]
    protected static function validSample(): string
    {
        return 'user@example.com';
    }

    /** @return iterable<string, array{string}> */
    #[\Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'                => [''];
        yield 'no at sign'           => ['user.example.com'];
        yield 'no local part'        => ['@example.com'];
        yield 'no domain'            => ['user@'];
        yield 'whitespace'           => ['user @example.com'];
        yield 'control char'         => ["user\x01@example.com"];
        yield 'over 255 chars'       => [str_repeat('a', 250) . '@x.io'];
    }

    public function testAcceptsPlusTagAndSubdomain(): void
    {
        // Smoke-test the cases filter_var permits — locks in the canonical
        // shape so we notice if the underlying validator changes.
        self::assertNotNull(Email::tryFrom('user.name+tag@sub.example.com'));
        self::assertNotNull(Email::tryFrom('a@b.cc'));
    }
}
