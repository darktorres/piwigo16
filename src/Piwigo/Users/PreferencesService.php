<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Per-user preferences: a single JSON object on user_infos.preferences,
 * mirrored into `CurrentUser::get()->preferences` for the lifetime of the
 * request. Constructor-injects UserRepository, plain constructor injection
 * (same shape as PermalinkService/GroupService).
 */
final readonly class PreferencesService
{
    public function __construct(
        private UserRepository $repo,
        private CurrentUser $currentUser,
    ) {}

    public function save(): void
    {
        $user = $this->currentUser->get();

        $this->repo->savePreferences($user->id, $user->preferences);
    }

    /**
     * @param mixed $value save() json_encode()s the whole preferences
     *   array, so this isn't limited to strings -- real callers pass bool
     *   (admin.php), int (password.php's timestamp), and array
     *   (functions_search.inc.php's filter list) too
     */
    public function updateParam(string $param, mixed $value): void
    {
        if ($value === 'true') {
            $value = true;
        } elseif ($value === 'false') {
            $value = false;
        }

        $preferences = $this->currentUser->get()
            ->preferences;
        $preferences[$param] = $value;
        $this->currentUser->set($this->currentUser->get()->withPreferences($preferences));

        $this->save();
    }

    /**
     * @param string|array<int|string, string> $params
     */
    public function deleteParam(string|array $params): void
    {
        $paramList = is_array($params) ? $params : [$params];
        if ($paramList === []) {
            return;
        }

        $preferences = $this->currentUser->get()
            ->preferences;
        foreach ($paramList as $param) {
            unset($preferences[$param]);
        }
        $this->currentUser->set($this->currentUser->get()->withPreferences($preferences));

        $this->save();
    }

    /**
     * Stays for the one genuinely dynamic real caller
     * (`Admin\AdminShell`'s `'show_whats_new_' . $majorVersion`, a
     * runtime-built key) -- every other real call site has its own
     * named, correctly-narrowed accessor below instead of re-checking
     * `is_string()`/`is_array()`/casting to bool on this method's own
     * `mixed` return.
     */
    public function getParam(string $param, mixed $default = null): mixed
    {
        return $this->currentUser->get()
            ->preferences[$param] ?? $default;
    }

    /**
     * `admin_theme` -- 4 real callers, all falling back to
     * `CurrentConfig::adminTheme()` when unset or not a string.
     */
    public function getAdminThemePref(): ?string
    {
        $value = $this->currentUser->get()
            ->preferences['admin_theme'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `user-manager-view` -- `Admin\UserListPageRenderer`'s "line" vs
     * other layout choice.
     */
    public function getUserManagerView(): ?string
    {
        $value = $this->currentUser->get()
            ->preferences['user-manager-view'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `user-manager-pagination` -- `Admin\UserListPageRenderer`'s own real
     * caller applies 2 different fallbacks (5 for the "line" view, 10
     * otherwise) depending on `getUserManagerView()`, so this stays
     * nullable rather than baking in either one.
     */
    public function getUserManagerPagination(): ?int
    {
        $value = $this->currentUser->get()
            ->preferences['user-manager-pagination'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * `plugin-manager-view` -- `Admin\PluginsInstalledPageRenderer`'s
     * "classic" vs other layout choice.
     */
    public function getPluginManagerView(): ?string
    {
        $value = $this->currentUser->get()
            ->preferences['plugin-manager-view'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `promote-mobile-apps` -- null only when the key is genuinely absent
     * (matching the removed `getParam()` call's own `?? true` semantics);
     * a present-but-falsy stored value (e.g. `false`/`0`) still surfaces
     * as `false`, not silently replaced by the caller's default.
     */
    public function getPromoteMobileApps(): ?bool
    {
        $preferences = $this->currentUser->get()
            ->preferences;

        return array_key_exists('promote-mobile-apps', $preferences) ? (bool) $preferences['promote-mobile-apps'] : null;
    }

    /**
     * `show_newsletter_subscription` -- same "null only when genuinely
     * absent" contract as {@see getPromoteMobileApps()}.
     */
    public function getShowNewsletterSubscription(): ?bool
    {
        $preferences = $this->currentUser->get()
            ->preferences;

        return array_key_exists('show_newsletter_subscription', $preferences) ? (bool) $preferences['show_newsletter_subscription'] : null;
    }

    /**
     * `gallery_search_filters` -- `Controller\SearchController`'s saved
     * filter-field selection.
     *
     * @return array<array-key, mixed>|null
     */
    public function getGallerySearchFilters(): ?array
    {
        $value = $this->currentUser->get()
            ->preferences['gallery_search_filters'] ?? null;

        return is_array($value) ? $value : null;
    }
}
