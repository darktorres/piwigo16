<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for legacy `before_parse_mail_template` (notify).
 *
 * Dispatched from: src/Piwigo/Mail/MailService.php
 */
final readonly class BeforeParseMailTemplate
{
    public function __construct(
        public string $cacheKey,
        public string $contentType,
    ) {
    }
}
