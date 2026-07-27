<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

/**
 * Typed row shape for `piwigo_tags` (P17-23 Stage 1b, Tag domain --
 * `docs/PLAN.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_string($row['x']) ? ... :
 * default` narrowing every {@see \Piwigo\Tag\TagRepository} caller used to
 * duplicate for itself, same shape as {@see \Piwigo\Category\Projection\Category}.
 *
 * Scoped to the 3 pure single-table `piwigo_tags` row methods (findAllTags()/
 * findByIdsUrlNamesOrNames()/findByIdsOrAll()) -- findCommonTags()'s own
 * `t.*, count(*) AS counter` JOIN-plus-aggregate query stays a raw array,
 * same deliberate deferral as UserService::getUserData()'s own 3-way raw
 * JOIN (a tag row glued to an aggregate isn't a `piwigo_tags` row this
 * projection could represent on its own). `fromRow()` still tolerates an
 * extra `counter` key being present on the row it's handed (it simply
 * never reads it) -- this matters for `TagService::getAllTags()`/
 * `getAvailableTags()`, which widen the row with a derived `counter`
 * *after* narrowing it through this projection, not before.
 *
 * `lastmodified` stays `string`, not `\DateTimeImmutable` -- no real
 * consumer today needs anything but the raw DB DATETIME string form, same
 * reasoning as {@see \Piwigo\Image\Projection\Image}.
 */
final readonly class Tag
{
    public function __construct(
        public int $id,
        public string $name,
        public string $urlName,
        public string $lastmodified,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_tags`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            urlName: is_string($row['url_name'] ?? null) ? $row['url_name'] : '',
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : '',
        );
    }

    /**
     * @return array{id: int, name: string, url_name: string, lastmodified: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url_name' => $this->urlName,
            'lastmodified' => $this->lastmodified,
        ];
    }
}
