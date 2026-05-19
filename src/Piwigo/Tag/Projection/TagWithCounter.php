<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\TagId;

/**
 * Tag row joined with `COUNT(*) AS counter` — the shared shape returned by
 * `TagRepository::findCommonTags()` and consumed by every tag-cloud /
 * common-tag rendering path.
 */
final readonly class TagWithCounter
{
    public function __construct(
        public TagId $id,
        public string $name,
        public string $urlName,
        public MysqlDateTime $lastModified,
        public int $counter,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw           = $row['id'] ?? null;
        $nameRaw         = $row['name'] ?? null;
        $urlNameRaw      = $row['url_name'] ?? null;
        $lastmodifiedRaw = $row['lastmodified'] ?? null;
        $counterRaw      = $row['counter'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('TagWithCounter row is missing required `id` field');
        }
        $lastmodified = is_string($lastmodifiedRaw) ? MysqlDateTime::tryFrom($lastmodifiedRaw) : null;
        if ($lastmodified === null) {
            throw new \InvalidArgumentException('TagWithCounter row is missing required `lastmodified` field');
        }
        return new self(
            id:           TagId::from((int) $idRaw),
            name:         is_string($nameRaw) ? $nameRaw : '',
            urlName:      is_string($urlNameRaw) ? $urlNameRaw : '',
            lastModified: $lastmodified,
            counter:      is_numeric($counterRaw) ? (int) $counterRaw : 0,
        );
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id'           => $this->id->value,
            'name'         => $this->name,
            'url_name'     => $this->urlName,
            'lastmodified' => $this->lastModified->value,
            'counter'      => $this->counter,
        ];
    }
}
