<?php

declare(strict_types=1);

namespace Piwigo\Site\Projection;

/**
 * Typed row shape for `piwigo_sites`. `fromRow()` centralises the
 * `is_string($row['x']) ? ... :
 * default` narrowing {@see \Piwigo\Site\SiteRepository}'s own caller used
 * to do inline, same shape as {@see \Piwigo\Category\Projection\Category}.
 */
final readonly class Site
{
    public function __construct(
        public int $id,
        public string $galleriesUrl,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_sites`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            galleriesUrl: is_string($row['galleries_url'] ?? null) ? $row['galleries_url'] : '',
        );
    }

    /**
     * @return array{id: int, galleries_url: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'galleries_url' => $this->galleriesUrl,
        ];
    }
}
