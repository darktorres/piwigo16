<?php

declare(strict_types=1);

namespace Piwigo\Mail\Event;

/**
 * Typed event for the legacy `before_parse_mail_template` notification.
 * No handler is registered for it anywhere today. Co-located here from `Piwigo\Event\Mail\BeforeParseMailTemplate` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class BeforeParseMailTemplate
{
    public function __construct(
        public string $cacheKey,
        public string $contentType,
    ) {}
}
