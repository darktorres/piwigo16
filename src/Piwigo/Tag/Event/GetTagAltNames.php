<?php

declare(strict_types=1);

namespace Piwigo\Tag\Event;

/**
 * Typed event for the legacy `get_tag_alt_names` filter. No handler is
 * registered for it anywhere today -- a pure information carrier. Every
 * real dispatch site starts `$value` from an empty array, expecting a
 * handler to populate it. Mutable on `$value`; `$rawName` stays context.
 */
final class GetTagAltNames
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public array $value,
        public readonly string $rawName,
    ) {}
}
