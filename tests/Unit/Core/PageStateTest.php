<?php

declare(strict_types=1);

use Piwigo\Core\PageState;

beforeEach(function (): void {
    PageState::reset();
});

afterEach(function (): void {
    PageState::reset();
});

test('current lazily creates an empty instance before attachGlobals runs', function (): void {
    $state = PageState::current();

    expect($state->errors)->toBe([])
        ->and($state->hasErrors())->toBeFalse();
});

test('add* methods accumulate into the singleton', function (): void {
    $state = PageState::current();
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

test('setMetaRobots replaces the whole map, setMetaRobotsFlag adds to it', function (): void {
    $state = PageState::current();
    $state->setMetaRobots(['noindex' => 1]);
    $state->setMetaRobotsFlag('nofollow');

    expect($state->metaRobots)->toBe(['noindex' => 1, 'nofollow' => 1]);

    $state->setMetaRobots(['noindex' => 1, 'nofollow' => 1]);

    expect($state->metaRobots)->toBe(['noindex' => 1, 'nofollow' => 1]);
});

test('setAuthKeyId sets and clears the current auth key id', function (): void {
    $state = PageState::current();

    expect($state->authKeyId)->toBeNull();

    $state->setAuthKeyId(42);

    expect($state->authKeyId)->toBe(42);

    $state->setAuthKeyId(null);

    expect($state->authKeyId)->toBeNull();
});

test('addQueryTime accumulates count and time, resetQueryCounters zeroes both', function (): void {
    $state = PageState::current();
    $state->addQueryTime(0.5);
    $state->addQueryTime(0.25);

    expect($state->countQueries)->toBe(2)
        ->and($state->queriesTime)->toBe(0.75);

    $state->resetQueryCounters();

    expect($state->countQueries)->toBe(0)
        ->and($state->queriesTime)->toBe(0.0);
});

test('attachGlobals seeds the singleton like current(), idempotently', function (): void {
    PageState::attachGlobals();
    $state = PageState::current();
    $state->addError('bad thing');

    PageState::attachGlobals();

    expect(PageState::current())->toBe($state)
        ->and($state->errors)->toBe(['bad thing']);
});
