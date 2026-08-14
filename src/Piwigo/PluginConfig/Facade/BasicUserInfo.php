<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Facade;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\UserStatus;

/**
 * One row of {@see UserReadFacade::listBasic()}'s own listing.
 */
final readonly class BasicUserInfo
{
    public function __construct(
        public UserId $id,
        public string $username,
        public UserStatus $status,
    ) {}
}
