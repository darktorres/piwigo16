<?php

declare(strict_types=1);

use Piwigo\Audit\Projection\AuditLogEntry;
use Piwigo\Common\ValueObject\IpAddress;

/**
 * @return array<string, mixed>
 */
function fullAuditLogEntryRow(): array
{
    return [
        'id' => '42',
        'actor_id' => '1',
        'action' => 'create',
        'entity_type' => 'user',
        'entity_id' => '7',
        'before_json' => null,
        'after_json' => '{"username":"alice"}',
        'ip_address' => '10.0.0.1',
        'created_at' => '2026-07-12 10:00:00',
        'prev_hash' => str_repeat('a', 64),
        'row_hash' => str_repeat('b', 64),
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $entry = AuditLogEntry::fromRow(fullAuditLogEntryRow());

    expect($entry->id)->toBe(42)
        ->and($entry->actorId)->toBe(1)
        ->and($entry->action)->toBe('create')
        ->and($entry->entityType)->toBe('user')
        ->and($entry->entityId)->toBe(7)
        ->and($entry->beforeJson)->toBeNull()
        ->and($entry->afterJson)->toBe('{"username":"alice"}')
        ->and($entry->ipAddress)->toEqual(IpAddress::from('10.0.0.1'))
        ->and($entry->createdAt)->toBe('2026-07-12 10:00:00')
        ->and($entry->prevHash)->toBe(str_repeat('a', 64))
        ->and($entry->rowHash)->toBe(str_repeat('b', 64));
});

test('fromRow defaults every nullable column to null when absent', function (): void {
    $row = fullAuditLogEntryRow();
    foreach (['actor_id', 'entity_id', 'before_json', 'after_json', 'ip_address', 'prev_hash'] as $key) {
        $row[$key] = null;
    }

    $entry = AuditLogEntry::fromRow($row);

    expect($entry->actorId)->toBeNull()
        ->and($entry->entityId)->toBeNull()
        ->and($entry->beforeJson)->toBeNull()
        ->and($entry->afterJson)->toBeNull()
        ->and($entry->ipAddress)->toBeNull()
        ->and($entry->prevHash)->toBeNull();
    // The NOT NULL columns (id/action/entity_type/created_at/row_hash) fall
    // back to their type's zero value instead, matching every other
    // narrowing helper in this codebase -- never actually null for a real
    // fetched row, since these are NOT NULL DB columns.
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = AuditLogEntry::fromRow(fullAuditLogEntryRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => 42,
        'actor_id' => 1,
        'action' => 'create',
        'entity_type' => 'user',
        'entity_id' => 7,
        'before_json' => null,
        'after_json' => '{"username":"alice"}',
        'ip_address' => '10.0.0.1',
        'created_at' => '2026-07-12 10:00:00',
        'prev_hash' => str_repeat('a', 64),
        'row_hash' => str_repeat('b', 64),
    ]);
});
