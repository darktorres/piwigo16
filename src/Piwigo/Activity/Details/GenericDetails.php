<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/**
 * Catch-all for activity detail shapes that are unique per call site and don't
 * warrant a dedicated class — varied Edit payloads, plugin/theme management
 * results, etc. Passes the caller-supplied array through unchanged.
 */
final readonly class GenericDetails implements ActivityDetails
{
    /** @param array<string, mixed> $fields */
    public function __construct(private array $fields)
    {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return $this->fields;
    }
}
