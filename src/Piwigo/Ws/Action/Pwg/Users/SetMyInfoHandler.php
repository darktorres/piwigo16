<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Tables;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.users.setMyInfo` — self-service profile update (subset of setInfo). */
final readonly class SetMyInfoHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private PermissionService $permissionService,
        private UserRepository $userRepository,
        private UserService $userService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($this->permissionService->isAGuest()) {
            return new PwgError(401, 'Access Denied');
        }
        $currentUser = CurrentUser::get();
        if (!Config::activateComments()) {
            unset($params['show_nb_comments']);
        }
        if (!Config::allowUserCustomization()) {
            unset($params['nb_image_page'], $params['theme'], $params['language'], $params['recent_period'], $params['expand'], $params['show_nb_comments'], $params['show_nb_hits']);
        }
        $specialUser = in_array($currentUser->id, [Config::guestId(), Config::defaultUserId()]);
        if ($specialUser) {
            unset($params['password'], $params['theme'], $params['language']);
        }
        if (!empty($params['password'])) {
            if ($params['new_password'] !== $params['conf_new_password']) {
                return new PwgError(403, Lang::t('The passwords do not match'));
            }
            $userFields      = Config::userFields();
            $currentPassword = $this->userRepository->findPasswordById($userFields['password'], $userFields['id'], Tables::users(), $currentUser->id);
            if (!password_verify(is_string($params['password']) ? $params['password'] : '', is_string($currentPassword) ? $currentPassword : '')) {
                return new PwgError(403, Lang::t('Current password is wrong'));
            }
            $params['password'] = $params['new_password'];
        }
        unset($params['new_password'], $params['conf_new_password'], $params['username'], $params['status'], $params['level'], $params['group_id'], $params['enabled_high']);
        $params['user_id'] = [$currentUser->id];
        $updatedUsers2     = $this->userService->checkAndSaveUserInfos($params);
        if (isset($updatedUsers2['error'])) {
            $err2 = is_array($updatedUsers2['error']) ? $updatedUsers2['error'] : [];
            return new PwgError(is_int($err2['code'] ?? null) ? $err2['code'] : null, is_string($err2['message'] ?? null) ? $err2['message'] : '');
        }
        return Lang::t('Your changes have been applied.');
    }
}
