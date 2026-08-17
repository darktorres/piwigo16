<?php

declare(strict_types=1);

namespace Piwigo\Users\Event;

use Piwigo\Common\ValueObject\Email;

/**
 * Typed event for the legacy `get_webmaster_mail_address` filter. No
 * handler is registered for it anywhere today. `$email` is nullable --
 * a webmaster without an email address on file is real, not a bug (see
 * `UserRepository::getWebmasterMailAddress()`'s own docblock). No context
 * -- every real call site passes only the email.
 */
final class GetWebmasterMailAddress
{
    public function __construct(
        public ?Email $email,
    ) {}
}
