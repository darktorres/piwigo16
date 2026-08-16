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
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Core\AppInfo;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.extensions.checkUpdates` -- checks if piwigo or extensions are up to date.
 */
final readonly class CheckUpdatesHandler implements WsAction
{
    public function __construct(
        private CoreUpdateService $coreUpdateService,
        private ExtensionUpdateChecker $extensionUpdateChecker,
        private ExtensionUpdateCachePool $extensionUpdateCachePool,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{piwigo_need_update: bool|null, ext_need_update: bool|null}
     */
    #[Override]
    public function __invoke(array $params, Server $server): array
    {
        $coreUpdateService = $this->coreUpdateService;
        $updateChecker = $this->extensionUpdateChecker;
        $pool = $this->extensionUpdateCachePool;
        $result = [];

        $coreKey = 'core_need_update_' . AppInfo::VERSION;
        if (! $pool->getItem($coreKey)->isHit()) {
            $coreUpdateService->checkPiwigoUpgrade();
        }

        // CoreUpdateService::checkPiwigoUpgrade() only ever writes this
        // cache item as null or a real bool (version_compare() result);
        // narrowed defensively since it's still a round-trip through
        // cache state. Re-fetched: checkPiwigoUpgrade() may just have
        // written it, and a PSR-6 item is a snapshot, not a live view.
        $piwigo_need_update = $pool->getItem($coreKey)
            ->get();
        $result['piwigo_need_update'] = is_bool($piwigo_need_update) ? $piwigo_need_update : null;

        $extKey = 'extensions_need_update';
        if (! $pool->getItem($extKey)->isHit()) {
            $updateChecker->checkExtensions();
        } else {
            $updateChecker->checkUpdatedExtensions();
        }

        $extNeedUpdate = $pool->getItem($extKey)
            ->get();
        if (! is_array($extNeedUpdate)) {
            $result['ext_need_update'] = null;
        } else {
            $result['ext_need_update'] = $extNeedUpdate !== [];
        }

        return $result;
    }
}
