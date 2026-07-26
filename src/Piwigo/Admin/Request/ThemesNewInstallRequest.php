<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET` shape for ThemesNewPageRenderer::render() (the "new"
 * tab of page slug "themes") -- P27/SEC-40 Request DTO. `installStatus`
 * distinguishes "absent" (`null`, skip the whole result block) from
 * "present but not a string" (coerced to `''`, same as the original's own
 * `default:` branch coercion) -- collapsing both to `null` would skip the
 * switch's `default:` error-reporting branch the original always reaches
 * once the key is set, regardless of its value's type.
 */
final readonly class ThemesNewInstallRequest
{
    private function __construct(
        public ?string $revision,
        public ?string $extension,
        public ?string $installStatus,
        public ?string $installedThemeId,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $revision = $source['revision'] ?? null;
        $extension = $source['extension'] ?? null;
        $installstatus = $source['installstatus'] ?? null;
        $theme_id = $source['theme_id'] ?? null;

        return new self(
            is_string($revision) ? $revision : null,
            is_string($extension) ? $extension : null,
            isset($source['installstatus']) ? (is_string($installstatus) ? $installstatus : '') : null,
            is_string($theme_id) ? $theme_id : null,
        );
    }
}
