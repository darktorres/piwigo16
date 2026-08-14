<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\UserStatus;

/**
 * {@see \Piwigo\Users\UserRepository::findAllBasicInfo()}'s own row shape
 * -- {@see \Piwigo\PluginConfig\Facade\UserReadFacade::listBasic()}'s real
 * source.
 */
final readonly class UserListing
{
    public function __construct(
        public UserId $id,
        public string $username,
        public UserStatus $status,
    ) {}
}
