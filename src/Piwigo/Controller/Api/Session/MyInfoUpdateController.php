<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Api\SessionStatusPresenter;
use Piwigo\Core\PageState;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserInfoUpdateFailureReason;
use Piwigo\Users\UserInfoUpdateInput;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /api/v1/session` -- `pwg.users.setMyInfo`'s real replacement: a
 * logged-in (non-guest) user updating their own account. Returns the
 * resulting session status (`SessionStatusPresenter`, shared with
 * `GET`/`POST`/`DELETE /api/v1/session`) on success.
 */
final readonly class MyInfoUpdateController implements ControllerInterface
{
    public function __construct(
        private CsrfGuard $csrfGuard,
        private UserService $userService,
        private AuthService $authService,
        private AccessControl $accessControl,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private PageState $pageState,
        private PasswordService $passwordService,
        private SessionStatusPresenter $sessionStatusPresenter,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        if ($this->accessControl->isAGuest()) {
            return ResponseFactory::problem('Unauthorized', 401, 'Access denied.');
        }

        $input = MyInfoUpdateInput::fromArray(JsonBody::decode($request));
        $currentUser = $this->currentUser->get();

        $theme = $input->theme;
        $language = $input->language;
        $nbImagePage = $input->nbImagePage;
        $recentPeriod = $input->recentPeriod;
        $expand = $input->expand;
        $showNbComments = $input->showNbComments;
        $showNbHits = $input->showNbHits;
        $password = $input->password;

        if (! $this->currentConfig->activateComments) {
            $showNbComments = null;
        }

        if (! $this->currentConfig->allowUserCustomization) {
            $nbImagePage = null;
            $theme = null;
            $language = null;
            $recentPeriod = null;
            $expand = null;
            $showNbComments = null;
            $showNbHits = null;
        }

        $specialUser = in_array($currentUser->id->value, [$this->currentConfig->guestId, $this->currentConfig->defaultUserId], true);
        if ($specialUser) {
            $password = null;
            $theme = null;
            $language = null;
        }

        if ($password !== null && $password !== '') {
            if (($input->newPassword ?? '') !== ($input->confNewPassword ?? '')) {
                return ResponseFactory::problem('Forbidden', 403, 'The passwords do not match.');
            }

            $currentPassword = $this->authService->getPasswordHash($currentUser->id) ?? '';
            if (! $this->passwordService->verify($password, $currentPassword)) {
                return ResponseFactory::problem('Forbidden', 403, 'Current password is wrong.');
            }

            $password = $input->newPassword;
        }

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
            $status = $result->failureReason === UserInfoUpdateFailureReason::Forbidden ? 403 : 422;

            return ResponseFactory::problem($status === 403 ? 'Forbidden' : 'Unprocessable Entity', $status, $result->failureMessage);
        }

        return ResponseFactory::json($this->sessionStatusPresenter->present());
    }
}
