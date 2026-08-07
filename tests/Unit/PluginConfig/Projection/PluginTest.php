<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\PluginId;
use Piwigo\PluginConfig\Projection\Plugin;

/**
 * Piwigo\PluginConfig\Projection\Plugin -- no fromRow() (dead code deleted
 * in Phase 4: PluginRepository::getDbPlugins() has built this straight
 * from PluginEntity's already-typed properties since the DBAL->ORM
 * migration, per this class's own docblock).
 */
test('toArray round-trips the 3 typed properties', function (): void {
    $plugin = new Plugin(PluginId::from('my_plugin'), 'active', '1.2.3');

    expect($plugin->toArray())->toBe([
        'id' => 'my_plugin',
        'state' => 'active',
        'version' => '1.2.3',
    ]);
});
