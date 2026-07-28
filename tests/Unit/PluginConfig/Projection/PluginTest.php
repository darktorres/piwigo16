<?php

declare(strict_types=1);

use Piwigo\PluginConfig\Projection\Plugin;

/**
 * Piwigo\PluginConfig\Projection\Plugin -- fromRow()/toArray() are unused
 * in production since the DBAL->ORM migration (kept only for parity with
 * the Projection convention, per this class's own docblock), but both are
 * real, directly-callable public methods -- tested here for completeness
 * rather than left as untested dead weight (see /home/torres/.claude/
 * plans/piped-enchanting-spark.md, Wave 1).
 */
test('fromRow narrows a real DB row into typed properties', function (): void {
    $plugin = Plugin::fromRow(['id' => 'my_plugin', 'state' => 'active', 'version' => '1.2.3']);

    expect($plugin->id)->toBe('my_plugin');
    expect($plugin->state)->toBe('active');
    expect($plugin->version)->toBe('1.2.3');
});

test('fromRow falls back to safe defaults for missing/non-string fields', function (): void {
    $plugin = Plugin::fromRow(['id' => 123, 'version' => null]);

    expect($plugin->id)->toBe('');
    expect($plugin->state)->toBe('inactive');
    expect($plugin->version)->toBe('0');
});

test('toArray round-trips the 3 typed properties', function (): void {
    $plugin = new Plugin('my_plugin', 'active', '1.2.3');

    expect($plugin->toArray())->toBe([
        'id' => 'my_plugin',
        'state' => 'active',
        'version' => '1.2.3',
    ]);
});
