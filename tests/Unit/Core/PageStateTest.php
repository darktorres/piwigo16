<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\PageStateTestFactory;

// Container-shared instance -- each test constructs its own fresh instance
// directly; no reset() needed.

afterEach(function (): void {
    Kernel::reset();
});

test('a fresh instance starts with no errors', function (): void {
    $state = new PageState();

    expect($state->errors)
        ->toBe([])
        ->and($state->hasErrors())
        ->toBeFalse();
});

test('add* methods accumulate on the instance', function (): void {
    $state = new PageState();
    $state->addError('bad thing');
    $state->addWarning('careful');
    $state->addMessage('saved');
    $state->addInfo('fyi');
    $state->setBodyData('category_id', 5);

    expect($state->errors)
        ->toBe(['bad thing'])
        ->and($state->warnings)
        ->toBe(['careful'])
        ->and($state->messages)
        ->toBe(['saved'])
        ->and($state->infos)
        ->toBe(['fyi'])
        ->and($state->bodyData)
        ->toBe([
            'category_id' => 5,
        ])
        ->and($state->hasErrors())
        ->toBeTrue();
});

test('setAuthKeyId sets and clears the current auth key id', function (): void {
    $state = new PageState();

    expect($state->authKeyId)
        ->toBeNull();

    $state->setAuthKeyId(42);

    expect($state->authKeyId)
        ->toBe(42);

    $state->setAuthKeyId(null);

    expect($state->authKeyId)
        ->toBeNull();
});

test('setUpdatedVersion/markAuthKeyInvalid set their respective fields', function (): void {
    $state = new PageState();

    expect($state->updatedVersion)
        ->toBeNull()
        ->and($state->authKeyInvalid)
        ->toBeFalse();

    $state->setUpdatedVersion('17.1.0');
    $state->markAuthKeyInvalid();

    expect($state->updatedVersion)
        ->toBe('17.1.0')
        ->and($state->authKeyInvalid)
        ->toBeTrue();
});

test('exposeData/exposeString accumulate, keyed by their own key and dedup on repeat', function (): void {
    $state = new PageState();
    $state->exposeData('csrf_token', 'abc123');
    $state->exposeData('nb_albums', 12);
    $state->exposeData('nb_albums', 13);
    $state->exposeString('Please wait...');
    $state->exposeString('Please wait...');
    $state->exposeString('Loading');

    expect($state->exposedData)
        ->toBe([
            'csrf_token' => 'abc123',
            'nb_albums' => 13,
        ])
        ->and($state->exposedStringKeys)
        ->toBe([
            'Please wait...' => true,
            'Loading' => true,
        ]);
});

test('reset clears every property back to its constructed default', function (): void {
    $state = new PageState();
    $state->addError('bad thing');
    $state->addWarning('careful');
    $state->addMessage('saved');
    $state->addInfo('fyi');
    $state->setBodyData('category_id', 5);
    $state->exposeData('csrf_token', 'abc123');
    $state->exposeString('Loading');
    $state->setAuthKeyId(42);
    $state->setNbPendingComments(3);
    $state->setNoMd5sumNumber(2);
    $state->setNbOrphans(1);
    $state->setNbPhotosTotal(100);
    $state->setUpdatedVersion('17.1.0');
    $state->markAuthKeyInvalid();
    $state->setNotifyApiKeyExpiration([
        'days_left' => 3,
        'dbnow' => '2026-08-18',
        'auth_key' => 'key',
    ]);
    $state->addCommentRejectionReason('spam');

    $state->reset();

    $fresh = new PageState();

    expect($state->errors)
        ->toBe($fresh->errors)
        ->and($state->warnings)
        ->toBe($fresh->warnings)
        ->and($state->messages)
        ->toBe($fresh->messages)
        ->and($state->infos)
        ->toBe($fresh->infos)
        ->and($state->bodyData)
        ->toBe($fresh->bodyData)
        ->and($state->exposedData)
        ->toBe($fresh->exposedData)
        ->and($state->exposedStringKeys)
        ->toBe($fresh->exposedStringKeys)
        ->and($state->authKeyId)
        ->toBe($fresh->authKeyId)
        ->and($state->nbPendingComments)
        ->toBe($fresh->nbPendingComments)
        ->and($state->noMd5sumNumber)
        ->toBe($fresh->noMd5sumNumber)
        ->and($state->nbOrphans)
        ->toBe($fresh->nbOrphans)
        ->and($state->nbPhotosTotal)
        ->toBe($fresh->nbPhotosTotal)
        ->and($state->updatedVersion)
        ->toBe($fresh->updatedVersion)
        ->and($state->authKeyInvalid)
        ->toBe($fresh->authKeyInvalid)
        ->and($state->notifyApiKeyExpiration)
        ->toBe($fresh->notifyApiKeyExpiration)
        ->and($state->commentRejectionReasons)
        ->toBe($fresh->commentRejectionReasons);
});

test('PageStateTestFactory::get falls back to a memoized instance when Kernel is not booted', function (): void {
    // Memoized (not fresh-per-call), same reasoning as CurrentUserTestFactory::get():
    // a caller that writes via current() in one call and reads via current() in a
    // later call must see the same instance, or the write would be lost.
    // reset() first: the memoized fallback is one real object shared by
    // every not-booted caller across the whole test process (other test
    // files' own NotificationByMailSubController-style shim calls can
    // leave prior errors on it), so this test must start from a clean
    // slate rather than assume it's the first ever caller.
    $first = PageStateTestFactory::get();
    $first->reset();
    $first->addError('bad thing');

    $second = PageStateTestFactory::get();

    expect($second)
        ->toBe($first)
        ->and($second->errors)
        ->toBe(['bad thing']);
});

test('PageStateTestFactory::get resolves the container-shared instance once Kernel is booted', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-page-state-test'));

    $instance = Kernel::container()->get(PageState::class);

    expect(PageStateTestFactory::get())->toBe($instance);
});
