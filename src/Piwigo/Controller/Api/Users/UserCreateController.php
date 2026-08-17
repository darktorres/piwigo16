<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Override;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Mail\MailService;
use Piwigo\Session\SessionService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/users` -- `pwg.users.add`'s real replacement, admin +
 * CSRF. Preserves `Ws\Users\AddHandler`'s own choice to surface the real
 * "this login is already used" message to an admin caller (unlike the
 * public self-registration form, which never reveals that -- SEC-31 --
 * an authenticated admin picking a different username is a legitimate
 * need, not account enumeration).
 */
final readonly class UserCreateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private UserService $userService,
        private UserRowFetcher $userRowFetcher,
        private CurrentConfig $currentConfig,
        private SessionService $sessionService,
        private UrlServiceInterface $urlService,
        private MailService $mailService,
        private Lang $lang,
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

        $input = UserCreateInput::fromArray(JsonBody::decode($request));

        if (str_replace(' ', '', $input->username) === '') {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Name field must not be empty.');
        }

        if ($this->currentConfig->doublePasswordTypeInAdmin && $input->password !== $input->passwordConfirm) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $this->lang->t('The passwords do not match'));
        }

        $password = $input->autoPassword ? $this->sessionService->generateKey(mt_rand(15, 20)) : $input->password;
        if ($password === null) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $this->lang->t('Please, enter a password'));
        }

        $result = $this->userService->registerUser(
            $input->username,
            $password,
            $input->email,
            $this->urlService,
            $this->mailService,
            false,
            false
        );

        $errors = $result->errors;
        if ($result->duplicateUsername) {
            array_unshift($errors, $this->lang->t('this login is already used'));
        }

        if ($result->userId === null) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $errors[0] ?? 'Unable to create this user.');
        }

        $rows = $this->userRowFetcher->byIds([UserId::from($result->userId)]);

        return ResponseFactory::json($rows[0] ?? [
            'id' => $result->userId,
        ], 201);
    }
}
