<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * Typed row shape for `piwigo_search` (P17-23 Stage 1b, Search domain --
 * `docs/PLAN-REPLAY.md`'s own "7 Entity types, 73 projection shapes"
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
 * `rules` stays `?string`, still serialized -- User domain's own
 * `preferences` text->JSON retype precedent applies here too and is
 * deliberately deferred the same way; every real consumer already
 * `unserialize()`s it itself.
 */
final readonly class Search
{
    public function __construct(
        public int $id,
        public ?string $searchUuid,
        public ?string $createdOn,
        public ?int $createdBy,
        public ?int $forkedFrom,
        public ?string $rules,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_search`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            searchUuid: is_string($row['search_uuid'] ?? null) ? $row['search_uuid'] : null,
            createdOn: is_string($row['created_on'] ?? null) ? $row['created_on'] : null,
            createdBy: is_numeric($row['created_by'] ?? null) ? (int) $row['created_by'] : null,
            forkedFrom: is_numeric($row['forked_from'] ?? null) ? (int) $row['forked_from'] : null,
            rules: is_string($row['rules'] ?? null) ? $row['rules'] : null,
        );
    }

    /**
     * @return array{id: int, search_uuid: ?string, created_on: ?string,
     *   created_by: ?int, forked_from: ?int, rules: ?string}
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
