<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions\Projection;

/**
 * One entry of {@see \Piwigo\Admin\Extensions\ExtensionScanner::scanPlugins()}'s
 * own fixed per-plugin `plugin.json` scan result.
 */
final readonly class PluginScanRow
{
    public function __construct(
        public string $name,
        public string $version,
        public string $uri,
        public string $description,
        public string $author,
        public bool $hasSettings,
        public ?string $authorUri = null,
        public ?string $extension = null,
    ) {}
}
