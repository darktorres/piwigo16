<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/** (element_id, avg_rate) aggregate row from RateRepository::findAverageByElement(). */
final readonly class ElementAvgRate
{
    public function __construct(
        public int $elementId,
        public float $avgRate,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            elementId: is_numeric($row['element_id'] ?? null) ? (int) $row['element_id'] : 0,
            avgRate:   is_numeric($row['avg_rate'] ?? null) ? (float) $row['avg_rate'] : 0.0,
        );
    }
}
