<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Session\SessionService;

/**
 * Namespaced `$_SESSION` accessor handed out by `ExtensionContext::
 * session()`. Real callers persist across requests via `$_SESSION`
 * (`language_switch`'s real `pwg_set_session_var('lang_switch', ...)`/
 * `pwg_get_session_var('lang_switch', ...)` calls for a guest/generic
 * user -- traced from `../piwigo16-plugins/language_switch_16.3.0/
 * language_switch.inc.php`), not per-request scratch state, so this
 * wraps `SessionService`'s own real `$_SESSION` gateway rather than
 * anything request-scoped.
 *
 * Namespacing is real, not just documented convention: `ExtensionContext`
 * constructs a **fresh** instance of this class per plugin/theme,
 * parameterized by that extension's own `PluginId`/`ThemeId`
 * (`PluginRegistry`/`ThemeRegistry`, P27.3), so two extensions writing
 * the same bare key (`session()->set('key', ...)`) never collide --
 * see this fork's own P27 plan for the test that locks this in.
 */
final readonly class ExtensionSession
{
    public function __construct(
        private SessionService $sessionService,
        private PluginId|ThemeId $extensionId,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->sessionService->getSessionVar($this->namespacedKey($key));

        return $value ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->sessionService->setSessionVar($this->namespacedKey($key), $value);
    }

    public function remove(string $key): void
    {
        $this->sessionService->unsetSessionVar($this->namespacedKey($key));
    }

    private function namespacedKey(string $key): string
    {
        return 'ext_' . $this->extensionId->value . '_' . $key;
    }
}
