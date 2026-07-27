<?php

declare(strict_types=1);

use Piwigo\PluginConfig\PluginEntity;

test('constructs with distinct values for every property', function (): void {
    $plugin = new PluginEntity(id: 'adminusertags', state: 'active', version: '2.3.1');

    expect($plugin->id)->toBe('adminusertags')
        ->and($plugin->state)->toBe('active')
        ->and($plugin->version)->toBe('2.3.1');
});
