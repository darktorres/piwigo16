<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\UserId;

/**
 * {@see \Piwigo\Users\UserRepository::fetchBasicUserRow()}'s own row shape
 * -- {@see \Piwigo\Users\UserService::getUserData()}'s own basic-row half
 * of its `users`/`user_infos` merge.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * `getUserData()` `array_merge()`s this with
 * {@see UserInfoWithThemeName}'s own `toArray()` into one flat,
 * dynamically-keyed `$userdata` bag every downstream template/WS response
 * reads by key -- the merge boundary itself genuinely needs a flat array,
 * same reason every other DTO in this codebase keeps a `toArray()` unwrap
 * at its actual serialization boundary.
 */
final readonly class BasicUserRow
{
    public function __construct(
        public UserId $id,
        public string $username,
        public ?string $password,
        public ?string $email,
    ) {}

    /**
     * @return array{id: int, username: string, password: ?string, email: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'username' => $this->username,
            'password' => $this->password,
            'email' => $this->email,
        ];
    }
}
