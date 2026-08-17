<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Extensions;

use Override;
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Core\AppInfo;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/extensions/updates` -- `pwg.extensions.checkUpdates`'s
 * real replacement, admin only.
 */
final readonly class CheckUpdatesController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CoreUpdateService $coreUpdateService,
        private ExtensionUpdateChecker $extensionUpdateChecker,
        private ExtensionUpdateCachePool $extensionUpdateCachePool,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $pool = $this->extensionUpdateCachePool;

        $coreKey = 'core_need_update_' . AppInfo::VERSION;
        if (! $pool->getItem($coreKey)->isHit()) {
            $this->coreUpdateService->checkPiwigoUpgrade();
        }
        $piwigoNeedUpdate = $pool->getItem($coreKey)
            ->get();

        $extKey = 'extensions_need_update';
        if (! $pool->getItem($extKey)->isHit()) {
            $this->extensionUpdateChecker->checkExtensions();
        } else {
            $this->extensionUpdateChecker->checkUpdatedExtensions();
        }
        $extNeedUpdate = $pool->getItem($extKey)
            ->get();

        return ResponseFactory::json([
            'piwigoNeedUpdate' => is_bool($piwigoNeedUpdate) ? $piwigoNeedUpdate : null,
            'extNeedUpdate' => is_array($extNeedUpdate) ? $extNeedUpdate !== [] : null,
        ]);
    }
}
