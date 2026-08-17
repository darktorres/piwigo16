<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

/**
 * `PATCH /api/v1/session` body DTO -- mirrors `Ws\Users\SetMyInfoParams`'s
 * own optional fields (a logged-in user updating their own account).
 */
final readonly class MyInfoUpdateInput
{
    public function __construct(
        public ?string $email,
        public ?int $nbImagePage,
        public ?string $theme,
        public ?string $language,
        public ?int $recentPeriod,
        public ?bool $expand,
        public ?bool $showNbComments,
        public ?bool $showNbHits,
        public ?string $password,
        public ?string $newPassword,
        public ?string $confNewPassword,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $email = $raw['email'] ?? null;
        $nbImagePage = $raw['nbImagePage'] ?? null;
        $theme = $raw['theme'] ?? null;
        $language = $raw['language'] ?? null;
        $recentPeriod = $raw['recentPeriod'] ?? null;
        $expand = $raw['expand'] ?? null;
        $showNbComments = $raw['showNbComments'] ?? null;
        $showNbHits = $raw['showNbHits'] ?? null;
        $password = $raw['password'] ?? null;
        $newPassword = $raw['newPassword'] ?? null;
        $confNewPassword = $raw['confNewPassword'] ?? null;

        return new self(
            email: is_string($email) ? $email : null,
            nbImagePage: is_int($nbImagePage) ? $nbImagePage : null,
            theme: is_string($theme) ? $theme : null,
            language: is_string($language) ? $language : null,
            recentPeriod: is_int($recentPeriod) ? $recentPeriod : null,
            expand: is_bool($expand) ? $expand : null,
            showNbComments: is_bool($showNbComments) ? $showNbComments : null,
            showNbHits: is_bool($showNbHits) ? $showNbHits : null,
            password: is_string($password) ? $password : null,
            newPassword: is_string($newPassword) ? $newPassword : null,
            confNewPassword: is_string($confNewPassword) ? $confNewPassword : null,
        );
    }
}
