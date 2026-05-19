<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `added_by` saved-search filter — list of user ids to match
 * against image.added_by. Used by the admin search to restrict
 * to images uploaded by specific users.
 */
final readonly class AddedByFilter
{
    /** @param list<int> $userIds */
    public function __construct(public array $userIds)
    {
    }

    /** @param array<int|string, mixed> $raw  flat list of user ids */
    public static function fromArray(array $raw): ?self
    {
        $userIds = [];
        foreach ($raw as $userId) {
            if (is_numeric($userId)) {
                $userIds[] = (int) $userId;
            }
        }
        return $userIds === [] ? null : new self($userIds);
    }
}
