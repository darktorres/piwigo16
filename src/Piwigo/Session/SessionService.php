<?php

declare(strict_types=1);

namespace Piwigo\Session;

use InvalidArgumentException;
use LogicException;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;

final readonly class SessionService
{
    public function __construct(
        private SessionRepository $repo,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * Container resolve, not a constructor property -- used only inside
     * sessionWrite()'s own ApiKeyRequestFlag::isActive() check below. A
     * required constructor param here would ripple across this class's
     * many real construction sites for the sake of one internal caller.
     * Falls back to a fresh, unmemoized `false`-active instance when
     * Kernel::boot() hasn't run.
     */
    private function apiKeyRequestFlag(): ApiKeyRequestFlag
    {
        if (Kernel::isBooted()) {
            $apiKeyRequestFlag = Kernel::container()->get(ApiKeyRequestFlag::class);
            if (! $apiKeyRequestFlag instanceof ApiKeyRequestFlag) {
                throw new LogicException('Container returned an unexpected type for ' . ApiKeyRequestFlag::class);
            }

            return $apiKeyRequestFlag;
        }

        return new ApiKeyRequestFlag();
    }

    /**
     * Generates a pseudo random string.
     * Characters used are a-z A-Z and numerical values.
     */
    public function generateKey(int $size): string
    {
        if ($size < 1) {
            throw new InvalidArgumentException('generateKey(): $size must be at least 1');
        }
        $bytes = random_bytes($size + 10);

        return substr(
            str_replace(['+', '/'], '', base64_encode($bytes)),
            0,
            $size,
        );
    }

    /**
     * Called by PHP session manager, always return true.
     */
    public function sessionOpen(): true
    {
        return true;
    }

    /**
     * Called by PHP session manager, always return true.
     */
    public function sessionClose(): true
    {
        return true;
    }

    /**
     * Returns a hash from the current user's IP address.
     */
    public function getRemoteAddrSessionHash(): string
    {
        return self::remoteAddrHash($this->currentConfig->sessionUseIpAddress);
    }

    /**
     * Pure computation behind getRemoteAddrSessionHash(), with the
     * "bind sessions to the client IP?" policy bit passed in explicitly so
     * SessionUserResolver (the i.php fast path) can share the exact
     * composite-key hash logic while sourcing the policy bit from the
     * legacy global $conf instead of CurrentConfig:: -- on that
     * fast-bootstrap path CurrentConfig::$data is populated from
     * ConfigLoader defaults/env only, never from local config overrides,
     * so CurrentConfig::sessionUseIpAddress() would not be authoritative
     * there.
     */
    public static function remoteAddrHash(bool $useIpAddress): string
    {
        if (! $useIpAddress) {
            return '';
        }

        $remoteAddr = IpAddress::fromRemoteAddr()->value ?? '';

        // A session can legitimately be initialized with no HTTP request
        // behind it (no real REMOTE_ADDR -- e.g. a CLI-driven
        // install/bootstrap flow): explode('.', '') yields a single-element
        // array, and vsprintf()
        // requires exactly 2 for '%02X%02X', throwing a ValueError instead
        // of the "no IP available" empty-string fallback this method
        // already uses for ipv6/no-REMOTE_ADDR below. Same pre-existing
        // gap in the original include/functions_session.inc.php (a bare
        // (string) cast of an unset $_SERVER['REMOTE_ADDR'] hits the
        // identical crash there too) -- never reachable in a real Apache
        // request (the web server always populates REMOTE_ADDR), only in
        // a session created outside one.
        $octets = explode('.', $remoteAddr);
        if (! str_contains($remoteAddr, ':') && count($octets) === 4) { // ipv4
            // Deliberately only the first 2 octets -- see
            // SessionServiceTest.php's own docblock for why this narrower
            // hash is the original, long-standing behavior, not something
            // to widen here.
            return vsprintf('%02X%02X', array_slice($octets, 0, 2));
        }

        return ''; // ipv6, or no real IP available, not yet
    }

    /**
     * Called by PHP session manager, retrieves data stored in the sessions table.
     */
    public function sessionRead(string $sessionId): string
    {
        return $this->repo->read($this->getRemoteAddrSessionHash() . $sessionId);
    }

    /**
     * Called by PHP session manager, writes data in the sessions table.
     */
    public function sessionWrite(string $sessionId, string $data): true
    {
        // when the request is authenticated via api_key (ApiKeyRequestFlag),
        // you do not want the session to be written to the database (no user
        // session persistence) -- this avoids polluting the session table
        // with stateless API accesses
        if ($this->apiKeyRequestFlag()->isActive()) {
            return true;
        }
        $this->repo->write($this->getRemoteAddrSessionHash() . $sessionId, $data);

        return true;
    }

    /**
     * Called by PHP session manager, deletes data in the sessions table.
     */
    public function sessionDestroy(string $sessionId): true
    {
        $this->repo->destroy($this->getRemoteAddrSessionHash() . $sessionId);

        return true;
    }

    /**
     * Called by PHP session manager, garbage collector for expired
     * sessions. Returns the number of expired sessions deleted.
     */
    public function sessionGc(): int
    {
        return $this->repo->gc($this->currentConfig->sessionLength);
    }

    /**
     * Persistently stores a variable for the current session. $value is
     * genuinely arbitrary by design -- the app writes any serializable PHP
     * value here deliberately, same generic-KV-bag rationale as
     * Piwigo\Core\ProcessCache.
     */
    public function setSessionVar(string $var, mixed $value): bool
    {
        if (! isset($_SESSION)) {
            return false;
        }
        $_SESSION['pwg_' . $var] = $value;

        return true;
    }

    /**
     * Generic counterpart to setSessionVar() -- every other getter in this
     * class is a bespoke, narrowly-typed accessor for one of core's own
     * known session keys, but PluginConfig\ExtensionSession needs
     * a genuinely arbitrary-key read to back a plugin/theme's own
     * namespaced session() store, the same generic-KV-bag rationale
     * setSessionVar() itself already documents.
     */
    public function getSessionVar(string $var): mixed
    {
        return $_SESSION['pwg_' . $var] ?? null;
    }

    /**
     * `filter_enabled` -- every real caller casts to bool with a `false`
     * default regardless of whether the stored value itself is truthy, so
     * both are baked in here (unlike the other accessors below, which stay
     * nullable).
     */
    public function getFilterEnabled(): bool
    {
        return (bool) ($_SESSION['pwg_filter_enabled'] ?? false);
    }

    /**
     * `filter_check_key` -- Filter\FilterService's own cache-validity
     * marker (`user`/`recent_period`/`time`/`date`). Null for anything
     * that isn't a real array carrying all 4 keys, replacing that same
     * `is_array() + isset() on all 4 keys` guard the one real caller used
     * to duplicate itself.
     *
     * @return array{user: mixed, recent_period: mixed, time: mixed, date: mixed}|null
     */
    public function getFilterCheckKey(): ?array
    {
        $value = $_SESSION['pwg_filter_check_key'] ?? null;
        if (! is_array($value) || ! isset($value['user'], $value['recent_period'], $value['time'], $value['date'])) {
            return null;
        }

        return [
            'user' => $value['user'],
            'recent_period' => $value['recent_period'],
            'time' => $value['time'],
            'date' => $value['date'],
        ];
    }

    /**
     * `filter_categories` -- Filter\FilterService stores this as an
     * already-serialize()'d string; the one real caller still owns its own
     * unserialize() + array-shape validation, this only narrows the raw
     * session read.
     */
    public function getFilterCategoriesSerialized(): ?string
    {
        $value = $_SESSION['pwg_filter_categories'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `filter_visible_categories` -- Filter\FilterService writes either an
     * imploded CSV string or the literal {@see \Piwigo\Db\NoMatchSentinel::ID}
     * (see that class's own "must be not empty" comment) -- `is_scalar()`
     * narrowing (not `is_string()`) preserves that sentinel as
     * {@see \Piwigo\Db\NoMatchSentinel::ID_STRING} instead of discarding
     * it as a wrong type.
     */
    public function getFilterVisibleCategories(): ?string
    {
        $value = $_SESSION['pwg_filter_visible_categories'] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * `filter_visible_images` -- same {@see \Piwigo\Db\NoMatchSentinel}-preserving
     * contract as {@see getFilterVisibleCategories()}.
     */
    public function getFilterVisibleImages(): ?string
    {
        $value = $_SESSION['pwg_filter_visible_images'] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * `device` -- Core\DeviceHelper::getDevice()'s own cached
     * mobile/tablet/desktop detection result.
     */
    public function getDeviceVar(): ?string
    {
        $value = $_SESSION['pwg_device'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `mobile_theme` -- Core\DeviceHelper::mobileTheme()'s own cached
     * decision. `is_bool()` strict narrowing (not a `(bool)` cast) matches
     * the one real caller's own check exactly -- every real write is
     * already a genuine bool, so a stored non-bool means corrupted/foreign
     * session state, not a value worth coercing.
     */
    public function getMobileThemeVar(): ?bool
    {
        $value = $_SESSION['pwg_mobile_theme'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * `index_deriv` -- Category\CategoryDefaultRenderer's own selected
     * derivative size for the index thumbnail grid.
     */
    public function getIndexDeriv(): ?string
    {
        $value = $_SESSION['pwg_index_deriv'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `plugins_show_details` -- Admin\PluginsInstalledPageRenderer's own
     * "show details" toggle. `is_bool()` strict narrowing: every real
     * write is a genuine bool (Admin\Request\PluginsInstalledDisplayRequest::
     * $showDetails), so this stays a presence check plus a type guard, not
     * a cast.
     */
    public function getPluginsShowDetails(): ?bool
    {
        $value = $_SESSION['pwg_plugins_show_details'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * `plugins_new_order` -- Admin\PluginsNewPageRenderer's own sort-order
     * choice for the "new plugins" listing. Null for an empty string too,
     * matching the one real caller's own `$x !== ''` check.
     */
    public function getPluginsNewOrder(): ?string
    {
        $value = $_SESSION['pwg_plugins_new_order'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * `comments_order` -- Picture\PictureCommentRenderer's own sort-order
     * choice for the comment list.
     */
    public function getCommentsOrder(): ?string
    {
        $value = $_SESSION['pwg_comments_order'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `image_order` -- always written as a real int (index.php is the only
     * writer); `is_numeric()` narrowing here replaces the same guard 2 real
     * callers used to duplicate for themselves.
     */
    public function getImageOrder(): ?int
    {
        $value = $_SESSION['pwg_image_order'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * `show_metadata` -- a pure presence flag (Controller\PictureController
     * only ever checks whether this key exists, never reads its stored
     * value, which is always the literal `1`), so this is a boolean
     * existence check, not a value accessor.
     */
    public function isShowMetadataEnabled(): bool
    {
        return isset($_SESSION['pwg_show_metadata']);
    }

    /**
     * `referer_image_id` -- Controller\PictureController's own
     * same-picture-repeat-view hit-counting guard.
     */
    public function getRefererImageId(): ?int
    {
        $value = $_SESSION['pwg_referer_image_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * `picture_deriv` -- Controller\PictureController's own selected
     * derivative size for the picture view / prefetch link.
     */
    public function getPictureDeriv(): ?string
    {
        $value = $_SESSION['pwg_picture_deriv'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Deletes a persistent variable for the current session.
     */
    public function unsetSessionVar(string $var): bool
    {
        if (! isset($_SESSION)) {
            return false;
        }
        unset($_SESSION['pwg_' . $var]);

        return true;
    }

    /**
     * Delete all sessions for a given user (certainly deleted).
     */
    public function deleteUserSessions(int $userId): void
    {
        $this->repo->deleteByUserId($userId);
    }
}
