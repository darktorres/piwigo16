<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Activity\Projection\UserActivityLogEntry;
use Piwigo\Common\ValueObject\UserId;

/**
 * Piwigo\Activity\Projection\UserActivityLogEntry/SystemActivityLogEntry
 * -- had zero dedicated coverage for fromRow()/toArray() (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1); the
 * existing ActivityLogEntryFormatterTest only ever constructs these via
 * their plain constructor, never fromRow().
 */
test('UserActivityLogEntry::fromRow narrows a real joined row, including a valid ip_address', function (): void {
    $entry = UserActivityLogEntry::fromRow([
        'activity_id' => '10',
        // A real row's `performed_by` is a UserId instance
        // (ActivityEntity::$performedBy is UserId-typed, DQL array
        // hydration applies the custom Type), not a raw scalar.
        'performed_by' => UserId::from(5),
        'object' => 'user',
        'object_id' => '5',
        'action' => 'login',
        'ip_address' => '203.0.113.5',
        'occured_on' => '2024-01-01 12:00:00',
        'details' => '{"agent":"test"}',
        'username' => 'fixture_admin',
    ]);

    expect($entry->activityId)->toBe(10);
    expect($entry->performedBy)->toBe(5);
    expect($entry->object)->toBe('user');
    expect($entry->objectId)->toBe(5);
    expect($entry->action)->toBe('login');
    expect($entry->ipAddress?->value)->toBe('203.0.113.5');
    expect($entry->occuredOn)->toBe('2024-01-01 12:00:00');
    // details stays the raw JSON string here (unlike SystemActivityLogEntry) --
    // its one consumer (the CSV export) writes it out opaquely.
    expect($entry->details)->toBe('{"agent":"test"}');
    expect($entry->username)->toBe('fixture_admin');
});

test('UserActivityLogEntry::fromRow resolves an invalid ip_address string to null', function (): void {
    $entry = UserActivityLogEntry::fromRow(['ip_address' => 'not-an-ip']);

    expect($entry->ipAddress)->toBeNull();
});

test('UserActivityLogEntry::fromRow falls back to safe defaults for a fully empty row', function (): void {
    $entry = UserActivityLogEntry::fromRow([]);

    expect($entry->activityId)->toBe(0);
    expect($entry->performedBy)->toBeNull();
    expect($entry->object)->toBe('');
    // Kills line 50's DecrementInteger/IncrementInteger (0 -> -1/1): the
    // objectId fallback for a missing/non-numeric object_id must be
    // exactly 0, not any other sentinel.
    expect($entry->objectId)->toBe(0);
    expect($entry->action)->toBe('');
    expect($entry->ipAddress)->toBeNull();
    // Kills line 53's EmptyStringToNotEmpty ('' -> 'PEST Mutator was
    // here!'): the occuredOn fallback for a missing/non-string
    // occured_on must be exactly '', not some placeholder text.
    expect($entry->occuredOn)->toBe('');
    expect($entry->details)->toBeNull();
    expect($entry->username)->toBe('');
});

test('UserActivityLogEntry::toArray unwraps the IpAddress value object back to a raw string', function (): void {
    $entry = UserActivityLogEntry::fromRow([
        'activity_id' => 10,
        'performed_by' => UserId::from(5),
        'object' => 'user',
        'object_id' => 5,
        'action' => 'login',
        'ip_address' => '203.0.113.5',
        'occured_on' => '2024-01-01 12:00:00',
        'details' => '{"agent":"test"}',
        'username' => 'fixture_admin',
    ]);

    expect($entry->toArray())->toBe([
        'activity_id' => 10,
        'performed_by' => 5,
        'object' => 'user',
        'object_id' => 5,
        'action' => 'login',
        'ip_address' => '203.0.113.5',
        'occured_on' => '2024-01-01 12:00:00',
        'details' => '{"agent":"test"}',
        'username' => 'fixture_admin',
    ]);
});

