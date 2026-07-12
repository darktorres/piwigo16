<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * Aggregate counts only -- no plugin/theme/language identifiers, which
 * could otherwise fingerprint an install's exact configuration.
 */
final readonly class ExtensionStats
{
    public function __construct(
        public int $pluginCount,
        public int $themeCount,
        public int $languageCount,
    ) {}

    /**
     * @return array{plugin_count: int, theme_count: int, language_count: int}
     */
    public function toArray(): array
    {
        return [
            'plugin_count' => $this->pluginCount,
            'theme_count' => $this->themeCount,
            'language_count' => $this->languageCount,
        ];
    }
}
