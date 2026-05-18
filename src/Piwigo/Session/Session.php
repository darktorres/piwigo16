<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Image\DerivativeSize;

/**
 * Typed, request-scoped session value object.
 *
 * Replaces direct `$_SESSION[...]` access for every canonical Piwigo
 * session key. Each slot has a definite type; downstream code never
 * narrows raw mixed data out of `$_SESSION`.
 *
 * **Lifecycle.** `fromSuperglobal($_SESSION)` once at request start (in
 * SessionMiddleware once wired), consumers mutate the object during the
 * request, `persistInto($_SESSION)` once at request end. The bag of
 * canonical keys is closed: Session owns exactly the keys listed below.
 * Anything else in `$_SESSION` (plugin scratch, legacy keys) is left
 * untouched by both hydrate and persist — co-existence is intentional
 * during the F5-c rollout, and v17's plugin rewrite eventually replaces
 * plugin `$_SESSION` writes with a dedicated plugin-storage API.
 *
 * **Mutability.** Public typed properties (Symfony-style), not readonly
 * with `withX()`. Session represents the evolving request state, not a
 * value object identity — request-scoped accumulators (`flash->add()`,
 * `$filterCategories[] = ...`) are the natural mutation pattern and a
 * readonly variant would mean every mutation site has to rebind the
 * container. That trade-off doesn't pay off for this domain.
 *
 * **FlashBag** is held by composition (`$flash`), not conflated. Flash
 * messages have a different lifecycle (write request N, consume N+1)
 * than session state (persists until logout).
 */
final class Session
{
    // -- Auth / identity ---------------------------------------------------
    public ?UserId $userId            = null;   // pwg_uid
    public ?string $connectedWith     = null;   // connected_with (login method tag)
    /** @var array<mixed>|null Admin "view as user" impersonation state. */
    public ?array $fakeUserCache      = null;   // fake_user_cache

    // -- UI preferences ----------------------------------------------------
    public ?DerivativeSize $indexDeriv   = null;   // pwg_index_deriv
    public ?DerivativeSize $pictureDeriv = null;   // pwg_picture_deriv
    public ?ThemeId        $mobileTheme  = null;   // pwg_mobile_theme
    public ?string         $device       = null;   // pwg_device (detected device type)
    public ?string         $commentsOrder = null;  // pwg_comments_order
    public ?string         $imageOrder    = null;  // pwg_image_order
    public bool            $showMetadata  = false; // pwg_show_metadata
    public bool            $filterEnabled = false; // pwg_filter_enabled
    public ?ImageId        $refererImageId = null; // pwg_referer_image_id
    public ?string         $pluginsShowDetails = null; // pwg_plugins_show_details
    public ?string         $pluginsNewOrder    = null; // pwg_plugins_new_order

    // -- Filter state ------------------------------------------------------
    public ?string $filterCheckKey = null;          // pwg_filter_check_key
    /** @var list<int> */
    public array $filterCategories = [];            // pwg_filter_categories
    /** @var list<int> */
    public array $filterVisibleCategories = [];     // pwg_filter_visible_categories
    /** @var list<int> */
    public array $filterVisibleImages = [];         // pwg_filter_visible_images

    // -- Admin / batch -----------------------------------------------------
    /** @var array<mixed>|null */
    public ?array $bulkManagerFilter = null;        // bulk_manager_filter
    /** @var array<mixed>|null */
    public ?array $editContext = null;              // edit_context

    // -- Upgrade / install -------------------------------------------------
    /** @var list<string> */
    public array $extensionsNeedUpdate = [];        // extensions_need_update
    /**
     * Version the user dismissed the upgrade banner for. Replaces the
     * historical dynamic key `'need_update' . AppInfo::VERSION` — single
     * slot, simpler lifecycle, callers compare `=== AppInfo::VERSION`.
     */
    public ?string $dismissedUpgradeVersion = null;
    public bool $noPhotoYet = false;                // no_photo_yet

