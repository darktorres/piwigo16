<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Session;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Csrf\CsrfService;
use Piwigo\History\HistoryService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.session.getStatus` -- gets information about the current session. Also provides a token useable with admin methods.
 */
final readonly class GetStatusHandler implements WsAction
{
    public function __construct(
        private CurrentUser $currentUser,
        private AccessControl $accessControl,
        private CurrentConfig $currentConfig,
        private HistoryService $historyService,
        private ImageStdParams $imageStdParams,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array<string, mixed>
     */
    #[Override]
    public function __invoke(array $params, Server $server): array
    {
        $currentUser = $this->currentUser->get();
        $res = [];
        $res['username'] = $this->accessControl->isAGuest() ? 'guest' : stripslashes($currentUser->username->value ?? '');
        $res['status'] = $currentUser->status->value;
        $res['theme'] = $currentUser->theme->value;
        $res['language'] = $currentUser->language->value;
        $res['pwg_token'] = new CsrfService($this->currentConfig)->getToken();
        $res['charset'] = 'utf-8';

        // Env::now() (not SQL's NOW()) so this value can be frozen by
        // PIWIGO_TEST_NOW in tests -- SQL's NOW() reads the real,
        // unfreezable DB-server clock.
        $res['current_datetime'] = Env::now()->format('Y-m-d H:i:s');
        $res['version'] = AppInfo::VERSION;
        $res['save_visits'] = $this->historyService->isLoggingAllowed();
        $res['connected_with'] = $_SESSION['connected_with'] ?? null;

        // Piwigo Remote Sync does not support receiving the new (version 14) output "save_visits"
        $http_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (is_string($http_user_agent) and (bool) preg_match('/^PiwigoRemoteSync/', $http_user_agent)) {
            unset($res['save_visits']);
            unset($res['connected_with']);
        }

        // Piwigo Remote Sync does not support receiving the available sizes
        $piwigo_remote_sync_agent = 'Apache-HttpClient/';
        if (! is_string($http_user_agent) or ! str_starts_with($http_user_agent, $piwigo_remote_sync_agent)) {
            $res['available_sizes'] = array_keys($this->imageStdParams->getDefinedTypeMap());
        }

        if ($this->accessControl->isAdmin()) {
            $upload_ext_list = ($this->currentConfig->uploadFormAllTypes) ? $this->currentConfig->fileExtensions : $this->currentConfig->pictureExtensions;

            $res['upload_file_types'] = implode(
                ',',
                array_unique(
                    array_map(
                        strtolower(...),
                        $upload_ext_list
                    )
                )
            );

            $chunk_size = $this->currentConfig->uploadFormChunkSize;
            $res['upload_form_chunk_size'] = $chunk_size;
        }

        return $res;
    }
}
