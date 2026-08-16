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
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Mail\MailService;
use Piwigo\Session\SessionService;
use Piwigo\Users\UserService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.add` -- admin only. Registers a new user.
 */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private UserService $userService,
        private CurrentConfig $currentConfig,
        private SessionService $sessionService,
        private UrlServiceInterface $urlService,
        private MailService $mailService,
        private Lang $lang,
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
        $input = AddParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if (strlen(str_replace(' ', '', $input->username)) === 0) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'Name field must not be empty');
        }

        if ($this->currentConfig->doublePasswordTypeInAdmin) {
            if (($input->password ?? '') !== ($input->passwordConfirm ?? '')) {
                return new WsErrorResponse(WsError::InvalidParam->value, $this->lang->t('The passwords do not match'));
            }
        }

        $password = $input->password;
        if ($input->autoPassword) {
            $password = $this->sessionService->generateKey(mt_rand(15, 20));
        }

        // register_user() genuinely requires a string password; a client that
        // sends neither auto_password=true nor an explicit password would
        // otherwise reach it with null and crash inside pwg_password_hash() ->
        // password_hash() (a real string-typed native function).
        if ($password === null) {
            return new WsErrorResponse(WsError::InvalidParam->value, $this->lang->t('Please, enter a password'));
        }

        // Preserves the pre-SEC-31 behavior for this real caller (admin-
        // authenticated ws.users.add legitimately needs the real "already
        // used" message to let an operator pick a different username) --
        // UserService::registerUser() itself never puts that message in
        // errors (it would let an attacker enumerate accounts through the
        // public self-registration form).
        $result = $this->userService
            ->registerUser(
                $input->username,
                $password,
                $input->email,
                $this->urlService,
                $this->mailService,
                false, // notify admin
                false // send_password_by_mail is a registered param, but ignored -- see AddParams' own docblock
            );

        $errors = $result->errors;
        if ($result->duplicateUsername) {
            array_unshift($errors, $this->lang->t('this login is already used'));
        }

        $user_id = $result->userId ?? false;

        if (! (bool) $user_id) {
            return new WsErrorResponse(WsError::InvalidParam->value, $errors[0] ?? '');
        }

        return $this->getListHandler->resolve([
            'user_id' => [$user_id],
        ]);
    }
}
