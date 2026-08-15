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
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\WsError;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserInfoUpdateFailureReason;
use Piwigo\Users\UserInfoUpdateInput;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.setMyInfo` -- lets a logged-in (non-guest) user update their own info.
 */
final readonly class SetMyInfoHandler implements WsAction
{
    public function __construct(
        private UserService $userService,
        private AuthService $authService,
        private AccessControl $accessControl,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private Lang $lang,
        private PageState $pageState,
        private PasswordService $passwordService,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|string
    {
        $input = SetMyInfoParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(401, 'Access Denied');
        }

        $currentUser = $this->currentUser->get();

        $theme = $input->theme;
        $language = $input->language;
        $nbImagePage = $input->nbImagePage;
        $recentPeriod = $input->recentPeriod;
        $expand = $input->expand;
        $showNbComments = $input->showNbComments;
        $showNbHits = $input->showNbHits;
        $password = $input->password;

        // ACTIVATE_COMMENTS
        if (! $this->currentConfig->activateComments) {
            $showNbComments = null;
        }

        // ALLOW_USER_CUSTOMIZATION
        if (! $this->currentConfig->allowUserCustomization) {
            $nbImagePage = null;
            $theme = null;
            $language = null;
            $recentPeriod = null;
            $expand = null;
            $showNbComments = null;
            $showNbHits = null;
        }

        // SPECIAL_USER
        $special_user = in_array($currentUser->id->value, [$this->currentConfig->guestId, $this->currentConfig->defaultUserId], true);
        if ($special_user) {
            $password = null;
            $theme = null;
            $language = null;
        }

        if ($password !== null && $password !== '') {
            if (($input->newPassword ?? '') !== ($input->confNewPassword ?? '')) {
                return new WsErrorResponse(403, $this->lang->t('The passwords do not match'));
            }

            $current_password = $this->authService->getPasswordHash($currentUser->id);
            $current_password ??= '';

            if (! $this->passwordService->verify($password, $current_password)) {
                return new WsErrorResponse(403, $this->lang->t('Current password is wrong'));
            }

            $password = $input->newPassword;
        }

        // username/status/level/groupIds/enabledHigh are never populated
        // below -- pwg.users.setMyInfo doesn't register those fields at
        // all, so SetMyInfoParams never even reads them, the same
        // allowlist effect the god-class method's own unconditional
        // unset() achieved by hand.
        $result = $this->userService->checkAndSaveUserInfos(new UserInfoUpdateInput(
            userIds: [$currentUser->id->value],
            password: $password,
            email: $input->email,
            language: $language,
            theme: $theme,
            nbImagePage: $nbImagePage,
            recentPeriod: $recentPeriod,
            expand: $expand,
            showNbComments: $showNbComments,
            showNbHits: $showNbHits,
        ), $this->pageState);

        if ($result->isFailure) {
            assert($result->failureReason instanceof UserInfoUpdateFailureReason);
            $errorCode = match ($result->failureReason) {
                UserInfoUpdateFailureReason::Forbidden => 403,
                UserInfoUpdateFailureReason::InvalidInput => WsError::InvalidParam->value,
            };
            return new WsErrorResponse($errorCode, $result->failureMessage);
        }

        return $this->lang->t('Your changes have been applied.');
    }
}
