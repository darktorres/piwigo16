<?php

declare(strict_types=1);

namespace Piwigo\Users;

/** Discriminated result from UserService::checkAndSaveUserInfos(). */
final readonly class UpdateUserResult
{
    /**
     * @param array<mixed>|null $userId
     * @param array<mixed>|null $infos
     * @param array<mixed>|null $account
     */
    private function __construct(
        public bool $isError,
        public int|null $errorCode = null,
        public string|null $errorMessage = null,
        public array|null $userId = null,
        public array|null $infos = null,
        public array|null $account = null,
    ) {
    }

    public static function error(int $code, string $message): self
    {
        return new self(isError: true, errorCode: $code, errorMessage: $message);
    }

    /**
     * @param array<mixed> $userId
     * @param array<mixed> $infos
     * @param array<mixed> $account
     */
    public static function success(array $userId, array $infos, array $account): self
    {
        return new self(isError: false, userId: $userId, infos: $infos, account: $account);
    }
}
