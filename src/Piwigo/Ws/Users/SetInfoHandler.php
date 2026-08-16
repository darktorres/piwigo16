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
use Piwigo\Users\UserInfoUpdateFailureReason;
use Piwigo\Users\UserInfoUpdateInput;
use Piwigo\Users\UserService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.setInfo` -- admin only. Updates a user. Leave a field blank to keep the current value.
 */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private UserService $userService,
        private PageState $pageState,
        private WsCsrfGuard $wsCsrfGuard,
        private GetListHandler $getListHandler,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<int|string, mixed> the result of
     *   GetListHandler::resolve(), called directly (P25 Stage 1's
     *   recursive-dispatch removal)
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = SetInfoParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $result = $this->userService->checkAndSaveUserInfos(new UserInfoUpdateInput(
            userIds: $input->userIds,
            username: $input->username,
            password: $input->password,
            email: $input->email,
            status: $input->status,
            level: $input->level,
            language: $input->language,
            theme: $input->theme,
            groupIds: $input->groupIds,
            nbImagePage: $input->nbImagePage,
            recentPeriod: $input->recentPeriod,
            expand: $input->expand,
            showNbComments: $input->showNbComments,
            showNbHits: $input->showNbHits,
            enabledHigh: $input->enabledHigh,
        ), $this->pageState);

        if ($result->isFailure) {
            assert($result->failureReason instanceof UserInfoUpdateFailureReason);
            $errorCode = match ($result->failureReason) {
                UserInfoUpdateFailureReason::Forbidden => 403,
                UserInfoUpdateFailureReason::InvalidInput => WsError::InvalidParam->value,
            };
            return new WsErrorResponse($errorCode, $result->failureMessage);
        }

        return $this->getListHandler->resolve([
            'user_id' => $result->userIds,
            'display' => 'basics,' . implode(',', array_keys($result->infos)),
        ]);
    }
}
