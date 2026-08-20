<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Admin\Extensions\Projection\LanguageScanRow;
use Piwigo\Admin\Extensions\Projection\PluginScanRow;
use Piwigo\Admin\Extensions\Projection\ThemeScanRow;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\UpdatesPwgPageRenderer::render()}. Every optional
 * field here is genuinely optional -- the original code only ever
 * assigns each of these template keys under its own runtime condition,
 * omitted here (not present as a null value) to match that exact
 * original behavior.
 */
final readonly class UpdatesPwgPageContext implements TemplatePageContext
{
    /**
     * @param array<string, list<PluginScanRow|ThemeScanRow|LanguageScanRow>>|null $missing
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
        public string $pwgToken,
        public ?string $minorVersion,
        public ?string $minorReleaseUrl,
        public ?string $majorVersion,
        public ?string $majorReleaseUrl,
        public ?string $majorDockerReleaseUrl,
        public ?string $majorVersionPwg,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'STEP' => $this->step,
            'PIWIGO_CURRENT_VERSION' => $this->piwigoCurrentVersion,
            'UPGRADE_TO' => $this->upgradeTo,
            'CSRF_TOKEN' => $this->pwgToken,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];

        if ($this->containerVersion !== null) {
            $result['CONTAINER_VERSION'] = $this->containerVersion;
        }

        if ($this->dockerUpdateGuideUrl !== null) {
            $result['DOCKER_UPDATE_GUIDE_URL'] = $this->dockerUpdateGuideUrl;
        }

        if ($this->checkVersion !== null) {
            $result['CHECK_VERSION'] = $this->checkVersion;
        }

        if ($this->devVersion !== null) {
            $result['DEV_VERSION'] = $this->devVersion;
        }

        if ($this->missing !== null) {
            // updates_pwg.latte only ever reads ['uri']/['name'] off each
            // row (the "may not be compatible" plugin/theme link lists) --
            // the template boundary, converted here rather than carrying
            // the real PluginScanRow|ThemeScanRow|LanguageScanRow objects
            // any further.
            $result['missing'] = array_map(
                static fn (array $rows): array => array_map(
                    static fn (PluginScanRow|ThemeScanRow|LanguageScanRow $row): array => [
                        'uri' => $row->uri,
                        'name' => $row->name,
                    ],
                    $rows
                ),
                $this->missing
            );
        }

        if ($this->minorReleasePhpRequired !== null) {
            $result['MINOR_RELEASE_PHP_REQUIRED'] = $this->minorReleasePhpRequired;
        }

        if ($this->majorReleasePhpRequired !== null) {
            $result['MAJOR_RELEASE_PHP_REQUIRED'] = $this->majorReleasePhpRequired;
        }

        if ($this->minorVersion !== null) {
            $result['MINOR_VERSION'] = $this->minorVersion;
        }

        if ($this->minorReleaseUrl !== null) {
            $result['MINOR_RELEASE_URL'] = $this->minorReleaseUrl;
        }

        if ($this->majorVersion !== null) {
            $result['MAJOR_VERSION'] = $this->majorVersion;
        }

        if ($this->majorReleaseUrl !== null) {
            $result['MAJOR_RELEASE_URL'] = $this->majorReleaseUrl;
        }

        if ($this->majorDockerReleaseUrl !== null) {
            $result['MAJOR_DOCKER_RELEASE_URL'] = $this->majorDockerReleaseUrl;
        }

        if ($this->majorVersionPwg !== null) {
            $result['MAJOR_VERSION_PWG'] = $this->majorVersionPwg;
        }

        return $result;
    }
}
