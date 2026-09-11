<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Piwigo\Auth\CookieService;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Common\ValueObject\ThemeId;

/**
 * Namespaced cookie accessor handed out by `ExtensionContext::cookies()`,
 * mirroring `ExtensionSession`'s own shape exactly (per-extension
 * namespacing, a fresh instance per `PluginId`/`ThemeId`).
 *
 * `CookieService` itself deliberately has no generic `getCookieVar()`
 * reader (see its own docblock) -- each real caller gets a named,
 * correctly-narrowed accessor instead. This class doesn't break that rule:
 * it reads `$_COOKIE` directly, the same way `CookieService`'s own named
 * accessors (`getDisplayThumbnailPref()`, etc.) do, just namespaced per
 * extension rather than hardcoded to one specific key. Writes still go
 * through `CookieService::setCookieVar()`, which is already generic.
 */
final readonly class ExtensionCookie
{
    public function __construct(
        private CookieService $cookieService,
        private PluginId|ThemeId $extensionId,
    ) {}

    public function get(string $key): ?string
    {
        $value = $_COOKIE['pwg_' . $this->namespacedKey($key)] ?? null;

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $expire = null): bool
    {
        return $this->cookieService->setCookieVar($this->namespacedKey($key), $value, $expire);
    }

    public function remove(string $key): bool
    {
        return $this->cookieService->setCookieVar($this->namespacedKey($key), null);
    }

    private function namespacedKey(string $key): string
    {
        return 'ext_' . $this->extensionId->value . '_' . $key;
    }
}