    // -- Password reset ----------------------------------------------------
    public ?string $resetPasswordCode = null;       // reset_password_code
    /**
     * Active valid-reset-code payload (array shape is owned by PasswordService:
     * `['code' => string, 'mail_address' => string, 'pwg_uid' => int, …]`).
     * Null means "no valid code currently issued"; legacy callers detect this
     * with `isset($_SESSION['valid_reset_password_code'])`.
     *
     * @var array<mixed>|null
     */
    public ?array $validResetPasswordCode = null;   // valid_reset_password_code

    // -- Upload ------------------------------------------------------------
    /** @var array<mixed>|null */
    public ?array $uploadsError = null;             // uploads_error
    public bool $uploadHideWarnings = false;        // upload_hide_warnings

    // -- Flash messages ----------------------------------------------------
    public readonly FlashBag $flash;

    public function __construct(?FlashBag $flash = null)
    {
        $this->flash = $flash ?? FlashBag::empty();
    }

    /**
     * Hydrate from a `$_SESSION`-shaped array. Only canonical keys are
     * read; unknown keys (plugin scratch, legacy) are ignored.
     *
     * @param array<mixed> $raw
     */
    public static function fromSuperglobal(array $raw): self
    {
        $s = new self(self::hydrateFlashBag($raw));

        $s->userId        = UserId::tryFrom($raw['pwg_uid'] ?? null);
        $s->connectedWith = is_string($raw['connected_with'] ?? null) ? $raw['connected_with'] : null;
        $s->fakeUserCache = is_array($raw['fake_user_cache'] ?? null) ? $raw['fake_user_cache'] : null;

        $s->indexDeriv   = self::tryDerivativeSize($raw['pwg_index_deriv'] ?? null);
        $s->pictureDeriv = self::tryDerivativeSize($raw['pwg_picture_deriv'] ?? null);
        $s->mobileTheme  = ThemeId::tryFrom($raw['pwg_mobile_theme'] ?? null);
        $s->device       = is_string($raw['pwg_device'] ?? null) ? $raw['pwg_device'] : null;
        $s->commentsOrder = is_string($raw['pwg_comments_order'] ?? null) ? $raw['pwg_comments_order'] : null;
        $s->imageOrder    = is_string($raw['pwg_image_order'] ?? null) ? $raw['pwg_image_order'] : null;
        $s->showMetadata  = (bool) ($raw['pwg_show_metadata'] ?? false);
        $s->filterEnabled = (bool) ($raw['pwg_filter_enabled'] ?? false);
        $s->refererImageId = ImageId::tryFrom($raw['pwg_referer_image_id'] ?? null);
        $s->pluginsShowDetails = is_string($raw['pwg_plugins_show_details'] ?? null) ? $raw['pwg_plugins_show_details'] : null;
        $s->pluginsNewOrder    = is_string($raw['pwg_plugins_new_order'] ?? null) ? $raw['pwg_plugins_new_order'] : null;

        $s->filterCheckKey            = is_string($raw['pwg_filter_check_key'] ?? null) ? $raw['pwg_filter_check_key'] : null;
        $s->filterCategories          = self::intList($raw['pwg_filter_categories'] ?? null);
        $s->filterVisibleCategories   = self::intList($raw['pwg_filter_visible_categories'] ?? null);
        $s->filterVisibleImages       = self::intList($raw['pwg_filter_visible_images'] ?? null);

        $s->bulkManagerFilter = is_array($raw['bulk_manager_filter'] ?? null) ? $raw['bulk_manager_filter'] : null;
        $s->editContext       = is_array($raw['edit_context'] ?? null) ? $raw['edit_context'] : null;

        $s->extensionsNeedUpdate     = self::stringList($raw['extensions_need_update'] ?? null);
        $s->dismissedUpgradeVersion  = is_string($raw['dismissed_upgrade_version'] ?? null) ? $raw['dismissed_upgrade_version'] : null;
        $s->noPhotoYet               = (bool) ($raw['no_photo_yet'] ?? false);

        $s->resetPasswordCode      = is_string($raw['reset_password_code'] ?? null) ? $raw['reset_password_code'] : null;
        $s->validResetPasswordCode = is_array($raw['valid_reset_password_code'] ?? null) ? $raw['valid_reset_password_code'] : null;

        $s->uploadsError       = is_array($raw['uploads_error'] ?? null) ? $raw['uploads_error'] : null;
        $s->uploadHideWarnings = (bool) ($raw['upload_hide_warnings'] ?? false);

        return $s;
    }

