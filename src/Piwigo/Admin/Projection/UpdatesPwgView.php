<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `updates_pwg.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\UpdatesPwgPageRenderer::render()}. Every remaining
 * optional field stays optional -- the template's own body reads all
 * of them through `isset()`/`empty()` guards, never a bare truthy
 * check, so an always-present `null` behaves identically to the
 * original conditionally-omitted key. No `$majorVersionPwg` field --
 * the template's own body never references it (confirmed against
 * `updates_pwg.js` too).
 */
#[Template('updates_pwg.latte')]
final readonly class UpdatesPwgView implements View
{
    /**
     * @param array<string, list<array<string, mixed>>>|null $missing
     */
    public function __construct(
        public ?string $containerVersion,
        public ?string $dockerUpdateGuideUrl,
        public ?bool $checkVersion,
        public ?bool $devVersion,
        public ?array $missing,
        public ?string $minorReleasePhpRequired,
        public ?string $majorReleasePhpRequired,
        public int|string $step,
        public string $piwigoCurrentVersion,
        public string $upgradeTo,
        public string $csrfToken,
        public ?string $minorVersion,
        public ?string $minorReleaseUrl,
        public ?string $majorVersion,
        public ?string $majorReleaseUrl,
        public ?string $majorDockerReleaseUrl,
    ) {}
}
