<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Facade;

use Piwigo\Users\Projection\UserListing;
use Piwigo\Users\UserRepository;

/**
 * Narrow, purpose-built read facade handed out by `ExtensionContext::
 * users()` -- same discipline as `ImageReadFacade`'s own docblock: never
 * `UserService`/raw SQL directly.
 *
 * Grounded in `../piwigo16-plugins/AdminTools_16.3.0/include/
 * MultiView.class.php`'s own real `ws_get_data()` query, which lists
 * every user's id/username/status for its custom `multiView.getData` WS
 * method (P27.14).
 */
final readonly class UserReadFacade
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    /**
     * @return list<BasicUserInfo>
     */
    public function listBasic(): array
    {
        return array_map(
            static fn (UserListing $user): BasicUserInfo => new BasicUserInfo($user->id, $user->username, $user->status),
            $this->userRepository->findAllBasicInfo(),
        );
    }
}