    /**
     * Persist only the flash-bag slots. Used by SessionMiddleware during
     * the F5-c migration window: until every consumer that mutates a
     * canonical Session slot has migrated, a full persistInto() would
     * clobber raw `$_SESSION` writes made by unmigrated code (e.g.
     * AuthService writing `pwg_uid` directly during login). The flash
     * keys are safe — they're now exclusively owned by Session::$flash.
     *
     * Once F5-c is complete the SessionMiddleware switch to persistInto()
     * is a one-line change.
     *
     * @param array<mixed> $target
     */
    public function persistFlashInto(array &$target): void
    {
        $flashByKind = $this->flash->toArray();
        $this->writeListOrUnset($target, 'page_infos', $flashByKind['info'] ?? []);
        $this->writeListOrUnset($target, 'page_errors', $flashByKind['error'] ?? []);
        $this->writeListOrUnset($target, 'message_tags', $flashByKind['tag'] ?? []);
    }

    /**
     * Serialize back into a `$_SESSION`-shaped array. Only canonical keys
     * are written; pre-existing unknown keys in `$target` are left in
     * place so plugin / legacy scratch survives the round-trip.
     *
     * Not yet wired into SessionMiddleware — see persistFlashInto() above
     * for the migration-window persistence path.
     *
     * @param array<mixed> $target
     */
    public function persistInto(array &$target): void
    {
        $this->writeOrUnset($target, 'pwg_uid', $this->userId?->value);
        $this->writeOrUnset($target, 'connected_with', $this->connectedWith);
        $this->writeOrUnset($target, 'fake_user_cache', $this->fakeUserCache);

        $this->writeOrUnset($target, 'pwg_index_deriv', $this->indexDeriv?->value);
        $this->writeOrUnset($target, 'pwg_picture_deriv', $this->pictureDeriv?->value);
        $this->writeOrUnset($target, 'pwg_mobile_theme', $this->mobileTheme?->value);
        $this->writeOrUnset($target, 'pwg_device', $this->device);
        $this->writeOrUnset($target, 'pwg_comments_order', $this->commentsOrder);
        $this->writeOrUnset($target, 'pwg_image_order', $this->imageOrder);
        // Booleans are persisted only when true: legacy callers detect "on"
        // via isset(), so writing false would falsely make the key look set.
        $this->writeBoolFlag($target, 'pwg_show_metadata', $this->showMetadata);
        $this->writeBoolFlag($target, 'pwg_filter_enabled', $this->filterEnabled);
        $this->writeOrUnset($target, 'pwg_referer_image_id', $this->refererImageId?->value);
        $this->writeOrUnset($target, 'pwg_plugins_show_details', $this->pluginsShowDetails);
        $this->writeOrUnset($target, 'pwg_plugins_new_order', $this->pluginsNewOrder);

        $this->writeOrUnset($target, 'pwg_filter_check_key', $this->filterCheckKey);
        $this->writeListOrUnset($target, 'pwg_filter_categories', $this->filterCategories);
        $this->writeListOrUnset($target, 'pwg_filter_visible_categories', $this->filterVisibleCategories);
        $this->writeListOrUnset($target, 'pwg_filter_visible_images', $this->filterVisibleImages);

        $this->writeOrUnset($target, 'bulk_manager_filter', $this->bulkManagerFilter);
        $this->writeOrUnset($target, 'edit_context', $this->editContext);

        $this->writeListOrUnset($target, 'extensions_need_update', $this->extensionsNeedUpdate);
        $this->writeOrUnset($target, 'dismissed_upgrade_version', $this->dismissedUpgradeVersion);
        $this->writeBoolFlag($target, 'no_photo_yet', $this->noPhotoYet);

        $this->writeOrUnset($target, 'reset_password_code', $this->resetPasswordCode);
        $this->writeOrUnset($target, 'valid_reset_password_code', $this->validResetPasswordCode);

        $this->writeOrUnset($target, 'uploads_error', $this->uploadsError);
        $this->writeBoolFlag($target, 'upload_hide_warnings', $this->uploadHideWarnings);

        // Flash bag splits into its three legacy keys for backward-compatible
        // hydration on the next request — until the renderer consumer migrates
        // to consume($kind) directly.
        $flashByKind = $this->flash->toArray();
        $this->writeListOrUnset($target, 'page_infos', $flashByKind['info'] ?? []);
        $this->writeListOrUnset($target, 'page_errors', $flashByKind['error'] ?? []);
        $this->writeListOrUnset($target, 'message_tags', $flashByKind['tag'] ?? []);
    }

