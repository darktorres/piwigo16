<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Common\Enum\UserStatus;
use Piwigo\Config\Config;
use Piwigo\Csrf\CsrfService;
use Piwigo\Lang\Translator;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

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
        try {
            $input = DeleteParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $currentUser    = CurrentUser::get();
        $protectedUsers = [$currentUser->id, Config::guestId(), Config::defaultUserId(), Config::webmasterId()];
        if ($currentUser->status === UserStatus::Admin) {
            $protectedUsers = array_merge($protectedUsers, $this->userRepository->findAdminUserIds());
        }
        $userIds = array_values(array_diff($input->userIds, $protectedUsers));
        $counter = 0;
        foreach ($userIds as $userId) {
            $this->userAdminService->deleteUser($userId);
            $counter++;
        }
        return Translator::get()->plural('%d user deleted', '%d users deleted', $counter);
    }
}
