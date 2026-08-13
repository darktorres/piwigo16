<?php

declare(strict_types=1);

use Piwigo\Admin\LoadedPlugins;

/**
 * Piwigo\Admin\LoadedPlugins -- a container-shared instance. Each test
 * constructs its own fresh instance directly; no reset()/Kernel::boot()
 * needed since there's no shared static state to isolate between tests.
 */
test('get() throws before anything has ever been initialised', function (): void {
    $loadedPlugins = new LoadedPlugins();

    expect($loadedPlugins->isInitialized())
        ->toBeFalse();
    expect(fn (): array => $loadedPlugins->get())
        ->toThrow(LogicException::class, 'LoadedPlugins not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect() first.');
});

test('set() initialises the map and get()/isInitialized() reflect it', function (): void {
    $loadedPlugins = new LoadedPlugins();
    $plugins = [
        'plugin-a' => [
            'id' => 'plugin-a',
            'state' => 'active',
            'version' => '1.0',
        ],
        'plugin-b' => [
            'id' => 'plugin-b',
            'state' => 'inactive',
            'version' => '2.0',
        ],
    ];

    $loadedPlugins->set($plugins);

    expect($loadedPlugins->isInitialized())
        ->toBeTrue();
    expect($loadedPlugins->get())
        ->toBe($plugins);
});

test('set() to an empty array still counts as initialised, distinct from never-initialised', function (): void {
    $loadedPlugins = new LoadedPlugins();

    $loadedPlugins->set([]);

    expect($loadedPlugins->isInitialized())
        ->toBeTrue();
    expect($loadedPlugins->get())
        ->toBe([]);
});

test('add() lazily initialises an empty map when none exists yet', function (): void {
    $loadedPlugins = new LoadedPlugins();

    expect($loadedPlugins->isInitialized())
        ->toBeFalse();

    $loadedPlugins->add('solo-plugin', [
        'id' => 'solo-plugin',
        'state' => 'active',
        'version' => '3.1',
    ]);

    expect($loadedPlugins->isInitialized())
        ->toBeTrue();
    expect($loadedPlugins->get())
        ->toBe([
            'solo-plugin' => [
                'id' => 'solo-plugin',
                'state' => 'active',
                'version' => '3.1',
            ],
        ]);
});

test('add() appends to an already-initialised map without clobbering existing entries', function (): void {
    $loadedPlugins = new LoadedPlugins();

    $loadedPlugins->set([
        'first-plugin' => [
            'id' => 'first-plugin',
            'state' => 'active',
            'version' => '1.0',
        ],
    ]);

    $loadedPlugins->add('second-plugin', [
        'id' => 'second-plugin',
        'state' => 'inactive',
        'version' => '0.5',
    ]);

    expect($loadedPlugins->get())
        ->toBe([
            'first-plugin' => [
                'id' => 'first-plugin',
                'state' => 'active',
                'version' => '1.0',
            ],
            'second-plugin' => [
                'id' => 'second-plugin',
                'state' => 'inactive',
                'version' => '0.5',
            ],
        ]);
});

test('reset() returns to the never-initialised state', function (): void {
    $loadedPlugins = new LoadedPlugins();
    $loadedPlugins->set([
        'x' => [
            'id' => 'x',
            'state' => 'active',
            'version' => '1.0',
        ],
    ]);
    expect($loadedPlugins->isInitialized())
        ->toBeTrue();

    $loadedPlugins->reset();

    expect($loadedPlugins->isInitialized())
        ->toBeFalse();
    expect(fn (): array => $loadedPlugins->get())
        ->toThrow(LogicException::class);
});
