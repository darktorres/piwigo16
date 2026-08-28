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
 * \Piwigo\Admin\UpdatesPwgPageRenderer::render()}. No
 * `$majorVersionPwg` field -- the template's own body never references
 * it (confirmed against `updates_pwg.js` too).
 *
 * Most optional fields stay optional because the template reads them
 * through `isset()` guards, so an always-present `null` behaves
 * identically to the original conditionally-omitted key. Two exceptions,
 * both read bare:
 *
 * - `$checkVersion`/`$devVersion` are `bool`, read as `{if $checkVersion}`.
 *   The renderer used to set them only inside its own `$step === 0`
 *   branch and now reads them unconditionally off `NewVersionsInfo`,
 *   which is where the correlation with `$step` went (P58-A's §11).
 * - `$majorReleaseUrl` stays `?string`, because it genuinely is null
 *   whenever there is no major release. What changed is that the step
 *   can no longer disagree with it: the renderer validates the requested
 *   step against the releases that actually exist and falls back to 0
 *   otherwise, so steps 1 and 3 imply a major release the way they always
 *   meant to. The two blocks that read it say so themselves
 *   (`$step == 1 && $majorReleaseUrl !== null`) rather than relying on a
 *   correlation established eighty lines away in the renderer.
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
        public bool $checkVersion,
        public bool $devVersion,
        public ?array $missing,
        public ?string $minorReleasePhpRequired,
        public ?string $majorReleasePhpRequired,
        public int $step,
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
            AssetContribution::script('updates_pwg', 'themes/admin/default/js/updates_pwg.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/updates_pwg.css', id: 'updates_pwg'),
        ];
    }
}
