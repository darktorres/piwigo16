<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\Plugins\MigrationPlugin;

use Piwigo\Plugin\PluginInterface;
use Psr\Container\ContainerInterface;

final class Plugin implements PluginInterface
{
    public static int $installCount = 0;
    public static int $uninstallCount = 0;

    #[\Override]
    public function getId(): string
    {
        return 'MigrationPlugin';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '1.0.0';
    }

    #[\Override]
    public function getName(): string
    {
        return 'Migration Plugin';
    }

    #[\Override]
    public function boot(ContainerInterface $container): void
    {
    }

    #[\Override]
    public function install(): void
    {
        self::$installCount++;
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
        self::$uninstallCount++;
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
