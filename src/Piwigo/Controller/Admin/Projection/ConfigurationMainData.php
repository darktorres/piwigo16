<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The `'main'` tab's own display data, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * `'main'` case. Every field here is a fixed, statically-known key (11 of
 * them come from `checkboxValue()`'s own literal `match` arms before this
 * conversion, confirmed as real bool `CurrentConfig` properties, not a
 * genuinely dynamic bag) -- `configuration_main.latte` still reads them
 * via `$main['key']` (through {@see ConfigurationMainView}'s own
 * array-typed `$main`), so `toArray()` reproduces that exact shape.
 * `$emailAdminOnNewUserFilterGroup` stays `int|string` -- a matched
 * group id digit-string, or the `-1` sentinel.
 */
final readonly class ConfigurationMainData
{
    /**
     * @param array<string, string> $weekStartsOnOptions
     * @param array<string, string> $mailThemeOptions
     * @param list<string> $orderBy
     * @param array<string, string> $orderByOptions
     */
    public function __construct(
        public string $confGalleryTitle,
        public string $confPageBanner,
        public array $weekStartsOnOptions,
        public string $weekStartsOnOptionsSelected,
        public string $mailTheme,
        public array $mailThemeOptions,
        public array $orderBy,
        public array $orderByOptions,
        public bool $emailAdminOnNewUser,
        public string $emailAdminOnNewUserFilter,
        public int|string $emailAdminOnNewUserFilterGroup,
        public bool $allowUserRegistration,
        public bool $obligatoryUserMailAddress,
        public bool $rate,
        public bool $rateAnonymous,
        public bool $allowUserCustomization,
        public bool $log,
        public bool $historyAdmin,
        public bool $historyGuest,
        public bool $showMobileAppBannerInGallery,
        public bool $showMobileAppBannerInAdmin,
        public bool $uploadDetectDuplicate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'CONF_GALLERY_TITLE' => $this->confGalleryTitle,
            'CONF_PAGE_BANNER' => $this->confPageBanner,
            'week_starts_on_options' => $this->weekStartsOnOptions,
            'week_starts_on_options_selected' => $this->weekStartsOnOptionsSelected,
            'mail_theme' => $this->mailTheme,
            'mail_theme_options' => $this->mailThemeOptions,
            'order_by' => $this->orderBy,
            'order_by_options' => $this->orderByOptions,
            'email_admin_on_new_user' => $this->emailAdminOnNewUser,
            'email_admin_on_new_user_filter' => $this->emailAdminOnNewUserFilter,
            'email_admin_on_new_user_filter_group' => $this->emailAdminOnNewUserFilterGroup,
            'allow_user_registration' => $this->allowUserRegistration,
            'obligatory_user_mail_address' => $this->obligatoryUserMailAddress,
            'rate' => $this->rate,
            'rate_anonymous' => $this->rateAnonymous,
            'allow_user_customization' => $this->allowUserCustomization,
            'log' => $this->log,
            'history_admin' => $this->historyAdmin,
            'history_guest' => $this->historyGuest,
            'show_mobile_app_banner_in_gallery' => $this->showMobileAppBannerInGallery,
            'show_mobile_app_banner_in_admin' => $this->showMobileAppBannerInAdmin,
            'upload_detect_duplicate' => $this->uploadDetectDuplicate,
        ];
    }
}
