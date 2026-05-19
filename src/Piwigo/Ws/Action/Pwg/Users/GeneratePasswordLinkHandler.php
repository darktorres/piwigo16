<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Csrf\CsrfService;
use Piwigo\Mail\MailService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.users.generatePasswordLink` — issue a password-reset link (optionally email it). */
final readonly class GeneratePasswordLinkHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private CsrfService $csrfService,
        private MailService $mailService,
        private PermissionService $permissionService,
        private UserAdminService $userAdminService,
        private UserService $userService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $targetUserId = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
        if ($this->userAdminService->getUsername($targetUserId) === false) {
            return new PwgError(WsError::InvalidParam->value, 'This user does not exist.');
        }
        $userLost = $this->userService->getuserdata($targetUserId);
        if ($userLost === false) {
            return new PwgError(404, 'User not found');
        }
        $userLostStatus = is_string($userLost['status'] ?? null) ? $userLost['status'] : '';
        if ($this->permissionService->isAGuest($userLostStatus) || $this->permissionService->isGeneric($userLostStatus)) {
            return new PwgError(403, 'Password reset is not allowed for this user');
        }
        if (CurrentUser::get()->status === 'admin' && $userLostStatus === 'webmaster') {
            return new PwgError(403, 'You cannot perform this action');
        }
        $firstLogin       = $this->userService->hasAlreadyLoggedIn($targetUserId);
        $sendByMailResp   = null;
        $userLostLanguage = is_string($userLost['language'] ?? null) ? $userLost['language'] : '';
        $langToUse        = $firstLogin ? $this->userService->getDefaultLanguage() : $userLostLanguage;
        $this->mailService->switchLangTo($langToUse);
        $generateLink         = $this->authService->generatePasswordLink($targetUserId, $firstLogin);
        $userLostEmailRaw     = $userLost['email'] ?? null;
        $userLostUsernameRaw  = $userLost['username'] ?? null;
        $genPasswordLinkRaw   = $generateLink['password_link'] ?? null;
        $genTimeValidationRaw = $generateLink['time_validation'] ?? null;
        $userLostEmail        = is_string($userLostEmailRaw) ? $userLostEmailRaw : '';
        $userLostUsername     = is_string($userLostUsernameRaw) ? $userLostUsernameRaw : '';
        $genPasswordLink      = is_string($genPasswordLinkRaw) ? $genPasswordLinkRaw : '';
        $genTimeValidation    = is_string($genTimeValidationRaw) ? $genTimeValidationRaw : '';
        if ($params['send_by_mail'] && $userLostEmail !== '') {
            $emailParams    = $firstLogin ? $this->mailService->pwgGenerateSetPasswordMail($userLostUsername, $genPasswordLink, Config::galleryTitle(), $genTimeValidation) : $this->mailService->pwgGenerateResetPasswordMail($userLostUsername, $genPasswordLink, Config::galleryTitle(), $genTimeValidation);
            $sendByMailResp = $this->mailService->pwgMail($userLostEmail, $emailParams) ? 'Mail sent at : ' . $userLostEmail : false;
        }
        $this->mailService->switchLangBack();
        return ['generated_link' => $genPasswordLink, 'send_by_mail' => $sendByMailResp, 'time_validation' => $genTimeValidation];
    }
}
