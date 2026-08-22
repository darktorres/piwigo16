<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * {@see \Piwigo\Admin\PluginsInstalledPageRenderer::render()}'s own
 * per-plugin row for `plugins_installed.latte`. A fixed, always-9-key
 * shape (confirmed via direct read: `AUTHOR_URL` is always a key -- its
 * value is nullable, matching {@see \Piwigo\Admin\Extensions\Projection\
 * PluginScanRow::$authorUri} -- not a conditionally-present key).
 */
final readonly class PluginListRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $visitUrl,
        public string $version,
        public string $desc,
        public string $author,
        public ?string $authorUrl,
        public string $settingsUrl,
        public string $state,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ID' => $this->id,
            'NAME' => $this->name,
            'VISIT_URL' => $this->visitUrl,
            'VERSION' => $this->version,
            'DESC' => $this->desc,
            'AUTHOR' => $this->author,
            'AUTHOR_URL' => $this->authorUrl,
            'SETTINGS_URL' => $this->settingsUrl,
            'STATE' => $this->state,
        ];
    }
}
