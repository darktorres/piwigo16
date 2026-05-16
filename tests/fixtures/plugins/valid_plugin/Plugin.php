<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\Plugins\ValidPlugin;

use Piwigo\Plugin\PluginInterface;
use Psr\Container\ContainerInterface;

final class Plugin implements PluginInterface
{
    public static int $installCount = 0;
    public static int $activateCount = 0;
    public static int $deactivateCount = 0;
    public static int $uninstallCount = 0;
    public static ?string $lastUpdateOldVersion = null;
    public static ?string $lastUpdateNewVersion = null;

    #[\Override]
    public function getId(): string
    {
        return 'valid_plugin';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '1.0.0';
    }

    #[\Override]
    public function getName(): string
    {
        return 'Valid Plugin';
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
        self::$activateCount++;
    }

    #[\Override]
    public function deactivate(): void
    {
        self::$deactivateCount++;
    }

    #[\Override]
    public function uninstall(): void
    {
        self::$uninstallCount++;
    }

    #[\Override]
    public function update(string $oldVersion, string $newVersion): void
    {
        self::$lastUpdateOldVersion = $oldVersion;
        self::$lastUpdateNewVersion = $newVersion;
    }

    #[\Override]
    public function subscribedEvents(): array
    {
        return [];
    }
}
