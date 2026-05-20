<?php

declare(strict_types=1);

namespace Piwigo\Mail;

/** An email address with an optional display name. */
final readonly class MailAddress
{
    public function __construct(
        public string $email,
        public string $name,
    ) {
    }

    /** Parse an RFC 822-style `Name <email>` string. */
    public static function fromRfc822(string $input): self
    {
        if (preg_match('/(.*)<(.*)>.*/', $input, $matches)) {
            return new self(trim($matches[2]), trim($matches[1]));
        }
        return new self(trim($input), '');
    }
}
