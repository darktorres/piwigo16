<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for the legacy `before_parse_mail_template` notification.
 * No handler is registered for it anywhere today.
 */
final readonly class BeforeParseMailTemplate
{
    public function __construct(
        public string $cacheKey,
        public string $contentType,
    ) {}
}
