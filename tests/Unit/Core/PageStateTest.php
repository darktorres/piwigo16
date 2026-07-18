<?php

declare(strict_types=1);

use Piwigo\Core\PageState;

beforeEach(function (): void {
    PageState::reset();
    unset($GLOBALS['page'], $GLOBALS['header_msgs'], $GLOBALS['header_notes']);
});

afterEach(function (): void {
    PageState::reset();
    unset($GLOBALS['page'], $GLOBALS['header_msgs'], $GLOBALS['header_notes']);
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

test('attachGlobals seeds from an already-populated $GLOBALS[page]', function (): void {
    $GLOBALS['page'] = [
        'errors' => ['pre-existing error'],
        'body_data' => ['section' => 'categories'],
        'execution_uuid' => 'abc123',
        'meta_robots' => ['noindex' => 1],
    ];
    $GLOBALS['header_msgs'] = ['upgrade needed'];

    PageState::attachGlobals();
    $state = PageState::current();

    expect($state->errors)->toBe(['pre-existing error'])
        ->and($state->bodyData)->toBe(['section' => 'categories'])
        ->and($state->executionUuid)->toBe('abc123')
        ->and($state->metaRobots)->toBe(['noindex' => 1])
        ->and($state->headerMessages)->toBe(['upgrade needed']);
});

test('attachGlobals bridges $GLOBALS[page] so legacy array writes are visible on the typed side', function (): void {
    PageState::attachGlobals();
    $state = PageState::current();

    $GLOBALS['page']['errors'][] = 'legacy push';

    expect($state->errors)->toBe(['legacy push']);
});

test('attachGlobals bridges the typed side so add* calls are visible on $GLOBALS[page]', function (): void {
    PageState::attachGlobals();
    $state = PageState::current();

    $state->addWarning('typed push');
    $state->setMetaRobotsFlag('noindex');

    expect($GLOBALS['page']['warnings'])->toBe(['typed push'])
        ->and($GLOBALS['page']['meta_robots'])->toBe(['noindex' => 1]);
});

test('attachGlobals bridges $GLOBALS[header_msgs] and $GLOBALS[header_notes] independently of $page', function (): void {
    PageState::attachGlobals();
    $state = PageState::current();

    $GLOBALS['header_msgs'][] = 'legacy header msg';
    $state->addHeaderNote('typed header note');

    expect($state->headerMessages)->toBe(['legacy header msg'])
        ->and($GLOBALS['header_notes'])->toBe(['typed header note']);
});
