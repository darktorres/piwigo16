<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/** (user_agent, counter, first_encounter, last_encounter) row from ActivityRepository::findAppUserAgentStats(). */
final readonly class AppUserAgentStatRow
{
    public function __construct(
        public string $userAgent,
        public int $counter,
        public string $firstEncounter,
        public string $lastEncounter,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userAgent:      is_string($row['user_agent'] ?? null) ? $row['user_agent'] : '',
            counter:        is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0,
            firstEncounter: is_string($row['first_encounter'] ?? null) ? $row['first_encounter'] : '',
            lastEncounter:  is_string($row['last_encounter'] ?? null) ? $row['last_encounter'] : '',
        );
    }
}
