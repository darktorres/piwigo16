<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\Themes\ChildTheme;

use Piwigo\Theme\ThemeInterface;
use Psr\Container\ContainerInterface;

final class Theme implements ThemeInterface
{
    #[\Override]
    public function getId(): string
    {
        return 'child_theme';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '1.0.0';
    }

    #[\Override]
    public function getName(): string
    {
        return 'Child Theme';
    }

    #[\Override]
    public function getParentId(): string
    {
        return 'valid_theme';
    }

    #[\Override]
    public function loadParentCss(): bool
    {
        return true;
    }

    #[\Override]
    public function getAssetDir(string $kind): string
    {
        return 'assets/' . $kind;
    }

    #[\Override]
    public function getLocalHeadTemplate(): ?string
    {
        return null;
    }

    #[\Override]
    public function boot(ContainerInterface $container): void
    {
    }

    #[\Override]
    public function install(): void
    {
    }

    #[\Override]
    public function activate(): void
    {
    }

    #[\Override]
    public function deactivate(): void
    {
    }

    #[\Override]
    public function uninstall(): void
    {
    }

    #[\Override]
    public function update(string $oldVersion, string $newVersion): void
    {
    }

    #[\Override]
    public function subscribedEvents(): array
    {
        return [];
    }
}
