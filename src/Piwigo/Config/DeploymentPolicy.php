<?php

declare(strict_types=1);

namespace Piwigo\Config;

use LogicException;
use Piwigo\Core\Paths;

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
final readonly class DeploymentPolicy
{
    public function __construct(
        public int $showPhpErrors = 30719,
        public bool $showPhpErrorsOnFrontend = true,
        public bool $apacheAuthentication = false,
        public bool $externalAuthentification = false,
        /**
         * @var list<string>
         */
        public array $allowedHosts = [],
    ) {}

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
