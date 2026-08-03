<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;

/**
 * Container-shared instance holding the authenticated-user-for-this-request
 * state (singleton/service-locator elimination campaign, Phase 5).
 *
 * `current()` is a memoized `@deprecated` transitional bridge for callers
 * not yet converted to constructor injection -- same "load once, read/write
 * many times per request" reasoning as `Translator`/`EventDispatcher`/
 * `PageState`/`ImageStdParams`: the not-booted fallback is memoized
 * (`self::$fallback ??= new self()`), not fresh-per-call, so a caller that
 * writes via `current()` in one call and reads via `current()` in a later
 * call sees the same instance. This also preserves `get()`'s existing
 * throw-if-uninitialized semantics exactly: a fresh fallback instance's
 * `$user` starts `null`, same as the pre-conversion static's own
 * `self::$instance`.
 *
 * `attachGlobals()` is called by `RequestBootstrap::finalize()`/
 * `bootConfigOnly()`/`CliBootstrap::buildApplication()` -- NOT from
 * Kernel itself, which is L1Infrastructure and (per deptrac's ruleset)
 * may only depend on L0Data, not upward on `Users`'
 * L2aCoreDomain.
 */
final class CurrentUser
{
    private static ?self $fallback = null;

    private ?User $user = null;

    /**
     * Legacy Coupling Retirement Phase 8, 8h: distinguishes "a real,
     * per-request user was resolved" from "attachGlobals() guest-seeded
     * this singleton because nothing else has run yet" -- isInitialized()
     * can't make that distinction, since attachGlobals() unconditionally
     * seeds a guest user on every bootstrap path, including CLI/plugin
     * `autoupdate` firings with no real request user. ActivityService::
     * record() needs the distinction: a truly-unresolved actor must record
     * as `null` (activity.performed_by's `ON DELETE SET NULL` foreign key
     * depends on it -- `0` is not a valid id, AUTO_INCREMENT starts at 1),
     * not the guest id.
     */
    private bool $realUserResolved = false;

    public static function current(): self
    {
        if (\Piwigo\Core\Kernel::isBooted()) {
            $instance = \Piwigo\Core\Kernel::container()->get(self::class);
            if (! $instance instanceof self) {
                throw new \LogicException('Container returned an unexpected type for ' . self::class);
            }

            return $instance;
        }

        return self::$fallback ??= new self();
    }

    /**
     * Initialises the instance with an empty guest user if not already
     * set. Idempotent -- a later real `set()` call is never clobbered by a
     * redundant `attachGlobals()`.
     */
    public function attachGlobals(): void
    {
        $this->user ??= new User(
            id: UserId::from(CurrentConfig::guestId()),
            username: '',
            email: '',
            language: AppInfo::DEFAULT_LANGUAGE,
            theme: AppInfo::DEFAULT_TEMPLATE,
            status: UserStatus::Guest,
            enabledHigh: false,
        );
    }

    public function isInitialized(): bool
    {
        return $this->user instanceof User;
    }

    public function get(): User
    {
        if (! $this->user instanceof User) {
            throw new \LogicException('CurrentUser not initialised -- call Kernel::boot() first.');
        }

        return $this->user;
    }

    public function set(User $user): void
    {
        $this->user = $user;
    }

    public function updateLanguage(string $language): void
    {
        $this->user = $this->get()
            ->withLanguage($language);
    }

    /**
     * Called by UserBootstrap::initialize() -- the only real per-request
     * user resolver -- right alongside its own set() call.
     */
    public function markRealUserResolved(): void
    {
        $this->realUserResolved = true;
    }

    public function wasRealUserResolved(): bool
    {
        return $this->realUserResolved;
    }

    /**
     * Monotonic (false -> true, never flipped back mid-request), unlike
     * most of this class's other request-scoped state -- needs an
     * explicit reset at the true start of each request
     * (RequestBootstrap::configure()), not folded into reset() below,
     * which is arch-test-restricted to tests/ and resets more than this
     * flag needs.
     */
    public function resetRealUserResolvedFlag(): void
    {
        $this->realUserResolved = false;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test (matching the same
     * test-isolation precedent as Config's and Kernel's own reset() methods).
     */
    public function reset(): void
    {
        $this->user = null;
        $this->realUserResolved = false;
    }
}
