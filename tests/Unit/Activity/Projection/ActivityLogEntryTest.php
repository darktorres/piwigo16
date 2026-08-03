<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Activity\Projection\UserActivityLogEntry;

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
        'performed_by' => '5',
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
    expect($entry->action)->toBe('');
    expect($entry->ipAddress)->toBeNull();
    expect($entry->details)->toBeNull();
    expect($entry->username)->toBe('');
});

test('UserActivityLogEntry::toArray unwraps the IpAddress value object back to a raw string', function (): void {
    $entry = UserActivityLogEntry::fromRow([
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
    expect($entry->details)->toBeNull();
    expect($entry->username)->toBeNull();
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
