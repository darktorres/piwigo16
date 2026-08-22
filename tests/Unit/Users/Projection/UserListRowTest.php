<?php

declare(strict_types=1);

use Piwigo\Users\Projection\UserListRow;

test('fromRow narrows a real full row (UserRowFetcher::DISPLAY_COLUMNS shape)', function (): void {
    $row = UserListRow::fromRow([
        'id' => '7',
        'username' => 'alice',
        'email' => 'alice@example.com',
        'status' => 'admin',
        'level' => '4',
        'language' => 'en_UK',
        'theme' => 'modus',
        'registration_date' => '2026-01-01 00:00:00',
        'last_visit' => '2026-02-01 00:00:00',
        'last_visit_from_history' => true,
        'nb_image_page' => '20',
        'recent_period' => '7',
        'expand' => true,
        'show_nb_comments' => false,
        'show_nb_hits' => true,
        'enabled_high' => false,
    ]);

    expect($row->id)
        ->toBe(7)
        ->and($row->username)
        ->toBe('alice')
        ->and($row->email)
        ->toBe('alice@example.com')
        ->and($row->status)
        ->toBe('admin')
        ->and($row->level)
        ->toBe(4)
        ->and($row->language)
        ->toBe('en_UK')
        ->and($row->theme)
        ->toBe('modus')
        ->and($row->registrationDate)
        ->toBe('2026-01-01 00:00:00')
        ->and($row->lastVisit)
        ->toBe('2026-02-01 00:00:00')
        ->and($row->lastVisitFromHistory)
        ->toBeTrue()
        ->and($row->nbImagePage)
        ->toBe(20)
        ->and($row->recentPeriod)
        ->toBe(7)
        ->and($row->expand)
        ->toBeTrue()
        ->and($row->showNbComments)
        ->toBeFalse()
        ->and($row->showNbHits)
        ->toBeTrue()
        ->and($row->enabledHigh)
        ->toBeFalse();
});

test('fromRow defaults every absent column to its zero value', function (): void {
    $row = UserListRow::fromRow([]);

    expect($row->id)
        ->toBe(0)
        ->and($row->username)
        ->toBe('')
        ->and($row->email)
        ->toBeNull()
        ->and($row->status)
        ->toBeNull()
        ->and($row->level)
        ->toBeNull()
        ->and($row->language)
        ->toBeNull()
        ->and($row->theme)
        ->toBeNull()
        ->and($row->registrationDate)
        ->toBeNull()
        ->and($row->lastVisit)
        ->toBeNull()
        ->and($row->lastVisitFromHistory)
        ->toBeFalse()
        ->and($row->nbImagePage)
        ->toBeNull()
        ->and($row->recentPeriod)
        ->toBeNull()
        ->and($row->expand)
        ->toBeFalse()
        ->and($row->showNbComments)
        ->toBeFalse()
        ->and($row->showNbHits)
        ->toBeFalse()
        ->and($row->enabledHigh)
        ->toBeFalse();
});

test('fromRow treats the old enum(\'true\',\'false\') string form the same as a real bool', function (): void {
    $row = UserListRow::fromRow([
        'expand' => 'false',
        'show_nb_comments' => 'true',
    ]);

    expect($row->expand)
        ->toBeFalse()
        ->and($row->showNbComments)
        ->toBeTrue();
});
