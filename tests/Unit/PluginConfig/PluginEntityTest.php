<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\PluginId;
use Piwigo\PluginConfig\PluginEntity;
use Piwigo\PluginConfig\PluginState;

test('constructs with distinct values for every property', function (): void {
    $plugin = new PluginEntity(id: PluginId::from('adminusertags'), state: PluginState::Active, version: '2.3.1');

    expect($plugin->id)->toEqual(PluginId::from('adminusertags'))
        ->and($plugin->state)->toBe(PluginState::Active)
        ->and($plugin->version)->toBe('2.3.1');
});
