<?php

declare(strict_types=1);

use Piwigo\Admin\LoadedPlugins;

/**
 * Piwigo\Admin\LoadedPlugins -- a per-request static singleton (no test
 * file existed before this one). reset() is test-only (arch-test
 * restricted to tests/), used here to isolate each test from whatever
 * state a previous test (or PluginLoader::loadPlugins()) left behind.
 */
beforeEach(function (): void {
    LoadedPlugins::reset();
});

afterEach(function (): void {
    LoadedPlugins::reset();
});

test('get() throws before anything has ever been initialised', function (): void {
    expect(LoadedPlugins::isInitialized())->toBeFalse();
    expect(fn () => LoadedPlugins::get())
        ->toThrow(LogicException::class, 'LoadedPlugins not initialised -- call Piwigo\Admin\PluginLoader::loadPlugins() first.');
});

test('set() initialises the map and get()/isInitialized() reflect it', function (): void {
    $plugins = [
        'plugin-a' => ['id' => 'plugin-a', 'state' => 'active', 'version' => '1.0'],
        'plugin-b' => ['id' => 'plugin-b', 'state' => 'inactive', 'version' => '2.0'],
    ];

    LoadedPlugins::set($plugins);

    expect(LoadedPlugins::isInitialized())->toBeTrue();
    expect(LoadedPlugins::get())->toBe($plugins);
});

test('set() to an empty array still counts as initialised, distinct from never-initialised', function (): void {
    LoadedPlugins::set([]);

    expect(LoadedPlugins::isInitialized())->toBeTrue();
    expect(LoadedPlugins::get())->toBe([]);
});

test('add() lazily initialises an empty map when none exists yet', function (): void {
    expect(LoadedPlugins::isInitialized())->toBeFalse();

    LoadedPlugins::add('solo-plugin', ['id' => 'solo-plugin', 'state' => 'active', 'version' => '3.1']);

    expect(LoadedPlugins::isInitialized())->toBeTrue();
    expect(LoadedPlugins::get())->toBe([
        'solo-plugin' => ['id' => 'solo-plugin', 'state' => 'active', 'version' => '3.1'],
    ]);
});

test('add() appends to an already-initialised map without clobbering existing entries', function (): void {
    LoadedPlugins::set(['first-plugin' => ['id' => 'first-plugin', 'state' => 'active', 'version' => '1.0']]);

    LoadedPlugins::add('second-plugin', ['id' => 'second-plugin', 'state' => 'inactive', 'version' => '0.5']);

    expect(LoadedPlugins::get())->toBe([
        'first-plugin' => ['id' => 'first-plugin', 'state' => 'active', 'version' => '1.0'],
        'second-plugin' => ['id' => 'second-plugin', 'state' => 'inactive', 'version' => '0.5'],
    ]);
});

test('reset() returns to the never-initialised state', function (): void {
    LoadedPlugins::set(['x' => ['id' => 'x', 'state' => 'active', 'version' => '1.0']]);
    expect(LoadedPlugins::isInitialized())->toBeTrue();

    LoadedPlugins::reset();

    expect(LoadedPlugins::isInitialized())->toBeFalse();
    expect(fn () => LoadedPlugins::get())->toThrow(LogicException::class);
});
