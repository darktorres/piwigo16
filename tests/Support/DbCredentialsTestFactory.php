<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Core\Kernel;
use Piwigo\Db\DbCredentials;
use Psr\Container\ContainerExceptionInterface;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-3:
 * replaces the deleted `DbCredentials::current()` transitional shim for
 * test call sites -- reproduces its exact behavior (the real
 * container-shared instance once Kernel has booted, a fresh, unmemoized
 * `fromEnv()` read otherwise). Several real Integration tests chain
 * `->seed(...)`/`->reload()` directly onto this call to mutate the shared
 * instance in place (e.g. RequestBootstrapBootEntryPointTest's own
 * tearDown()) -- safe here for the same reason it was safe on the shim:
 * once Kernel is booted, this resolves that exact same container-shared
 * instance every other constructor-injected consumer already holds.
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
                // Falls through to fromEnv() below -- same has()-is-
                // unreliable reasoning the shim's own former docblock
                // documented, not applicable here since this method
                // already has a real, safe default to fall back to.
            }
        }

        return DbCredentials::fromEnv();
    }
}
