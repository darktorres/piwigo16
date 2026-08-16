<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * "This request is past the install-check gate" marker -- typed
 * replacement for the raw PHPWG_INSTALLED constant, fully retiring it the
 * same way Piwigo\Core\AdminContext/WsContext fully retire IN_ADMIN/IN_WS:
 * no raw-constant fallback, `mark()` is the only way `isActive()` ever
 * becomes true.
 *
 * A container-shared instance. `RequestBootstrap` (the normal request
 * path's writer) and `public/install.php` (the install path's writer, via
 * `RequestBootstrap::installationFlag()`) both call `mark()` through this
 * same accessor. `Piwigo\Core\Lang`, `Piwigo\Users\UserService` and
 * `Piwigo\Http\SessionBootstrap` all constructor-inject it directly to
 * read `isActive()`.
 */
final class InstallationFlag
{
    private bool $marked = false;

    /**
     * Called once from RequestBootstrap::bootEntryPoint(), right after
     * configure() -- the same point the former `defined('PHPWG_INSTALLED')
     * or define('PHPWG_INSTALLED', true);` guard sat in the now-deleted
     * include/common.inc.php seam file. Also called from
     * public/install.php, right before performInstall(), the same point
     * that file's own former raw define() sat.
     */
    public function mark(): void
    {
        $this->marked = true;
    }

    public function isActive(): bool
    {
        return $this->marked;
    }

    /**
     * Test-only -- production code never needs to clear this mid-request.
     * No arch test needed (unlike the old static `reset()`): reaching this
     * instance method at all requires holding a real reference to this
     * exact instance, which production code has no reason to do.
     */
    public function reset(): void
    {
        $this->marked = false;
    }
}
