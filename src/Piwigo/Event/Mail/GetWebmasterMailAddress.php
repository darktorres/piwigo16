<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for legacy `get_webmaster_mail_address` (dispatch).
 *
 * New in 2.6
 *
 * Dispatched from: src/Piwigo/Core/Util.php
 */
final readonly class GetWebmasterMailAddress
{
    public function __construct(
        public string $email,
    ) {
    }

    public function withEmail(string $email): self
    {
        return new self($email);
    }
}
