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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = SetMyInfoParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($this->permissionService->isAGuest()) {
            return new PwgError(401, 'Access Denied');
        }
        $currentUser = CurrentUser::get();
        $payload     = $input->raw;
        if (!Config::activateComments()) {
            unset($payload['show_nb_comments']);
        }
        if (!Config::allowUserCustomization()) {
            unset($payload['nb_image_page'], $payload['theme'], $payload['language'], $payload['recent_period'], $payload['expand'], $payload['show_nb_comments'], $payload['show_nb_hits']);
        }
        $specialUser = in_array($currentUser->id, [Config::guestId(), Config::defaultUserId()]);
        if ($specialUser) {
            unset($payload['password'], $payload['theme'], $payload['language']);
        }
        if (isset($payload['password']) && $payload['password'] !== '' && $payload['password'] !== false && $payload['password'] !== 0) {
            if ($input->newPassword !== $input->confNewPassword) {
                return new PwgError(403, Lang::t('The passwords do not match'));
            }
            $userFields      = Config::userFields();
            $currentPassword = $this->userRepository->findPasswordById($userFields->password, $userFields->id, Tables::users(), $currentUser->id);
            if (!password_verify($input->password ?? '', is_string($currentPassword) ? $currentPassword : '')) {
                return new PwgError(403, Lang::t('Current password is wrong'));
            }
            $payload['password'] = $input->newPassword;
        }
        unset($payload['new_password'], $payload['conf_new_password'], $payload['username'], $payload['status'], $payload['level'], $payload['group_id'], $payload['enabled_high']);
        $payload['user_id'] = [$currentUser->id];
        $updatedUsers2      = $this->userService->checkAndSaveUserInfos($payload);
        if (isset($updatedUsers2['error'])) {
            $err2 = is_array($updatedUsers2['error']) ? $updatedUsers2['error'] : [];
            return new PwgError(is_int($err2['code'] ?? null) ? $err2['code'] : null, is_string($err2['message'] ?? null) ? $err2['message'] : '');
        }
        return Lang::t('Your changes have been applied.');
    }
}
