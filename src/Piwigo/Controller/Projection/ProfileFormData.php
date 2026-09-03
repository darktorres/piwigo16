<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\ThemeId;

/**
 * The data {@see \Piwigo\Controller\ProfileFormHandler::loadIntoTemplate()}
 * computes, returned rather than assigned onto a shared template bag.
 * `ProfileController` and `Controller\Admin\ConfigurationSubController`'s
 * "default" tab are its two real callers, and each renders a different
 * template from these same fields (the front-end profile page's own
 * `ProfileFormView`/`ProfileView` pair, versus the admin Guest-settings
 * tab's own inline `GUEST_`-prefixed form) -- so neither caller's own
 * field-naming convention belongs on this shared class.
 *
 * `$apiEmailInfos` is Html, not string (P59): `loadIntoTemplate()`
 * builds it from a `Lang::t()` translation plus, at most, the current
 * user's own already-validated `Email::$value` (`filter_var(...,
 * FILTER_VALIDATE_EMAIL)` structurally excludes HTML-breaking
 * characters) -- never attacker- or admin-supplied free text. The
 * admin caller above never reads this field.
 */
final readonly class ProfileFormData
{
    /**
     * @param array<string, string> $radioOptions
     * @param array<int|string, string> $templateOptions
     * @param array<int|string, string> $languageOptions
     * @param array<int|string, string> $apiExpiration
     */
    public function __construct(
        public string $username,
        public ?Email $email,
        public bool $allowUserCustomization,
        public bool $activateComments,
        public int $nbImagePage,
        public int $recentPeriod,
        public string $expand,
        public string $nbComments,
        public string $nbHits,
        public string $redirect,
        public string $fAction,
        public array $radioOptions,
        public ThemeId $templateSelection,
        public array $templateOptions,
        public ?string $languageSelection,
        public array $languageOptions,
        public bool $specialUser,
        public string $apiCurrentDate,
        public array $apiExpiration,
        public int|string|null $apiSelectedExpiration,
        public bool $apiCanManage,
        public Html $apiEmailInfos,
        public string $pwgToken,
    ) {}
}
