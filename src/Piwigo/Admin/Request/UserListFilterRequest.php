<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['group']`/`$_GET['user_id']`/`$_GET['show_add_user']`
 * for UserListPageRenderer::render() (page slug "user_list") --
 * P27/SEC-40 Request DTO. `group`/`user_id` are digits-only
 * (`ValidationPattern::ID`), not mandatory. `userSearchInput` precomputes
 * the `'id:' . user_id` search string this page's own template variable
 * needs, matching the original inline expression exactly. `showAddUser`
 * is a presence-only flag (`isset($_GET['show_add_user'])`).
 */
final readonly class UserListFilterRequest
{
    private function __construct(
        public ?string $groupId,
        public ?string $userSearchInput,
        public bool $showAddUser,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        new InputValidator()
            ->validate('group', $source, false, ValidationPattern::ID);
        new InputValidator()
            ->validate('user_id', $source, false, ValidationPattern::ID);

        $group = $source['group'] ?? null;
        $user_id = $source['user_id'] ?? null;

        return new self(
            is_string($group) ? $group : null,
            is_string($user_id) ? 'id:' . $user_id : null,
            isset($source['show_add_user']),
        );
    }
}
