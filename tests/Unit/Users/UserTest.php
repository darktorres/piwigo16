<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Users;

use PHPUnit\Framework\TestCase;
use Piwigo\Users\User;

final class UserTest extends TestCase
{
    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'            => 7,
            'username'      => 'alice',
            'email'         => 'alice@example.com',
            'language'      => 'fr_FR',
            'theme'         => 'elegant',
            'status'        => 'admin',
            'enabled_high'  => true,
        ];
    }

    public function testFromUserArrayCreatesCorrectUser(): void
    {
        $user = User::fromUserArray($this->baseRow());
        self::assertSame(7, $user->id);
        self::assertSame('alice', $user->username);
        self::assertSame('alice@example.com', $user->email);
        self::assertSame('fr_FR', $user->language);
        self::assertSame('elegant', $user->theme);
        self::assertSame('admin', $user->status);
        self::assertTrue($user->enabledHigh);
    }

    public function testFromUserArrayCoercesIdToInt(): void
    {
        $row = $this->baseRow();
        $row['id'] = '42';
        $user = User::fromUserArray($row);
        self::assertSame(42, $user->id);
    }

    public function testFromUserArrayDefaultsForMissingFields(): void
    {
        $user = User::fromUserArray([]);
        self::assertSame(0, $user->id);
        self::assertSame('', $user->username);
        self::assertSame('', $user->email);
        self::assertSame('en_US', $user->language);
        self::assertSame('elegant', $user->theme);
        self::assertSame('guest', $user->status);
        self::assertFalse($user->enabledHigh);
    }

    public function testRawAttributesCapturesFullRow(): void
    {
        $row = $this->baseRow();
        $row['plugin_custom_field'] = 'extra_value';
        $user = User::fromUserArray($row);
        self::assertSame('extra_value', $user->rawAttributes['plugin_custom_field']);
    }

    public function testIdIsReadonly(): void
    {
        $user = User::fromUserArray($this->baseRow());
        $this->expectException(\Error::class);
        /** @psalm-suppress InaccessibleProperty */
        $user->id = 99; // @phpstan-ignore assign.propertyProtectedSet
    }
}
