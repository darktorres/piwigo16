<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `album_notification.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\AlbumNotificationPageRenderer::render()}. No
 * `$categoriesNav` field -- the template's own body never references
 * it. Every remaining optional field stays optional -- the template's
 * own body reads all of them through `isset()` guards, never a bare
 * truthy check, so an always-present `null` behaves identically to the
 * original conditionally-omitted key. `$saveSuccess` is also mutually
 * exclusive across its 2 original call sites (notify by users vs. by
 * group).
 */
#[Template('album_notification.latte')]
final readonly class AlbumNotificationView implements View
{
    /**
     * @param array<int, string>|null $groupMailOptions
     * @param array<int, string>|null $userOptions
     */
    public function __construct(
        public ?string $saveSuccess,
        public string $fAction,
        public string $csrfToken,
        public ?string $authKeyDuration,
        public ?bool $noGroupInGallery,
        public ?string $permissionUrl,
        public ?array $groupMailOptions,
        public ?array $userOptions,
    ) {}
}
