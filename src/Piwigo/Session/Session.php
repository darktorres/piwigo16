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
    /**
     * Pending extension updates keyed by extension type (plugins / themes /
     * languages) → extension id → latest revision name. Null when the cache
     * is uninitialized; empty array when initialized but no updates. Slot
     * type is loose (`array<mixed>|null`) to match what `is_array()` returns
     * out of `$_SESSION`; the nested-shape contract is owned by
     * `Admin\Updates` and `Ws\Method\ExtensionsEndpoints`.
     *
     * @var array<mixed>|null
     */
    public ?array $extensionsNeedUpdate = null;     // extensions_need_update
    /**
     * Cached result of the last Piwigo-version upgrade check: true when a
     * newer core version is available, false when up to date, null when the
     * check hasn't run this session. Replaces the historical dynamic key
     * `'need_update' . AppInfo::VERSION` — the version-keyed cache busts
     * automatically when AppInfo::VERSION changes (deploy / upgrade).
     */
    public ?bool $piwigoNeedsUpdate = null;
    /**
     * Mode flag controlling the "no photos yet" admin onboarding banner.
     * Only documented value today is `'browse'` (user dismissed the banner
     * by clicking Browse). Null means the banner has not been dismissed.
     * Legacy callers gate behavior on isset().
     */
    public ?string $noPhotoYet = null;              // no_photo_yet

    // -- Password reset ----------------------------------------------------
    /**
     * Pending password-reset code payload owned by PasswordService:
     * `['secret' => string, 'attempts' => int, 'user_id' => ?int, 'created_at' => int, 'ttl' => int]`.
     * Null means "no reset code in flight".
     *
     * @var array<mixed>|null
     */
    public ?array $resetPasswordCode = null;        // reset_password_code
    /**
     * Validated reset-code payload owned by PasswordService:
     * `['user_id' => int, 'username' => string, 'email' => string, 'language' => string]`.
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

    /**
     * One-shot success message displayed under the tags section of admin
     * tools (e.g. "Orphan tags deleted"). Single string, not a flash list —
     * the legacy session shape is scalar and the template assigns it as
     * a single template variable.
     */
    public ?string $messageTags = null;             // message_tags

    // -- Flash messages ----------------------------------------------------
    public readonly FlashBag $flash;

    /**
     * Frozen serialization of Session state at hydration time. persistInto()
     * diffs the current state against this and writes only what changed —
     * unmigrated consumers' raw `$_SESSION` writes survive untouched because
     * Session has no snapshot entry for keys it doesn't own.
     *
     * @var array<string, mixed>
     */
    private array $snapshot = [];

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

        $s->extensionsNeedUpdate     = is_array($raw['extensions_need_update'] ?? null) ? $raw['extensions_need_update'] : null;
        $s->piwigoNeedsUpdate        = is_bool($raw['piwigo_needs_update'] ?? null) ? $raw['piwigo_needs_update'] : null;
        $s->noPhotoYet               = is_string($raw['no_photo_yet'] ?? null) ? $raw['no_photo_yet'] : null;

        $s->resetPasswordCode      = is_array($raw['reset_password_code'] ?? null) ? $raw['reset_password_code'] : null;
        $s->validResetPasswordCode = is_array($raw['valid_reset_password_code'] ?? null) ? $raw['valid_reset_password_code'] : null;

        $s->uploadsError       = is_array($raw['uploads_error'] ?? null) ? $raw['uploads_error'] : null;
        $s->uploadHideWarnings = (bool) ($raw['upload_hide_warnings'] ?? false);
        $s->messageTags        = is_string($raw['message_tags'] ?? null) ? $raw['message_tags'] : null;

        $s->snapshot = $s->currentState();
        return $s;
    }

    /**
     * Serialize Session state back into a `$_SESSION`-shaped array.
     *
     * Snapshot-diff semantics: only the keys whose value differs from the
     * hydration snapshot are written. Keys absent from both snapshot and
     * current state are left alone. Keys present in snapshot but absent
     * from current state are unset. Unknown keys in `$target` (plugin
     * scratch, raw `$_SESSION` writes by unmigrated consumers) are never
     * touched — Session has no snapshot entry to diff against, so it
     * makes no changes.
     *
     * This is what makes the F5-c migration window safe: AuthService can
     * still write `$_SESSION['pwg_uid']` directly during login; Session's
     * `userId` slot is unchanged from its initial hydration, so the
     * diff is empty and persistInto leaves `pwg_uid` alone.
     *
     * @param array<mixed> $target
     */
    public function persistInto(array &$target): void
    {
        $current = $this->currentState();

        // Union of canonical keys that appear in either side of the diff.
        $allKeys = array_unique([...array_keys($this->snapshot), ...array_keys($current)]);

        foreach ($allKeys as $key) {
            $hasCurrent  = array_key_exists($key, $current);
            $hasSnapshot = array_key_exists($key, $this->snapshot);

            if ($hasCurrent && $hasSnapshot && $current[$key] === $this->snapshot[$key]) {
                continue;   // unchanged — leave target alone.
            }

            if ($hasCurrent) {
                $target[$key] = $current[$key];
            } else {
                unset($target[$key]);
            }
        }
    }

    /**
     * Project the current Session state to a `$_SESSION`-shaped map.
     *
     * Encoding rules per slot type:
     *   - nullable scalar / VO / array: present iff non-null.
     *   - bool flag: present iff true (matches `isset()` semantics legacy
     *     callers rely on).
     *   - list<T>: present iff non-empty.
     *   - flash bag: split into three legacy keys (page_infos / page_errors /
     *     message_tags), present iff non-empty.
     *
     * Used both as the hydration snapshot and as the "current" side of
     * the persistInto() diff.
     *
     * @return array<string, mixed>
     */
    private function currentState(): array
    {
        $state = [];

        if ($this->userId !== null) {
            $state['pwg_uid'] = $this->userId->value;
        }
        if ($this->connectedWith !== null) {
            $state['connected_with'] = $this->connectedWith;
        }
        if ($this->fakeUserCache !== null) {
            $state['fake_user_cache'] = $this->fakeUserCache;
        }
        if ($this->indexDeriv !== null) {
            $state['pwg_index_deriv'] = $this->indexDeriv->value;
        }
        if ($this->pictureDeriv !== null) {
            $state['pwg_picture_deriv'] = $this->pictureDeriv->value;
        }
        if ($this->mobileTheme !== null) {
            $state['pwg_mobile_theme'] = $this->mobileTheme->value;
        }
        if ($this->device !== null) {
            $state['pwg_device'] = $this->device;
        }
        if ($this->commentsOrder !== null) {
            $state['pwg_comments_order'] = $this->commentsOrder;
        }
        if ($this->imageOrder !== null) {
            $state['pwg_image_order'] = $this->imageOrder;
        }
        if ($this->showMetadata) {
            $state['pwg_show_metadata'] = true;
        }
        if ($this->filterEnabled) {
            $state['pwg_filter_enabled'] = true;
        }
        if ($this->refererImageId !== null) {
            $state['pwg_referer_image_id'] = $this->refererImageId->value;
        }
        if ($this->pluginsShowDetails !== null) {
            $state['pwg_plugins_show_details'] = $this->pluginsShowDetails;
        }
        if ($this->pluginsNewOrder !== null) {
            $state['pwg_plugins_new_order'] = $this->pluginsNewOrder;
        }
        if ($this->filterCheckKey !== null) {
            $state['pwg_filter_check_key'] = $this->filterCheckKey;
        }
        if ($this->filterCategories !== []) {
            $state['pwg_filter_categories'] = $this->filterCategories;
        }
        if ($this->filterVisibleCategories !== []) {
            $state['pwg_filter_visible_categories'] = $this->filterVisibleCategories;
        }
        if ($this->filterVisibleImages !== []) {
            $state['pwg_filter_visible_images'] = $this->filterVisibleImages;
        }
        if ($this->bulkManagerFilter !== null) {
            $state['bulk_manager_filter'] = $this->bulkManagerFilter;
        }
        if ($this->editContext !== null) {
            $state['edit_context'] = $this->editContext;
        }
        if ($this->extensionsNeedUpdate !== null) {
            $state['extensions_need_update'] = $this->extensionsNeedUpdate;
        }
        if ($this->piwigoNeedsUpdate !== null) {
            $state['piwigo_needs_update'] = $this->piwigoNeedsUpdate;
        }
        if ($this->noPhotoYet !== null) {
            $state['no_photo_yet'] = $this->noPhotoYet;
        }
        if ($this->resetPasswordCode !== null) {
            $state['reset_password_code'] = $this->resetPasswordCode;
        }
        if ($this->validResetPasswordCode !== null) {
            $state['valid_reset_password_code'] = $this->validResetPasswordCode;
        }
        if ($this->uploadsError !== null) {
            $state['uploads_error'] = $this->uploadsError;
        }
        if ($this->uploadHideWarnings) {
            $state['upload_hide_warnings'] = true;
        }
        if ($this->messageTags !== null) {
            $state['message_tags'] = $this->messageTags;
        }

        $flashByKind = $this->flash->toArray();
        if (($flashByKind['info'] ?? []) !== []) {
            $state['page_infos'] = $flashByKind['info'];
        }
        if (($flashByKind['error'] ?? []) !== []) {
            $state['page_errors'] = $flashByKind['error'];
        }

        return $state;
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
        $this->extensionsNeedUpdate = null;
        $this->piwigoNeedsUpdate    = null;
        $this->noPhotoYet           = null;
        $this->resetPasswordCode    = null;
        $this->validResetPasswordCode = null;
        $this->uploadsError         = null;
        $this->uploadHideWarnings   = false;
        $this->messageTags          = null;
    }

    // -- internal helpers --------------------------------------------------

    /** @param array<mixed> $raw */
    private static function hydrateFlashBag(array $raw): FlashBag
    {
        $messages = [];
        $info  = self::stringList($raw['page_infos'] ?? null);
        $error = self::stringList($raw['page_errors'] ?? null);
        if ($info !== []) {
            $messages['info'] = $info;
        }
        if ($error !== []) {
            $messages['error'] = $error;
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

}
