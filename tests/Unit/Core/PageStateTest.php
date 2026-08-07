<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Core\Paths;

// Container-shared instance -- each test constructs its own fresh instance
// directly; no reset() needed.

afterEach(function (): void {
    Kernel::reset();
});

test('a fresh instance starts with no errors', function (): void {
    $state = new PageState();

    expect($state->errors)->toBe([])
        ->and($state->hasErrors())->toBeFalse();
});

test('add* methods accumulate on the instance', function (): void {
    $state = new PageState();
    $state->addError('bad thing');
    $state->addWarning('careful');
    $state->addMessage('saved');
    $state->addInfo('fyi');
    $state->addHeaderMessage('header msg');
    $state->addHeaderNote('header note');
    $state->addBodyClass('section-categories');
    $state->setBodyData('category_id', 5);

    expect($state->errors)->toBe(['bad thing'])
        ->and($state->warnings)->toBe(['careful'])
        ->and($state->messages)->toBe(['saved'])
        ->and($state->infos)->toBe(['fyi'])
        ->and($state->headerMessages)->toBe(['header msg'])
        ->and($state->headerNotes)->toBe(['header note'])
        ->and($state->bodyClasses)->toBe(['section-categories'])
        ->and($state->bodyData)->toBe(['category_id' => 5])
        ->and($state->hasErrors())->toBeTrue();
});

test('addDebugOutput appends to prior debug output rather than replacing it', function (): void {
    // Kills line 308's ConcatEqualToEqual (`=` instead of `.=`): a
    // single call can't distinguish append from overwrite (both start
    // from the same empty string) -- a second call is needed to prove
    // the first line survives.
    $state = new PageState();
    $state->addDebugOutput('first line');
    $state->addDebugOutput('second line');

    expect($state->debugOutput)->toBe('first linesecond line');
});

test('setMetaRobots replaces the whole map, setMetaRobotsFlag adds to it', function (): void {
    $state = new PageState();
    $state->setMetaRobots(['noindex' => 1]);
    $state->setMetaRobotsFlag('nofollow');

    expect($state->metaRobots)->toBe(['noindex' => 1, 'nofollow' => 1]);

    $state->setMetaRobots(['noindex' => 1, 'nofollow' => 1]);

    expect($state->metaRobots)->toBe(['noindex' => 1, 'nofollow' => 1]);
});

test('setAuthKeyId sets and clears the current auth key id', function (): void {
    $state = new PageState();

    expect($state->authKeyId)->toBeNull();

    $state->setAuthKeyId(42);

    expect($state->authKeyId)->toBe(42);

    $state->setAuthKeyId(null);

    expect($state->authKeyId)->toBeNull();
});

test('addQueryTime accumulates count and time', function (): void {
    $state = new PageState();
    $state->addQueryTime(0.5);
    $state->addQueryTime(0.25);

    expect($state->countQueries)->toBe(2)
        ->and($state->queriesTime)->toBe(0.75);
});

test('setUpdatedVersion/markAuthKeyInvalid set their respective fields', function (): void {
    $state = new PageState();

    expect($state->updatedVersion)->toBeNull()
        ->and($state->authKeyInvalid)->toBeFalse();

    $state->setUpdatedVersion('17.1.0');
    $state->markAuthKeyInvalid();

    expect($state->updatedVersion)->toBe('17.1.0')
        ->and($state->authKeyInvalid)->toBeTrue();
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

    expect($second)->toBe($first)
        ->and($second->errors)->toBe(['bad thing']);
});

test('PageStateTestFactory::get resolves the container-shared instance once Kernel is booted', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-page-state-test'));

    $instance = Kernel::container()->get(PageState::class);

    expect(PageStateTestFactory::get())->toBe($instance);
});
