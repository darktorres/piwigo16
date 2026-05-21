<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/** (object, action, counter) aggregate row from ActivityRepository::findActionCountsByObject(). */
final readonly class ActionCountRow
{
    public function __construct(
        public string $object,
        public string $action,
        public int $counter,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            object:  is_string($row['object'] ?? null) ? $row['object'] : '',
            action:  is_string($row['action'] ?? null) ? $row['action'] : '',
            counter: is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0,
        );
    }
}
