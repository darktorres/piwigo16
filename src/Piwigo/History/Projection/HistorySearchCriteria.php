<?php

declare(strict_types=1);

namespace Piwigo\History\Projection;

/**
 * {@see \Piwigo\History\HistoryService::getHistory()}'s own filter
 * criteria -- a fresh per-request shape built directly from
 * `Controller\Api\History\HistorySearchInput` (never serialized/stored,
 * unlike `Search\Projection\SearchRules`'s saved-search field set, which
 * this shape doesn't share a single field name with despite the
 * superficially similar "search filter" framing). Every field null means
 * "not filtered on", matching the original `$search['fields']` array's
 * own per-key `isset()` semantics exactly -- including `userId`, where
 * the caller-facing `-1` "no filter" sentinel is already resolved to
 * `null` before construction (see `HistorySearchInput`'s own docblock).
 */
final readonly class HistorySearchCriteria
{
    /**
     * @param ?list<string> $imageTypes
     */
    public function __construct(
        public ?string $filename = null,
        public ?string $dateAfter = null,
        public ?string $dateBefore = null,
        public ?array $imageTypes = null,
        public ?int $userId = null,
        public ?int $imageId = null,
        public ?string $ip = null,
    ) {}
}
