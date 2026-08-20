<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
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
final readonly class UpdatesPwgView implements View, HasPageAssets
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

    /**
     * `updates_pwg.latte`'s own unconditional `{do combineScript(...)}`/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B). Its own
     * `{do exposeString('Are you sure?')}` is dropped outright, not
     * migrated -- one of the 3 theme-base confirm-dialog strings
     * `ThemeBaseAssets` already registers unconditionally for every
     * page.
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('updates_pwg', 'themes/admin/default/js/updates_pwg.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
            AssetContribution::css('themes/admin/default/css/pages/updates_pwg.css', id: 'updates_pwg'),
        ];
    }
}
