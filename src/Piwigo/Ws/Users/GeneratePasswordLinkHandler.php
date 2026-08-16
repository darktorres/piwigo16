<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Mail\MailService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.generatePasswordLink` -- admin only. Returns the reset
 * password link of the current user. Only webmaster can perform this
 * action for another webmaster.
 */
final readonly class GeneratePasswordLinkHandler implements WsAction
{
    public function __construct(
        private UserService $userService,
        private AuthService $authService,
        private AccessControl $accessControl,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private MailService $mailService,
        private UrlServiceInterface $urlService,
        private EntityManagerInterface $entityManager,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{generated_link: string, send_by_mail: string|false|null, time_validation: string}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = GeneratePasswordLinkParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $lost_user_id = UserId::from($input->userId);

        // check if user exist
        if (! $this->userService->getUsername($lost_user_id) instanceof Username) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'This user does not exist.');
        }

        // UserService::getUserData() is declared to return
        // array<string, mixed> (its own @return docblock); narrow the
        // specific fields this function consumes to their real column types.
        $user_lost = $this->userService->getUserData($lost_user_id);
        $user_lost_status = is_string($user_lost['status']) ? $user_lost['status'] : '';

        // Cannot perform this action for a guest or generic user
        if ($this->accessControl->isAGuest($user_lost_status) or $this->accessControl->isGeneric($user_lost_status)) {
            return new WsErrorResponse(403, 'Password reset is not allowed for this user');
        }

        // Only webmaster can perform this action for another webmaster
        if ($this->currentUser->get()->status === UserStatus::Admin && $user_lost_status === 'webmaster') {
            return new WsErrorResponse(403, 'You cannot perform this action');
        }

        $first_login = $this->authService->hasAlreadyLoggedIn($input->userId, $this->entityManager->getRepository(ActivityEntity::class));
        $send_by_mail_response = null;
        $user_lost_language = is_string($user_lost['language']) ? $user_lost['language'] : $this->userService->getDefaultLanguage();
        $lang_to_use = $first_login ? $this->userService->getDefaultLanguage() : $user_lost_language;

        $this->mailService
            ->switchLangTo($lang_to_use);
        $generate_link = $this->authService->generatePasswordLink($input->userId, $this->urlService, $first_login);

        $user_lost_email = is_string($user_lost['email']) ? $user_lost['email'] : null;

        // $this->currentConfig->galleryTitle is a raw config string; pwg_generate_set/
        // reset_password_mail() both require a real string for their 3rd
        // parameter.
        $gallery_title = $this->currentConfig->galleryTitle;

        if ($input->sendByMail and ! in_array($user_lost_email, [null, ''], true)) {
            $user_lost_username = is_string($user_lost['username']) ? $user_lost['username'] : '';
            if ($first_login) {
                $email_params = $this->mailService
                    ->generateSetPasswordMail($user_lost_username, $generate_link['password_link'], $gallery_title, $generate_link['time_validation']);
            } else {
                $email_params = $this->mailService
                    ->generateResetPasswordMail($user_lost_username, $generate_link['password_link'], $gallery_title, $generate_link['time_validation']);
            }
            // Here we remove the display of errors because they prevent the response from being parsed
            if (@$this->mailService->mail($user_lost_email, $email_params->toArray())) {
                $send_by_mail_response = 'Mail sent at : ' . $user_lost_email;
            } else {
                $send_by_mail_response = false;
            }
        }
        $this->mailService
            ->switchLangBack();

        return [
            'generated_link' => $generate_link['password_link'],
            'send_by_mail' => $send_by_mail_response,
            'time_validation' => $generate_link['time_validation'],
        ];
    }
}
