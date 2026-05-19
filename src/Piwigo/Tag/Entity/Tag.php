<?php

declare(strict_types=1);

namespace Piwigo\Tag\Entity;

use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\TagId;

/**
 * Typed entity wrapping one row of the `tags` table. Mirrors the `Image`
 * entity convention from F5-d/1.
 */
final readonly class Tag
{
    public function __construct(
        public TagId $id,
        public string $name,
        public string $urlName,
        public MysqlDateTime $lastModified,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('Tag row is missing required `id` field');
        }
        $lastmodifiedRaw = $row['lastmodified'] ?? null;
        $lastmodified    = is_string($lastmodifiedRaw) ? MysqlDateTime::tryFrom($lastmodifiedRaw) : null;
        if ($lastmodified === null) {
            throw new \InvalidArgumentException('Tag row is missing required `lastmodified` field');
        }
        return new self(
            id:           TagId::from((int) $idRaw),
            name:         is_string($row['name'] ?? null) ? $row['name'] : '',
            urlName:      is_string($row['url_name'] ?? null) ? $row['url_name'] : '',
            lastModified: $lastmodified,
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
        ];
    }
}
