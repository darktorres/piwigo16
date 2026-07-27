<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\Enum;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\Enum\Privacy;

final class PrivacyTest extends TestCase
{
    public function testCaseValuesMatchEnumColumn(): void
    {
        // Backing values exactly match `categories.status enum('public','private')`.
        self::assertSame('public', Privacy::Public->value);
        self::assertSame('private', Privacy::Private->value);
    }

    public function testFromRoundTrips(): void
    {
        self::assertSame(Privacy::Public, Privacy::from('public'));
        self::assertSame(Privacy::Private, Privacy::from('private'));
    }

    public function testTryFromReturnsNullForUnknown(): void
    {
        self::assertNull(Privacy::tryFrom('not_a_privacy_' . uniqid()));
    }

    public function testTwoCases(): void
    {
        self::assertCount(2, Privacy::cases());
    }
}
