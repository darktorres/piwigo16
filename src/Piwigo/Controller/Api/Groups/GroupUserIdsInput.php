<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

/**
 * Shared `POST .../actions/{add-user,remove-user}` body DTO -- carries
 * `userIds`.
 */
final readonly class GroupUserIdsInput
{
    /**
     * @param list<int> $userIds
     */
    public function __construct(
        public array $userIds,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            userIds: self::intList($raw['userIds'] ?? null),
        );
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            if (is_int($v)) {
                $ids[] = $v;
            } elseif (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }
}
