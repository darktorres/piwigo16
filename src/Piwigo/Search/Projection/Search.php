<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Core\ArrayHelper;

/**
 * Typed row shape for `piwigo_search` (P17-23 Stage 1b, Search domain --
 * `docs/PLAN.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_string($row['x']) ? ... :
 * default` narrowing {@see \Piwigo\Search\SearchService}'s own callers
 * used to do inline, same shape as {@see \Piwigo\Category\Projection\Category}.
 *
 * Scoped to {@see \Piwigo\Search\SearchRepository::findOneByClause()} only
 * -- that method is always fixed to `piwigo_search` (unlike this same
 * repository's deliberately generic findRowsByClause()/findIdsByClause(),
 * whose `$fromSql` varies per caller and can't be represented by a single
 * table's projection).
 *
 * `createdOn` stays `?string` here (this Projection's own convention) --
 * `SavedSearchEntity::$createdOn` itself is `SqlDateTime`-typed (Phase 5),
 * so `fromRow()` accepts either a real instance (a `getArrayResult()`
 * -hydrated row) or a raw string.
 *
 * `rules` is `?array`, decoded here via `json_decode()` -- the column is
 * JSON (gap-closure Stage 1a-bis item 2), so every real consumer reads an
 * already-decoded value instead of hand-rolling `unserialize()`/
 * `json_decode()` itself. Every writer ({@see
 * \Piwigo\Search\SearchService::saveSearch()}'s own `array<string, mixed>
 * $rules` parameter) only ever stores a string-keyed JSON object, so the
 * decoded value is filtered down to string keys here rather than carrying
 * `ArrayHelper::safeJsonDecode()`'s more permissive `array<int|string,
 * mixed>` (shared with callers that genuinely can get a JSON list back)
 * through every one of this projection's own consumers.
 */
final readonly class Search
{
    /**
     * @param array<string, mixed>|null $rules
     */
    public function __construct(
        public int $id,
        public ?string $searchUuid,
        public ?string $createdOn,
        public ?int $createdBy,
        public ?int $forkedFrom,
        public ?array $rules,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_search`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            searchUuid: is_string($row['search_uuid'] ?? null) ? $row['search_uuid'] : null,
            createdOn: ($row['created_on'] ?? null) instanceof SqlDateTime
                ? $row['created_on']->value
                : (is_string($row['created_on'] ?? null) ? $row['created_on'] : null),
            createdBy: is_numeric($row['created_by'] ?? null) ? (int) $row['created_by'] : null,
            forkedFrom: is_numeric($row['forked_from'] ?? null) ? (int) $row['forked_from'] : null,
            rules: self::decodeRules($row['rules'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeRules(mixed $rulesRaw): ?array
    {
        if (! is_string($rulesRaw)) {
            return null;
        }

        return array_filter(ArrayHelper::safeJsonDecode($rulesRaw), is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array{id: int, search_uuid: ?string, created_on: ?string,
     *   created_by: ?int, forked_from: ?int, rules: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'search_uuid' => $this->searchUuid,
            'created_on' => $this->createdOn,
            'created_by' => $this->createdBy,
            'forked_from' => $this->forkedFrom,
            'rules' => $this->rules,
        ];
    }
}
