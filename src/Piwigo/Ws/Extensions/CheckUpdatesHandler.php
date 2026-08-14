<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
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
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{piwigo_need_update: bool|null, ext_need_update: bool|null}
     */
    public function __invoke(array $params, Server $server): array
    {
        $coreUpdateService = $this->coreUpdateService;
        $updateChecker = $this->extensionUpdateChecker;
        $result = [];

        if (! isset($_SESSION['need_update' . AppInfo::VERSION])) {
            $coreUpdateService->checkPiwigoUpgrade();
        }

        // CoreUpdateService::checkPiwigoUpgrade() only ever writes this
        // session key as null or a real bool (version_compare() result);
        // narrowed defensively since it's still a round-trip through
        // session state.
        $piwigo_need_update = $_SESSION['need_update' . AppInfo::VERSION] ?? null;
        $result['piwigo_need_update'] = is_bool($piwigo_need_update) ? $piwigo_need_update : null;

        if (! isset($_SESSION['extensions_need_update'])) {
            $updateChecker->checkExtensions();
        } else {
            $updateChecker->checkUpdatedExtensions();
        }

        if (! is_array($_SESSION['extensions_need_update'] ?? null)) {
            $result['ext_need_update'] = null;
        } else {
            $result['ext_need_update'] = $_SESSION['extensions_need_update'] !== [];
        }

        return $result;
    }
}
