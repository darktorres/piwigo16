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

test('addDebugOutput appends to prior debug output rather than replacing it', function (): void {
    // Kills line 308's ConcatEqualToEqual (`=` instead of `.=`): a
    // single call can't distinguish append from overwrite (both start
    // from the same empty string) -- a second call is needed to prove
    // the first line survives.
    $state = PageState::current();
    $state->addDebugOutput('first line');
    $state->addDebugOutput('second line');

    expect($state->debugOutput)->toBe('first linesecond line');
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

test('addQueryTime accumulates count and time', function (): void {
    $state = PageState::current();
    $state->addQueryTime(0.5);
    $state->addQueryTime(0.25);

    expect($state->countQueries)->toBe(2)
        ->and($state->queriesTime)->toBe(0.75);
});

test('setUpdatedVersion/markAuthKeyInvalid set their respective fields', function (): void {
    $state = PageState::current();

    expect($state->updatedVersion)->toBeNull()
        ->and($state->authKeyInvalid)->toBeFalse();

    $state->setUpdatedVersion('17.1.0');
    $state->markAuthKeyInvalid();

    expect($state->updatedVersion)->toBe('17.1.0')
        ->and($state->authKeyInvalid)->toBeTrue();
});

test('attachGlobals seeds the singleton like current(), idempotently', function (): void {
    PageState::attachGlobals();
    $state = PageState::current();
    $state->addError('bad thing');

    PageState::attachGlobals();

    expect(PageState::current())->toBe($state)
        ->and($state->errors)->toBe(['bad thing']);
});
