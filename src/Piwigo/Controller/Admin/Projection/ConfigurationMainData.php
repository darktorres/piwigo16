<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The `'main'` tab's own display data, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * `'main'` case. Every field here is a fixed, statically-known key (11 of
 * them come from `checkboxValue()`'s own literal `match` arms before this
 * conversion, confirmed as real bool `CurrentConfig` properties, not a
 * genuinely dynamic bag) -- `configuration_main.latte` reads them as
 * properties directly (P58-A).
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
}
