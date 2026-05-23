<?php

declare(strict_types=1);

namespace Piwigo\Exception;

final class AuthException extends PiwigoException
{
    public static function accountLocked(): self
    {
        return new self('Account temporarily locked due to repeated failed logins');
    }

    public static function rateLimited(): self
    {
        return new self('Too many login attempts; please retry shortly');
    }
}
