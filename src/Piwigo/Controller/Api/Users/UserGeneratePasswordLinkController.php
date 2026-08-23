<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Projection\MailArgs;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Mail\MailService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/users/{id}/actions/generate-password-link` --
 * `pwg.users.generatePasswordLink`'s real replacement, admin + CSRF.
 * Only a webmaster can perform this action for another webmaster.
 */
final readonly class UserGeneratePasswordLinkController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private UserService $userService,
        private AuthService $authService,
        private AccessControl $accessControl,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private MailService $mailService,
        private UrlServiceInterface $urlService,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $lostUserId = is_string($rawId) ? (int) $rawId : 0;

        if (! $this->userService->getUsername(UserId::from($lostUserId)) instanceof Username) {
            return ResponseFactory::problem('Not Found', 404, 'This user does not exist.');
        }

        $userLost = $this->userService->getUserData(UserId::from($lostUserId));
        $userLostStatus = is_string($userLost['status'] ?? null) ? $userLost['status'] : '';

        if ($this->accessControl->isAGuest($userLostStatus) || $this->accessControl->isGeneric($userLostStatus)) {
            return ResponseFactory::problem('Forbidden', 403, 'Password reset is not allowed for this user.');
        }

        if ($this->currentUser->get()->status === UserStatus::Admin && $userLostStatus === 'webmaster') {
            return ResponseFactory::problem('Forbidden', 403, 'You cannot perform this action.');
        }

        $input = UserGeneratePasswordLinkInput::fromArray(JsonBody::decode($request));

        $firstLogin = $this->authService->hasAlreadyLoggedIn($lostUserId, $this->entityManager->getRepository(ActivityEntity::class));
        $sendByMailResponse = null;
        $userLostLanguage = is_string($userLost['language'] ?? null) ? $userLost['language'] : $this->userService->getDefaultLanguage();
        $langToUse = $firstLogin ? $this->userService->getDefaultLanguage() : $userLostLanguage;

        $this->mailService->switchLangTo($langToUse);
        $generatedLink = $this->authService->generatePasswordLink($lostUserId, $this->urlService, $firstLogin);

        $userLostEmail = is_string($userLost['email'] ?? null) ? $userLost['email'] : null;
        $galleryTitle = $this->currentConfig->galleryTitle;

        if ($input->sendByMail && ! in_array($userLostEmail, [null, ''], true)) {
            $userLostUsername = is_string($userLost['username'] ?? null) ? $userLost['username'] : '';
            $emailParams = $firstLogin
                ? $this->mailService->generateSetPasswordMail($userLostUsername, $generatedLink->passwordLink, $galleryTitle, $generatedLink->timeValidation)
                : $this->mailService->generateResetPasswordMail($userLostUsername, $generatedLink->passwordLink, $galleryTitle, $generatedLink->timeValidation);

            // Errors from mail() are suppressed deliberately -- a
            // failed send degrades to sendByMail: false, not a 5xx.
            $sendByMailResponse = @$this->mailService->mail($userLostEmail, new MailArgs(
                subject: $emailParams->subject,
                content: $emailParams->content,
                contentFormat: $emailParams->contentFormat,
            ))
                ? 'Mail sent at : ' . $userLostEmail
                : false;
        }
        $this->mailService->switchLangBack();

        return ResponseFactory::json([
            'generatedLink' => $generatedLink->passwordLink,
            'sendByMail' => $sendByMailResponse,
            'timeValidation' => $generatedLink->timeValidation,
        ]);
    }
}
