<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Comments;

/**
 * Shared `POST .../actions/{delete,validate}` body DTO -- carries
 * `commentIds`.
 */
final readonly class CommentIdsInput
{
    /**
     * @param list<int> $commentIds
     */
    public function __construct(
        public array $commentIds,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            commentIds: self::intList($raw['commentIds'] ?? null),
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

        return array_values(array_unique($ids));
    }
}
