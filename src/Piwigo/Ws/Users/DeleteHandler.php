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
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Lang\Translator;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.delete` -- admin only. Deletes one or more users. Photos owned by this user are not deleted.
 */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private UserService $userService,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private Translator $translator,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|string
    {
        $input = DeleteParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $currentUser = $this->currentUser->get();

        $protected_users = [
            $currentUser->id->value,
            $this->currentConfig->guestId,
            $this->currentConfig->defaultUserId,
            $this->currentConfig->webmasterId,
        ];

        // an admin can't delete other admin/webmaster
        if ($currentUser->status === UserStatus::Admin) {
            $protected_users = array_merge($protected_users, $this->userService->getAdminIds());
        }

        // protect some users
        // array_diff() requires every element to be string-castable;
        // array_column()'s list<mixed> return can contain null for a NULL
        // user_id column, and $protected_users' $conf-sourced entries are
        // still `mixed` even after typing $conf above, so filter down to
        // scalars (int/string) before diffing.
        $protected_users = array_filter($protected_users, is_scalar(...));
        $user_ids = array_diff($input->userIds, $protected_users);

        $counter = 0;

        $user_service = $this->userService;
        foreach ($user_ids as $user_id) {
            $user_service->deleteUser(UserId::from($user_id));
            $counter++;
        }

        return $this->translator->plural(
            '%d user deleted',
            '%d users deleted',
            $counter
        );
    }
}
