<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Override;
use Piwigo\Core\PageState;
use Piwigo\Core\WsError;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.users.setInfo` -- admin only. Updates a user. Leave a field blank to keep the current value.
 */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private UserService $userService,
        private PageState $pageState,
        private WsHelper $wsHelper,
        private GetListHandler $getListHandler,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<int|string, mixed> the result of
     *   GetListHandler::resolve(), called directly (P25 Stage 1's
     *   recursive-dispatch removal)
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = SetInfoParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $updated_users = $this->userService->checkAndSaveUserInfos($params, $this->pageState);

        if (isset($updated_users['error'])) {
            // UserService::checkAndSaveUserInfos() is declared to return plain
            // `array`; its error branches always
            // populate error.code (int) and error.message (string), but that
            // shape isn't statically expressed, so narrow defensively here
            // rather than trust the mixed offsets.
            $error = $updated_users['error'];
            $error_code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : WsError::InvalidParam->value;
            $error_message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Invalid parameters';
            return new WsErrorResponse($error_code, $error_message);
        }

        $updated_infos = is_array($updated_users['infos'] ?? null) ? $updated_users['infos'] : [];

        return $this->getListHandler->resolve([
            'user_id' => $updated_users['user_id'],
            'display' => 'basics,' . implode(',', array_keys($updated_infos)),
        ]);
    }
}
