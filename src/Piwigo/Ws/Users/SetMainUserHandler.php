<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.setMainUser` -- admin only, webmaster only. Sets a user as
 * the main user.
 */
final readonly class SetMainUserHandler implements WsAction
{
    public function __construct(
        private AccessControl $accessControl,
        private UserService $userService,
        private ConfigService $configService,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|string
    {
        // check if not webmaster
        if (! $this->accessControl->isWebmaster()) {
            return new WsErrorResponse(403, 'You cannot perform this action');
        }

        $input = SetMainUserParams::fromArray($params);

        // check pwg_token
        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $new_main_user_id = UserId::from($input->userId);

        // checl if user exist
        if (! $this->userService->getUsername($new_main_user_id) instanceof Username) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'This user does not exist.');
        }

        $new_main_user = $this->userService->getUserData($new_main_user_id);

        // check if the user to set as main user is not webmaster
        if ($new_main_user['status'] !== 'webmaster') {
            return new WsErrorResponse(403, 'This user cannot become a main user because he is not a webmaster.');
        }

        $this->configService->confUpdateParam('webmaster_id', $input->userId);
        return 'The main user has been changed.';
    }
}
