<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Core\Kernel;
use Piwigo\Db\DbCredentials;
use Psr\Container\ContainerExceptionInterface;

/**
 * Returns the container-shared instance once Kernel has booted, or a
 * fresh, unmemoized `fromEnv()` read otherwise. Because the booted case
 * resolves the same container-shared instance every other
 * constructor-injected consumer holds, tests may safely chain
 * `->seed(...)`/`->reload()` onto this call to mutate the shared instance
 * in place.
 */
final class DbCredentialsTestFactory
{
    public static function get(): DbCredentials
    {
        if (Kernel::isBooted()) {
            try {
                $instance = Kernel::container()->get(DbCredentials::class);
                if ($instance instanceof DbCredentials) {
                    return $instance;
                }
            } catch (ContainerExceptionInterface) {
                // Falls through to fromEnv() below, a safe default.
            }
        }

        return DbCredentials::fromEnv();
    }
}
