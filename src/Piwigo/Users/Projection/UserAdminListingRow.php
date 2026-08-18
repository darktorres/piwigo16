<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Users\UserStatus;

/**
 * {@see \Piwigo\Users\UserRepository::findAllForAdminListing()}'s own row
 * shape -- {@see \Piwigo\Command\UserListCommand}'s only real consumer.
 * `status`/`registrationDate` are nullable: a user present in `users` but
 * missing its `user_infos` row (the LEFT JOIN's own "no such row" case)
 * still appears, matching {@see \Piwigo\Users\UserRepository::
 * findStatusByIds()}'s own established precedent for the same join shape.
 */
final readonly class UserAdminListingRow
{
    public function __construct(
        public UserId $id,
        public Username $username,
        public ?Email $mailAddress,
        public ?UserStatus $status,
        public ?SqlDateTime $registrationDate,
    ) {}
}
