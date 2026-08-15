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
use Piwigo\Csrf\CsrfService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
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
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|string
    {
        $input = SetMyInfoParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(401, 'Access Denied');
        }

        $currentUser = $this->currentUser->get();

        // ACTIVATE_COMMENTS
        if (! $this->currentConfig->activateComments) {
            unset($params['show_nb_comments']);
        }

        // ALLOW_USER_CUSTOMIZATION
        if (! $this->currentConfig->allowUserCustomization) {
            unset(
                $params['nb_image_page'],
                $params['theme'],
                $params['language'],
                $params['recent_period'],
                $params['expand'],
                $params['show_nb_comments'],
                $params['show_nb_hits']
            );
        }

        // SPECIAL_USER
        $special_user = in_array($currentUser->id->value, [$this->currentConfig->guestId, $this->currentConfig->defaultUserId], true);
        if ($special_user) {
            unset(
                $params['password'],
                $params['theme'],
                $params['language']
            );
        }

        if (isset($params['password']) && $params['password'] !== '') {
            if (($params['new_password'] ?? '') !== ($params['conf_new_password'] ?? '')) {
                return new WsErrorResponse(403, $this->lang->t('The passwords do not match'));
            }

            $current_password = $this->authService->getPasswordHash($currentUser->id);
            $current_password ??= '';

            // $params['password'] survived the isset()/!=='' check above, but
            // the conditional unset($params['password']) in the SPECIAL_USER
            // branch means PHPStan can't keep that offset's type precise
            // after the merge, so it's read back as mixed here.
            $params_password = is_string($params['password']) ? $params['password'] : '';

            if (! $this->passwordService->verify($params_password, $current_password)) {
                return new WsErrorResponse(403, $this->lang->t('Current password is wrong'));
            }

            $params['password'] = $params['new_password'] ?? null;
        }

        // Unset admin field also new and conf password
        unset(
            $params['new_password'],
            $params['conf_new_password'],
            $params['username'],
            $params['status'],
            $params['level'],
            $params['group_id'],
            $params['enabled_high']
        );

        $params['user_id'] = [$currentUser->id->value];
        $updated_users = $this->userService->checkAndSaveUserInfos($params, $this->pageState);

        if (isset($updated_users['error'])) {
            // UserService::checkAndSaveUserInfos() is declared to return plain
            // `array`; its error branches always
            // populate error.code (int) and error.message (string), but that
            // shape isn't statically expressed, so narrow defensively here
            // rather than trust the mixed offsets.
            $error = $updated_users['error'];
            $error_code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : WsError::INVALID_PARAM;
            $error_message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Invalid parameters';
            return new WsErrorResponse($error_code, $error_message);
        }

        return $this->lang->t('Your changes have been applied.');
    }
}
