<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for legacy `render_lost_password_mail_content` (dispatch).
 *
 * Dispatched from: src/Piwigo/Mail/MailService.php
 */
final readonly class RenderLostPasswordMailContent
{
    public function __construct(
        public string $message,
    ) {
    }

    public function withMessage(string $message): self
    {
        return new self($message);
    }
}
