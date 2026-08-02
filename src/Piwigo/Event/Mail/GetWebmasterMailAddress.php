<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for the legacy `get_webmaster_mail_address` filter. No
 * handler is registered for it anywhere today.
 */
final readonly class GetWebmasterMailAddress
{
    public function __construct(
        public string $email,
    ) {}
}
