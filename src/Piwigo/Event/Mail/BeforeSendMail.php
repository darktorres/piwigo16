<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for legacy `before_send_mail` (dispatch).
 *
 * Dispatched from: src/Piwigo/Mail/MailService.php
 */
final readonly class BeforeSendMail
{
    /**
     * @param array<mixed> $arguments
     */
    public function __construct(
        public bool $result,
        public mixed $to,
        public array $arguments,
        public \PHPMailer\PHPMailer\PHPMailer $mail,
    ) {
    }

    public function withResult(bool $result): self
    {
        return new self($result, $this->to, $this->arguments, $this->mail);
    }
}
