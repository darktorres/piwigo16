<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['section']` for PluginSubController::handle() (page
 * slug "plugin") -- extracts just the plugin id. `Admin\AdminShell`'s own
 * `?page=plugin-<id>` alias always rewrites this to `<id>/admin.php`;
 * `PluginSubController` dispatches through `PluginConfig\
 * SettingsPageInterface`, and no file path is ever constructed from this
 * value, so the id is
 * always the leading segment before the first `/` -- everything after it
 * is ignored, not validated.
 */
final readonly class PluginSectionRequest
{
    private function __construct(
        public string $pluginId,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArray($_GET, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source, InputValidator $inputValidator): self
    {
        $section_param = $source['section'] ?? '';
        $section_str = is_string($section_param) ? $section_param : '';
        $plugin_id = explode('/', $section_str, 2)[0];

        // matching PluginLoader's own plugin_id charset assumption.
        $inputValidator->validate('plugin_id', [
            'plugin_id' => $plugin_id,
        ], false, '/^[\w-]+$/', true);

        return new self($plugin_id);
    }
}
