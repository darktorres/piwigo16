<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/**
 * {@see \Piwigo\Users\UserService::registerUser()}'s own fixed result
 * shape. `errors` is real, showable validation errors -- never includes a
 * duplicate-login message.
 */
final readonly class RegistrationOutcome
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public ?int $userId,
        public array $errors,
        public bool $duplicateUsername,
    ) {}
}
