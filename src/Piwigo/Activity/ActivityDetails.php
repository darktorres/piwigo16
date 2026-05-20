<?php

declare(strict_types=1);

namespace Piwigo\Activity;

/**
 * Typed payload carried by ActivityEvent::$details.
 * Each concrete implementation covers one per-action shape.
 * ActivityLogger::log() merges toJsonArray() with its own runtime-context
 * fields (method/script/connected_with/etc.) before JSON-encoding.
 */
interface ActivityDetails
{
    /** @return array<string, mixed> */
    public function toJsonArray(): array;
}
