<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/** (action, occured_on, details) row from ActivityRepository::findCoreUpdateActivities(). */
final readonly class CoreUpdateActivityRow
{
    public function __construct(
        public string $action,
        public string $occurredOn,
        public ?string $details,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            action:     is_string($row['action'] ?? null) ? $row['action'] : '',
            occurredOn: is_string($row['occured_on'] ?? null) ? $row['occured_on'] : '',
            details:    is_string($row['details'] ?? null) ? $row['details'] : null,
        );
    }
}
