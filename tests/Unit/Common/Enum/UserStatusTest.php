<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\Enum;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\Enum\UserStatus;

final class UserStatusTest extends TestCase
{
    public function testCaseValuesMatchPersistedStrings(): void
    {
        // Locks the backing values — the `users.status` column stores these
        // exact strings; renaming a case must NOT change the wire format.
        self::assertSame('webmaster', UserStatus::Webmaster->value);
        self::assertSame('admin', UserStatus::Admin->value);
        self::assertSame('normal', UserStatus::Normal->value);
        self::assertSame('generic', UserStatus::Generic->value);
        self::assertSame('guest', UserStatus::Guest->value);
    }

    public function testFromRoundTrips(): void
    {
        self::assertSame(UserStatus::Webmaster, UserStatus::from('webmaster'));
        self::assertSame(UserStatus::Guest, UserStatus::from('guest'));
    }

    public function testTryFromReturnsNullForUnknown(): void
    {
        // Use a runtime-generated string so static analysers don't fold
        // the result; we want to exercise tryFrom() at runtime.
        self::assertNull(UserStatus::tryFrom('not_a_status_' . uniqid()));
    }

    public function testFiveCases(): void
    {
        self::assertCount(5, UserStatus::cases());
    }
}
