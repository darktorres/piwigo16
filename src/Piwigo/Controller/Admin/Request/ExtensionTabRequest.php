<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['tab']` shared by `LanguagesSubController`/
 * `PluginsSubController`/`ThemesSubController` (page slugs "languages"/
 * "plugins"/"themes") -- P27/SEC-40 Request DTO. The 3 controllers had the
 * exact same inline read/validate/default logic duplicated verbatim,
 * differing only in the tab-name allowlist pattern (each still validates
 * against its own pattern, passed in by the caller) -- same rationale as
 * MaintenanceActionRequest being shared by its own 2 duplicate sites.
 */
final readonly class ExtensionTabRequest
{
    private function __construct(
        public string $tab,
    ) {}

    public static function fromGlobals(string $pattern): self
    {
        return self::fromArray($_GET, $pattern);
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromArray(array $source, string $pattern): self
    {
        if (isset($source['tab'])) {
            new InputValidator()->validate('tab', $source, false, $pattern);
            $tab_raw = $source['tab'];
            $tab = is_string($tab_raw) ? $tab_raw : 'installed';
        } else {
            $tab = 'installed';
        }

        return new self($tab);
    }
}
