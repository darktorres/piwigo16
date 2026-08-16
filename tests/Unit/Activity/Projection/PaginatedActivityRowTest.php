<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\PaginatedActivityRow;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;

test('constructs with distinct values for every property', function (): void {
    $row = new PaginatedActivityRow(
        performedBy: 1,
        object: 'tag',
        objectId: 3,
        action: 'add',
        sessionIdx: 'abc123',
        ipAddress: IpAddress::from('127.0.0.1'),
        occuredOn: SqlDateTime::from('2026-08-01 00:00:00'),
        details: [
            'method' => 'GET',
        ],
        userAgent: 'Mozilla/5.0',
    );

    expect($row->performedBy)
        ->toBe(1)
        ->and($row->object)
        ->toBe('tag')
        ->and($row->objectId)
        ->toBe(3)
        ->and($row->action)
        ->toBe('add')
        ->and($row->sessionIdx)
        ->toBe('abc123')
        ->and($row->ipAddress)
        ->toEqual(IpAddress::from('127.0.0.1'))
        ->and($row->occuredOn)
        ->toEqual(SqlDateTime::from('2026-08-01 00:00:00'))
        ->and($row->details)
        ->toBe([
            'method' => 'GET',
        ])
        ->and($row->userAgent)
        ->toBe('Mozilla/5.0');
});

test('accepts a null performedBy/ipAddress/details/userAgent', function (): void {
    $row = new PaginatedActivityRow(
        performedBy: null,
        object: 'system',
        objectId: 1,
        action: 'startup',
        sessionIdx: 'abc123',
        ipAddress: null,
        occuredOn: SqlDateTime::from('2026-08-01 00:00:00'),
        details: null,
        userAgent: null,
    );

    expect($row->performedBy)
        ->toBeNull()
        ->and($row->ipAddress)
        ->toBeNull()
        ->and($row->details)
        ->toBeNull()
        ->and($row->userAgent)
        ->toBeNull();
});