test('UserActivityLogEntry::toArray null-safes a null ipAddress instead of fataling', function (): void {
    // Kills line 72's RemoveNullSafeOperator (`$this->ipAddress?->value`
    // -> `$this->ipAddress->value`): with no/invalid ip_address in the
    // source row, fromRow() leaves $ipAddress null, so a plain `->value`
    // property access on null would throw a fatal "Attempt to read
    // property 'value' on null" instead of toArray() succeeding with a
    // null 'ip_address' entry.
    $entry = UserActivityLogEntry::fromRow([
        'activity_id' => 10,
        'object' => 'user',
        'object_id' => 5,
        'action' => 'login',
        'occured_on' => '2024-01-01 12:00:00',
        'username' => 'fixture_admin',
    ]);

    expect($entry->ipAddress)->toBeNull()
        ->and($entry->toArray())->toBe([
            'activity_id' => 10,
            'performed_by' => null,
            'object' => 'user',
            'object_id' => 5,
            'action' => 'login',
            'ip_address' => null,
            'occured_on' => '2024-01-01 12:00:00',
            'details' => null,
            'username' => 'fixture_admin',
        ]);
});

test('SystemActivityLogEntry::fromRow narrows a real row, keeping the already-decoded details array', function (): void {
    $entry = SystemActivityLogEntry::fromRow([
        'activity_id' => '20',
        'performed_by' => null,
        'object_id' => '1',
        'action' => 'update',
        'occured_on' => '2024-02-01 00:00:00',
        'details' => ['from_version' => '16.0', 'to_version' => '17.0'],
        'username' => null,
    ]);

    expect($entry->activityId)->toBe(20);
    expect($entry->performedBy)->toBeNull();
    expect($entry->objectId)->toBe(1);
    expect($entry->action)->toBe('update');
    expect($entry->occuredOn)->toBe('2024-02-01 00:00:00');
    expect($entry->details)->toBe(['from_version' => '16.0', 'to_version' => '17.0']);
    expect($entry->username)->toBeNull();
});

test('SystemActivityLogEntry::fromRow falls back to safe defaults for a fully empty row', function (): void {
    $entry = SystemActivityLogEntry::fromRow([]);

    expect($entry->activityId)->toBe(0);
    expect($entry->performedBy)->toBeNull();
    expect($entry->objectId)->toBe(0);
    expect($entry->action)->toBe('');
    expect($entry->occuredOn)->toBe('');
    expect($entry->details)->toBeNull();
    expect($entry->username)->toBeNull();
});

test('SystemActivityLogEntry::fromRow narrows a real performed_by UserId instance', function (): void {
    // Real gap, found via mutation testing: every other SystemActivityLogEntry
    // fixture in this file passes performed_by: null, so nothing exercised
    // the `instanceof UserId` branch that unwraps a real UserId into its
    // plain int value.
    $entry = SystemActivityLogEntry::fromRow([
        'performed_by' => UserId::from(7),
    ]);

    expect($entry->performedBy)->toBe(7);
});

test('SystemActivityLogEntry::fromRow narrows a real username string', function (): void {
    // Real gap, found via mutation testing: every other SystemActivityLogEntry
    // fixture in this file passes username: null, so nothing exercised the
    // is_string() branch that keeps a real username string as-is.
    $entry = SystemActivityLogEntry::fromRow([
        'username' => 'fixture_admin',
    ]);

    expect($entry->username)->toBe('fixture_admin');
});

test('SystemActivityLogEntry::fromRow filters non-string keys out of the details array', function (): void {
    $entry = SystemActivityLogEntry::fromRow([
        'details' => ['from_version' => '16.0', 0 => 'stray', 'to_version' => '17.0'],
    ]);

    expect($entry->details)->toBe(['from_version' => '16.0', 'to_version' => '17.0']);
});

test('SystemActivityLogEntry::toArray round-trips the decoded details array as-is', function (): void {
    $entry = SystemActivityLogEntry::fromRow([
        'activity_id' => 20,
        'performed_by' => null,
        'object_id' => 1,
        'action' => 'update',
        'occured_on' => '2024-02-01 00:00:00',
        'details' => ['from_version' => '16.0', 'to_version' => '17.0'],
        'username' => null,
    ]);

    expect($entry->toArray())->toBe([
        'activity_id' => 20,
        'performed_by' => null,
        'object_id' => 1,
        'action' => 'update',
        'occured_on' => '2024-02-01 00:00:00',
        'details' => ['from_version' => '16.0', 'to_version' => '17.0'],
        'username' => null,
    ]);
});
