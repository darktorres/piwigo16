<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

/**
 * {@see \Piwigo\Mail\MailService::unformatEmail()}/{@see
 * \Piwigo\Mail\MailService::getCleanRecipientsList()}'s own fixed
 * (email, name) result shape.
 */
final readonly class EmailRecipient
{
    public function __construct(
        public string $email,
        public string $name,
    ) {}
}
