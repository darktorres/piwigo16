<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Rate\Projection\Rate;

/**
 * Piwigo\Rate\Projection\Rate -- had zero dedicated coverage. fromRow()
 * has no real production caller today (both real RateRepository readers
 * build Rate straight from an already-hydrated RateEntity instead), but
 * stays available for a future raw-row caller, same shape as
 * Users\Projection\UserInfo::fromRow().
 */
test('fromRow narrows a real DB row into typed properties', function (): void {
    $rate = Rate::fromRow([
        'user_id' => '3',
        'element_id' => '7',
        'anonymous_id' => '',
        'rate' => '4',
        'date' => '2026-08-01',
    ]);

    expect($rate->userId)
        ->toEqual(UserId::from(3))
        ->and($rate->elementId)
        ->toEqual(ImageId::from(7))
        ->and($rate->anonymousId)
        ->toBe('')
        ->and($rate->rate)
        ->toBe(4)
        ->and($rate->date)
        ->toBe('2026-08-01');
});

test('fromRow keeps already-hydrated UserId/ImageId instances as-is', function (): void {
    // Covers the getArrayResult() Gotcha #1 shape: real Doctrine array
    // hydration would already have converted user_id/element_id via their
    // own custom Types, not left them as raw strings.
    $rate = Rate::fromRow([
        'user_id' => UserId::from(5),
        'element_id' => ImageId::from(9),
        'rate' => 3,
    ]);

    expect($rate->userId)
        ->toEqual(UserId::from(5))
        ->and($rate->elementId)
        ->toEqual(ImageId::from(9));
});

test('fromRow defaults anonymous_id/rate/date when absent', function (): void {
    $rate = Rate::fromRow([
        'user_id' => 1,
        'element_id' => 1,
    ]);

    expect($rate->anonymousId)
        ->toBe('')
        ->and($rate->rate)
        ->toBe(0)
        ->and($rate->date)
        ->toBeNull();
});

test('fromRow throws when user_id is missing or invalid', function (): void {
    expect(fn (): Rate => Rate::fromRow([
        'element_id' => 1,
    ]))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive user id, got null');

    expect(fn (): Rate => Rate::fromRow([
        'user_id' => 'not-a-number',
        'element_id' => 1,
    ]))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive user id, got string');
});

test('fromRow throws when element_id is missing or invalid', function (): void {
    expect(fn (): Rate => Rate::fromRow([
        'user_id' => 1,
    ]))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive element id, got null');

    expect(fn (): Rate => Rate::fromRow([
        'user_id' => 1,
        'element_id' => 'not-a-number',
    ]))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive element id, got string');
});

test('toArray round-trips every typed property back into its raw DB-shaped key', function (): void {
    $rate = Rate::fromRow([
        'user_id' => 3,
        'element_id' => 7,
        'anonymous_id' => '192.168',
        'rate' => 5,
        'date' => '2026-08-01',
    ]);

    expect($rate->toArray())
        ->toBe([
            'user_id' => 3,
            'element_id' => 7,
            'anonymous_id' => '192.168',
            'rate' => 5,
            'date' => '2026-08-01',
        ]);
});