    /** Reset every typed slot to its default — used by logout. */
    public function logout(): void
    {
        $this->userId               = null;
        $this->connectedWith        = null;
        $this->fakeUserCache        = null;
        $this->indexDeriv           = null;
        $this->pictureDeriv         = null;
        $this->mobileTheme          = null;
        $this->device               = null;
        $this->commentsOrder        = null;
        $this->imageOrder           = null;
        $this->showMetadata         = false;
        $this->filterEnabled        = false;
        $this->refererImageId       = null;
        $this->pluginsShowDetails   = null;
        $this->pluginsNewOrder      = null;
        $this->filterCheckKey       = null;
        $this->filterCategories     = [];
        $this->filterVisibleCategories = [];
        $this->filterVisibleImages  = [];
        $this->bulkManagerFilter    = null;
        $this->editContext          = null;
        $this->extensionsNeedUpdate = [];
        $this->dismissedUpgradeVersion = null;
        $this->noPhotoYet           = false;
        $this->resetPasswordCode    = null;
        $this->validResetPasswordCode = null;
        $this->uploadsError         = null;
        $this->uploadHideWarnings   = false;
    }

    // -- internal helpers --------------------------------------------------

    /** @param array<mixed> $raw */
    private static function hydrateFlashBag(array $raw): FlashBag
    {
        $messages = [];
        $info  = self::stringList($raw['page_infos'] ?? null);
        $error = self::stringList($raw['page_errors'] ?? null);
        $tag   = self::stringList($raw['message_tags'] ?? null);
        if ($info !== []) {
            $messages['info']  = $info;
        }
        if ($error !== []) {
            $messages['error'] = $error;
        }
        if ($tag !== []) {
            $messages['tag']   = $tag;
        }
        return FlashBag::fromArray($messages);
    }

    private static function tryDerivativeSize(mixed $value): ?DerivativeSize
    {
        return is_string($value) ? DerivativeSize::tryFrom($value) : null;
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_int($v)) {
                $out[] = $v;
            } elseif (is_string($v) && ctype_digit($v)) {
                $out[] = (int) $v;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Write the value at $key, or unset the key if value is null. Keeps
     * `$_SESSION` from accumulating null markers for slots that aren't set.
     *
     * @param array<mixed> $target
     */
    private function writeOrUnset(array &$target, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($target[$key]);
            return;
        }
        $target[$key] = $value;
    }

    /**
     * Same as writeOrUnset but for list-typed slots — an empty list also
     * unsets the key (matches the historical "absent means empty" idiom).
     *
     * @param array<mixed> $target
     * @param list<mixed>  $value
     */
    private function writeListOrUnset(array &$target, string $key, array $value): void
    {
        if ($value === []) {
            unset($target[$key]);
            return;
        }
        $target[$key] = $value;
    }

    /**
     * Persist a "flag" boolean: write `true` when on, unset when off. Matches
     * the legacy session idiom where `isset($_SESSION[$key])` means the flag
     * is set; downstream consumers that still use `isset()` see consistent
     * truthiness with the typed slot.
     *
     * @param array<mixed> $target
     */
    private function writeBoolFlag(array &$target, string $key, bool $value): void
    {
        if ($value) {
            $target[$key] = true;
            return;
        }
        unset($target[$key]);
    }
}
