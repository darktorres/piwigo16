<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\LayoutState;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\LayoutStateTestFactory;

// Container-shared instance -- each test constructs its own fresh instance
// directly; no reset() needed.

afterEach(function (): void {
    Kernel::reset();
});

test('a fresh instance starts empty', function (): void {
    $state = new LayoutState();

    expect($state->bodyClasses)
        ->toBe([])
        ->and($state->bodyId)
        ->toBe('')
        ->and($state->pageBanner)
        ->toBeNull()
        ->and($state->metaRobots)
        ->toBe([])
        ->and($state->headerNotes)
        ->toBe([])
        ->and($state->headerMessages)
        ->toBe([]);
});

test('add* methods accumulate on the instance', function (): void {
    $state = new LayoutState();
    $state->addHeaderMessage('header msg');
    $state->addHeaderNote('header note');

    expect($state->headerMessages)
        ->toBe(['header msg'])
        ->and($state->headerNotes)
        ->toBe(['header note']);
});

test('setMetaRobots replaces the whole map, setMetaRobotsFlag adds to it', function (): void {
    $state = new LayoutState();
    $state->setMetaRobots([
        'noindex' => 1,
    ]);
    $state->setMetaRobotsFlag('nofollow');

    expect($state->metaRobots)
        ->toBe([
            'noindex' => 1,
            'nofollow' => 1,
        ]);

    $state->setMetaRobots([
        'noindex' => 1,
        'nofollow' => 1,
    ]);

    expect($state->metaRobots)
        ->toBe([
            'noindex' => 1,
            'nofollow' => 1,
        ]);
});

test('setBodyId/setPageBanner set their respective fields', function (): void {
    $state = new LayoutState();

    $state->setBodyId('theBody');
    $state->setPageBanner('My Gallery');

    expect($state->bodyId)
        ->toBe('theBody')
        ->and($state->pageBanner)
        ->toBe('My Gallery');
});

test('reset clears every property back to its constructed default', function (): void {
    $state = new LayoutState();
    $state->bodyClasses = ['section-categories'];
    $state->addHeaderMessage('header msg');
    $state->addHeaderNote('header note');
    $state->setMetaRobots([
        'noindex' => 1,
    ]);
    $state->setBodyId('theBody');
    $state->setPageBanner('My Gallery');

    $state->reset();

    $fresh = new LayoutState();

    expect($state->bodyClasses)
        ->toBe($fresh->bodyClasses)
        ->and($state->bodyId)
        ->toBe($fresh->bodyId)
        ->and($state->pageBanner)
        ->toBe($fresh->pageBanner)
        ->and($state->metaRobots)
        ->toBe($fresh->metaRobots)
        ->and($state->headerNotes)
        ->toBe($fresh->headerNotes)
        ->and($state->headerMessages)
        ->toBe($fresh->headerMessages);
});

test('LayoutStateTestFactory::get falls back to a memoized instance when Kernel is not booted', function (): void {
    // Memoized (not fresh-per-call), same reasoning as PageStateTestFactory::get():
    // a caller that writes via get() in one call and reads via get() in a
    // later call must see the same instance, or the write would be lost.
    $first = LayoutStateTestFactory::get();
    $first->reset();
    $first->addHeaderNote('a note');

    $second = LayoutStateTestFactory::get();

    expect($second)
        ->toBe($first)
        ->and($second->headerNotes)
        ->toBe(['a note']);
});

test('LayoutStateTestFactory::get resolves the container-shared instance once Kernel is booted', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-layout-state-test'));

    $instance = Kernel::container()->get(LayoutState::class);

    expect(LayoutStateTestFactory::get())->toBe($instance);
});
