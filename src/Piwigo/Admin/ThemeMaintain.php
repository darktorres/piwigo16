<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Used to declare maintenance methods of a theme.
 */
class ThemeMaintain
{
    /**
     * @param string $theme_id
     */
    public function __construct(protected $theme_id)
    {
    }

    /**
     * @param string $theme_version
     * @param array &$errors - used to return error messages
     */
    /** @param array<mixed> $errors */
    public function activate(string $theme_version, array &$errors = []): mixed
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
