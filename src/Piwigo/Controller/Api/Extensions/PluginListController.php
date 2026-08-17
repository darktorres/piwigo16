<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Extensions;

use Override;
use Piwigo\Admin\Extensions\PluginListBuilder;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/plugins` -- `pwg.plugins.getList`'s real replacement,
 * admin only. The actual filesystem-scan + DB-state merge lives in
 * `Admin\Extensions\PluginListBuilder`, shared with the Maintenance ->
 * Environment admin screen's server-rendered plugin list.
 */
final readonly class PluginListController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private PluginListBuilder $pluginListBuilder,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        return ResponseFactory::json([
            'plugins' => $this->pluginListBuilder->build(),
        ]);
    }
}
