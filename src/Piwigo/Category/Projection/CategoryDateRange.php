<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (from, to) date range per category from CategoryRepository::findDateRangesForCategoriesKeyedById(), keyed by category_id. */
final readonly class CategoryDateRange
{
    public function __construct(
        public ?string $from,
        public ?string $to,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            from: is_string($row['from'] ?? null) ? $row['from'] : null,
            to:   is_string($row['to'] ?? null) ? $row['to'] : null,
        );
    }
}
