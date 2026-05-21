<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/** (element_id, rcount, rsum) aggregate row from RateRepository::getSumsByElement(). */
final readonly class ElementRateSum
{
    public function __construct(
        public int $elementId,
        public int $rcount,
        public int $rsum,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            elementId: is_numeric($row['element_id'] ?? null) ? (int) $row['element_id'] : 0,
            rcount:    is_numeric($row['rcount'] ?? null) ? (int) $row['rcount'] : 0,
            rsum:      is_numeric($row['rsum'] ?? null) ? (int) $row['rsum'] : 0,
        );
    }
}
