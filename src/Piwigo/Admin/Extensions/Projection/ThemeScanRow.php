<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions\Projection;

/**
 * One entry of {@see \Piwigo\Admin\Extensions\ExtensionScanner::scanThemes()}'s
 * own fixed per-theme `theme.json` scan result. `activable` is never set
 * by the scanner itself (no `ThemeManifest` equivalent) -- stays `null`
 * for callers that already read it defensively, matching the array
 * shape's own "never set, not just absent-with-a-key" contract.
 */
final readonly class ThemeScanRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $uri,
        public string $description,
        public string $author,
        public bool $mobile,
        public string $screenshot,
        public ?string $authorUri = null,
        public ?string $extension = null,
        public ?string $parent = null,
        public ?bool $activable = null,
        public ?bool $useStandardPages = null,
        public ?string $adminUri = null,
    ) {}
}
