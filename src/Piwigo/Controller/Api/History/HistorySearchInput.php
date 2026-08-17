<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\History;

/**
 * `GET /api/v1/history/search` query-param DTO. `userId` keeps a `-1`
 * "no filter" sentinel (matching `history.js`'s own
 * `current_param.user_id` convention) rather than `?int`, so a caller
 * can't confuse "filter by user -1" (impossible, `UserId` is a positive
 * int) with "no filter".
 */
final readonly class HistorySearchInput
{
    /**
     * @param list<string> $types
     */
    public function __construct(
        public ?string $start,
        public ?string $end,
        public array $types,
        public int $userId,
        public ?int $imageId,
        public ?string $filename,
        public ?string $ip,
        public int $pageNumber,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $start = $raw['start'] ?? null;
        $end = $raw['end'] ?? null;
        $userId = $raw['userId'] ?? null;
        $imageId = $raw['imageId'] ?? null;
        $filename = $raw['filename'] ?? null;
        $ip = $raw['ip'] ?? null;
        $pageNumber = $raw['pageNumber'] ?? null;

        return new self(
            start: is_string($start) && $start !== '' ? $start : null,
            end: is_string($end) && $end !== '' ? $end : null,
            types: self::stringList($raw['types'] ?? null),
            userId: is_numeric($userId) ? (int) $userId : -1,
            imageId: is_numeric($imageId) ? (int) $imageId : null,
            filename: is_string($filename) && $filename !== '' ? $filename : null,
            ip: is_string($ip) && $ip !== '' ? $ip : null,
            pageNumber: is_numeric($pageNumber) ? max(0, (int) $pageNumber) : 0,
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $values[] = $v;
            }
        }

        return $values;
    }
}
