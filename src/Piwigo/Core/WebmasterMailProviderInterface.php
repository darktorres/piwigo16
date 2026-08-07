<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Users\UserRepository` implements this interface and is bound in
 * `config/container.php`. `Piwigo\Mail\MailService` takes an instance via
 * an optional constructor parameter, lazily defaulting to the real
 * `UserRepository`; unlike MailService's other constructor parameters,
 * which are required, this one stays optional so unit tests can substitute
 * a fixed address without a DB connection.
 */
interface WebmasterMailProviderInterface
{
    /**
     * Returns the webmaster mail address depending on \Piwigo\Config\CurrentConfig::webmasterId().
     */
    public function getWebmasterMailAddress(): string;
}
