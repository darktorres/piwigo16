<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Override;
use Piwigo\Admin\Extensions\PluginListBuilder;
use Piwigo\Ws\WsAction;

/**
 * `pwg.plugins.getList` -- returns the list of all plugins. The actual
 * filesystem-scan + DB-state merge lives in
 * {@see \Piwigo\Admin\Extensions\PluginListBuilder}, shared with the
 * Maintenance -> Environment admin screen's server-rendered plugin list
 * (P26.1).
 */
final readonly class PluginsGetListHandler implements WsAction
{
    public function __construct(
        private PluginListBuilder $pluginListBuilder,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return list<array{id: string, name: string, version: string, state: string, description: string}>
     */
    #[Override]
    public function __invoke(array $params): array
    {
        return $this->pluginListBuilder->build();
    }
}
