<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

/**
 * {@see \Piwigo\Mail\MailService}'s `generate*Mail()` methods (reset
 * password, set password, code verification, reset-password success) all
 * share this fixed result shape, fed straight into {@see
 * \Piwigo\Mail\MailService::mail()}'s wider `$args` options bag.
 */
final readonly class MailContent
{
    public function __construct(
        public string $subject,
        public string $content,
        public string $contentFormat,
    ) {}
}
