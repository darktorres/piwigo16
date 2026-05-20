<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParams;

/** `pwg.categories.refreshRepresentative` input DTO. */
final readonly class RefreshRepresentativeParams implements WsParams
{
    public function __construct(public int $categoryId)
    {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        return new self(
            categoryId: is_numeric($raw['category_id'] ?? null) ? (int) $raw['category_id'] : 0,
        );
    }
}
