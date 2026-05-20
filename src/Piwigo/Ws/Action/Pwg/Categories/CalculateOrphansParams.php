<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParams;

/** `pwg.categories.calculateOrphans` input DTO — the category to inspect. */
final readonly class CalculateOrphansParams implements WsParams
{
    public function __construct(public int $categoryId)
    {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $rawIds  = is_array($raw['category_id'] ?? null) ? $raw['category_id'] : [];
        $firstId = $rawIds[0] ?? null;
        return new self(categoryId: is_numeric($firstId) ? (int) $firstId : 0);
    }
}
