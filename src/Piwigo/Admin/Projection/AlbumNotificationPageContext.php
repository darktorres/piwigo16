<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\AlbumNotificationPageRenderer::render()}.
 * `$saveSuccess`, `$authKeyDuration`, `$noGroupInGallery`,
 * `$permissionUrl`, `$groupMailOptions`, and `$userOptions` are
 * genuinely optional -- the original code only ever assigns those
 * template keys under their own runtime condition, omitted here (not
 * present as a null value) to match that exact original behavior.
 * `$saveSuccess` is also mutually exclusive across its 2 original call
 * sites (notify by users vs. by group).
 */
final readonly class AlbumNotificationPageContext implements TemplatePageContext
{
    /**
     * @param array<int, string>|null $groupMailOptions
     * @param array<int, string>|null $userOptions
     */
    public function __construct(
        public ?string $saveSuccess,
        public string $categoriesNav,
        public string $fAction,
        public string $pwgToken,
        public ?string $authKeyDuration,
        public ?bool $noGroupInGallery,
        public ?string $permissionUrl,
        public ?array $groupMailOptions,
        public ?array $userOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'CATEGORIES_NAV' => $this->categoriesNav,
            'F_ACTION' => $this->fAction,
            'PWG_TOKEN' => $this->pwgToken,
        ];

        if ($this->saveSuccess !== null) {
            $result['save_success'] = $this->saveSuccess;
        }

        if ($this->authKeyDuration !== null) {
            $result['auth_key_duration'] = $this->authKeyDuration;
        }

        if ($this->noGroupInGallery !== null) {
            $result['no_group_in_gallery'] = $this->noGroupInGallery;
        }

        if ($this->permissionUrl !== null) {
            $result['permission_url'] = $this->permissionUrl;
        }

        if ($this->groupMailOptions !== null) {
            $result['group_mail_options'] = $this->groupMailOptions;
        }

        if ($this->userOptions !== null) {
            $result['user_options'] = $this->userOptions;
        }

        return $result;
    }
}
