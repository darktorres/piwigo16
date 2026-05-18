<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Piwigo\Session\FlashBag;

final class FlashBagTest extends TestCase
{
    public function testEmptyBagHasNoMessages(): void
    {
        $bag = FlashBag::empty();
        self::assertFalse($bag->hasAny());
        self::assertSame([], $bag->peek('info'));
        self::assertSame([], $bag->toArray());
    }

    public function testAddAccumulates(): void
    {
        $bag = FlashBag::empty();
        $bag->add('info', 'first');
        $bag->add('info', 'second');
        $bag->add('error', 'oops');
        self::assertSame(['first', 'second'], $bag->peek('info'));
        self::assertSame(['oops'], $bag->peek('error'));
        self::assertTrue($bag->hasAny());
    }

    public function testPeekDoesNotClear(): void
    {
        $bag = FlashBag::empty();
        $bag->add('info', 'hello');
        // Three peeks back-to-back must all see the same message — peek is
        // the non-destructive read; consume() is the destructive one.
        self::assertSame(['hello'], $bag->peek('info'));
        self::assertSame(['hello'], $bag->peek('info'));
        self::assertSame(['hello'], $bag->peek('info'));
    }

    public function testConsumeClears(): void
    {
        $bag = FlashBag::empty();
        $bag->add('info', 'a');
        $bag->add('info', 'b');
        self::assertSame(['a', 'b'], $bag->consume('info'));
        self::assertSame([], $bag->peek('info'));
        self::assertSame([], $bag->consume('info'));
    }

    public function testConsumeIsKindScoped(): void
    {
        $bag = FlashBag::empty();
        $bag->add('info', 'i');
        $bag->add('error', 'e');
        $bag->consume('info');
        // error kind is untouched
        self::assertSame(['e'], $bag->peek('error'));
    }

    public function testFromArrayRoundTrips(): void
    {
        $payload = ['info' => ['x', 'y'], 'error' => ['z']];
        $bag     = FlashBag::fromArray($payload);
        self::assertSame($payload, $bag->toArray());
    }

    public function testToArrayOmitsEmptyKinds(): void
    {
        $bag = FlashBag::empty();
        $bag->add('info', 'a');
        $bag->consume('info');
        self::assertSame([], $bag->toArray());
    }
}
