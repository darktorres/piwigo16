<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Csrf\CsrfService;
use Piwigo\Lang\Translator;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.users.delete` — remove users (preserves photos owned by them). */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private UserAdminService $userAdminService,
        private UserRepository $userRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|string
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $currentUser    = CurrentUser::get();
        $protectedUsers = [$currentUser->id, Config::guestId(), Config::defaultUserId(), Config::webmasterId()];
        if ($currentUser->status === 'admin') {
            $protectedUsers = array_merge($protectedUsers, $this->userRepository->findAdminUserIds());
        }
        $userIdArr = is_array($params['user_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['user_id']) : [];
        $userIdArr = array_diff($userIdArr, $protectedUsers);
        $counter   = 0;
        foreach ($userIdArr as $userId) {
            $this->userAdminService->deleteUser($userId);
            $counter++;
        }
        return Translator::get()->plural('%d user deleted', '%d users deleted', $counter);
    }
}
