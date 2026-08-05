<?php

declare(strict_types=1);

namespace Piwigo\Config;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Psr\Container\ContainerExceptionInterface;

/**
 * The handful of settings a sysadmin with filesystem access should have
 * final say over, regardless of what a (possibly compromised) web-admin
 * session does through the DB-backed settings UI -- Config generic-
 * accessor removal, design #2. Every real candidate here gates an actual
 * security/trust boundary, not just "no settings-page checkbox exists
 * yet" (that alone doesn't earn a key a spot on this class -- most of
 * CurrentConfig's DB-backed keys also lack a checkbox):
 *
 * - showPhpErrors/showPhpErrorsOnFrontend: whether PHP errors leak to
 *   public visitors (path/stack-trace disclosure).
 * - apacheAuthentication/externalAuthentification: both gate real
 *   authentication-bypass logic (UserBootstrap trusts Apache-supplied
 *   credentials directly; PasswordService skips its own password check
 *   when externalAuthentification() is on) -- letting a DB write flip
 *   either is a real privilege-escalation path, not just an inconvenience.
 * - allowedHosts: [SEC-29] Host-header poisoning guard in UrlService --
 *   which hostnames this deployment is actually reachable at is a
 *   deployment-topology fact, not a site preference.
 *
 * No DB row, ever, for these -- no ConfigService setter either, by
 * design. Never overlaps with CurrentConfig (DB) or DbCredentials (env):
 * every setting lives in exactly one place.
 *
 * Sourced from local/config/config.php, a typed PHP file that must
 * `return new DeploymentPolicy(...)` -- unlike the legacy
 * local/config/config.inc.php's `$conf['key'] = value;` array format, a
 * typo'd named argument here (`showPhpErorsOnFrontend: false`) is an
 * immediate `Error: Unknown named parameter`, not a silently-ignored
 * no-op. A deployment that doesn't need to lock anything simply has no
 * such file; every property's own default matches CurrentConfig's former
 * SCHEMA default for the same key, so removing this class's involvement
 * changes nothing.
 */
final class DeploymentPolicy
{
    public function __construct(
        public readonly int $showPhpErrors = 30719,
        public readonly bool $showPhpErrorsOnFrontend = true,
        public readonly bool $apacheAuthentication = false,
        public readonly bool $externalAuthentification = false,
        /**
         * @var list<string>
         */
        public readonly array $allowedHosts = [],
    ) {}

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection -- singleton/service-locator elimination
     * campaign, Phase 4. `DeploymentPolicy::class` is bound in
     * container.php as a `factory()` reading `Paths $paths` (autowired) --
     * shared/memoized for the container's lifetime like every other
     * autowired service, matching this method's former per-request memo
     * exactly. Falls back to a fresh, unmemoized all-defaults instance
     * when Kernel isn't booted, or when it's booted without a `Paths`
     * bound (the factory's own `Paths` autowiring attempt throws PHP-DI's
     * `InvalidDefinition` in that case, same as CurrentPaths::get()'s own
     * documented `has()`-is-unreliable-for-concrete-classes finding) --
     * real production code never hits either branch (every entry point
     * resolves a Paths before Kernel::boot() runs), but a pure-Unit test
     * exercising, say, UrlService standalone shouldn't have to bootstrap
     * one just to reach a config check this deep in its call graph.
     */
    public static function current(): self
    {
        if (! Kernel::isBooted()) {
            return new self();
        }

        try {
            $policy = Kernel::container()->get(self::class);
        } catch (ContainerExceptionInterface) {
            return new self();
        }

        if (! $policy instanceof self) {
            throw new LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $policy;
    }

    public static function load(Paths $paths): self
    {
        $file = $paths->local . 'config/config.php';
        if (! is_file($file)) {
            return new self();
        }

        $policy = include $file;
        if (! $policy instanceof self) {
            throw new LogicException(
                $file . ' must `return new ' . self::class . '(...)`, got ' . get_debug_type($policy) . '.'
            );
        }

        return $policy;
    }
}
