<?php

declare(strict_types=1);

use Piwigo\Auth\Projection\ApiKey;
use Piwigo\Auth\UserAuthKeyEntity;
use Piwigo\Common\ValueObject\AuthKeyId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

test('fromRow narrows a full real row', function (): void {
    // revoked_on is a real, non-null string here so the test can
    // distinguish a real `?? null` coalesce from a mutated bare `null`
    // literal (CoalesceRemoveLeft): with a null value, `is_string(null)`
    // is false either way and revokedOn defaults to null regardless. A
    // real revoked key is a genuine row shape (see ApiKeyService
    // revocation).
    $apiKey = ApiKey::fromRow([
        'auth_key_id' => '5',
        'auth_key' => 'ck12345',
        'apikey_secret' => 'secret-value',
        'apikey_name' => 'My Key',
        'user_id' => '3',
        'created_on' => '2026-07-01 10:00:00',
        'duration' => '30',
        'expired_on' => '2026-08-01 10:00:00',
        'key_type' => 'api',
        'revoked_on' => '2026-07-25 08:00:00',
        'last_used_on' => '2026-07-15 09:00:00',
        'last_notified_on' => '2026-07-20 12:00:00',
    ]);

    expect($apiKey->authKeyId)
        ->toBe(5);
    expect($apiKey->authKey)
        ->toBe('ck12345');
    expect($apiKey->apikeySecret)
        ->toBe('secret-value');
    expect($apiKey->apikeyName)
        ->toBe('My Key');
    expect($apiKey->userId)
        ->toEqual(UserId::from(3));
    expect($apiKey->createdOn)
        ->toBe('2026-07-01 10:00:00');
    expect($apiKey->duration)
        ->toBe(30);
    expect($apiKey->expiredOn)
        ->toBe('2026-08-01 10:00:00');
    expect($apiKey->keyType)
        ->toBe('api');
    expect($apiKey->revokedOn)
        ->toBe('2026-07-25 08:00:00');
    expect($apiKey->lastUsedOn)
        ->toBe('2026-07-15 09:00:00');
    expect($apiKey->lastNotifiedOn)
        ->toBe('2026-07-20 12:00:00');
});

test('fromRow falls back to defaults for a missing/malformed row, except user_id', function (): void {
    // user_id is UserId now and can't silently default to 0 -- see the
    // dedicated "throws" tests below, same pattern as Comment/Rate.
    $apiKey = ApiKey::fromRow([
        'user_id' => 9,
    ]);

    expect($apiKey->authKeyId)
        ->toBe(0);
    expect($apiKey->authKey)
        ->toBe('');
    expect($apiKey->apikeySecret)
        ->toBeNull();
    expect($apiKey->apikeyName)
        ->toBeNull();
    expect($apiKey->createdOn)
        ->toBe('');
    expect($apiKey->duration)
        ->toBeNull();
    expect($apiKey->expiredOn)
        ->toBe('');
    expect($apiKey->keyType)
        ->toBeNull();
    expect($apiKey->revokedOn)
        ->toBeNull();
    expect($apiKey->lastUsedOn)
        ->toBeNull();
    expect($apiKey->lastNotifiedOn)
        ->toBeNull();
});

test('fromRow throws when user_id is missing', function (): void {
    expect(fn (): ApiKey => ApiKey::fromRow([]))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive user id, got null');
});

test('fromRow throws with the real debug type of a non-null but invalid user_id', function (): void {
    expect(fn (): ApiKey => ApiKey::fromRow([
        'user_id' => 'not-a-number',
    ]))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive user id, got string');
});

test('fromRow keeps an already-hydrated UserId instance as-is', function (): void {
    expect(ApiKey::fromRow([
        'user_id' => UserId::from(4),
    ])->userId)->toEqual(UserId::from(4));
});

test('fromEntity copies every field from a real UserAuthKeyEntity', function (): void {
    $entity = new UserAuthKeyEntity(
        authKey: 'ck98765',
        apikeySecret: 'ent-secret',
        userId: UserId::from(7),
        createdOn: SqlDateTime::from('2026-06-01 00:00:00'),
        duration: 60,
        expiredOn: SqlDateTime::from('2026-08-01 00:00:00'),
        apikeyName: 'Entity Key',
        keyType: 'session',
        revokedOn: '2026-07-10 00:00:00',
        lastUsedOn: '2026-07-05 00:00:00',
        lastNotifiedOn: '2026-07-06 00:00:00',
    );
    $entity->authKeyId = AuthKeyId::from(9);

    $apiKey = ApiKey::fromEntity($entity);

    expect($apiKey->authKeyId)
        ->toBe(9);
    expect($apiKey->authKey)
        ->toBe('ck98765');
    expect($apiKey->apikeySecret)
        ->toBe('ent-secret');
    expect($apiKey->userId)
        ->toEqual(UserId::from(7));
    expect($apiKey->duration)
        ->toBe(60);
    expect($apiKey->keyType)
        ->toBe('session');
    expect($apiKey->revokedOn)
        ->toBe('2026-07-10 00:00:00');
});

test('fromEntity defaults authKeyId to 0 when the entity has none yet', function (): void {
    $entity = new UserAuthKeyEntity(
        authKey: 'ck00000',
        apikeySecret: null,
        userId: UserId::from(1),
        createdOn: SqlDateTime::from('2026-06-01 00:00:00'),
        duration: null,
        expiredOn: SqlDateTime::from('2026-08-01 00:00:00'),
        apikeyName: null,
        keyType: null,
    );

    expect(ApiKey::fromEntity($entity)->authKeyId)->toBe(0);
});

test('toArray round-trips every field', function (): void {
    $apiKey = new ApiKey(
        authKeyId: 1,
        authKey: 'ck1',
        apikeySecret: 'sec',
        apikeyName: 'name',
        userId: UserId::from(2),
        createdOn: '2026-01-01 00:00:00',
        duration: 10,
        expiredOn: '2026-02-01 00:00:00',
        keyType: 'api',
        revokedOn: null,
        lastUsedOn: null,
        lastNotifiedOn: null,
    );

    expect($apiKey->toArray())
        ->toBe([
            'auth_key_id' => 1,
            'auth_key' => 'ck1',
            'apikey_secret' => 'sec',
            'apikey_name' => 'name',
            'user_id' => 2,
            'created_on' => '2026-01-01 00:00:00',
            'duration' => 10,
            'expired_on' => '2026-02-01 00:00:00',
            'key_type' => 'api',
            'revoked_on' => null,
            'last_used_on' => null,
            'last_notified_on' => null,
        ]);
});
