<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Used to declare maintenance methods of a theme.
 *
 * Signatures are intentionally untyped to match legacy vendor themes
 * that extend this class with pre-PHP-7 signatures (no parameter
 * types, no return types). Adding declared types here breaks LSP and
 * fatal-on-load. See PluginMaintain for the same reasoning.
 */
final class ThemeMaintain
{
    /**
     * @param string $theme_id
     */
    public function __construct(protected $theme_id)
    {
    }

    /** @param string $theme_version
     *  @param array<mixed> $errors */
    public function activate($theme_version, &$errors = []): null
    {
        return null;
    }

    public function deactivate(): void
    {
    }

    public function delete(): void
    {
    }
}
