<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\PluginConfig;

use Piwigo\PluginConfig\ExtensionContext;
use Piwigo\PluginConfig\ExtensionInterface;

/**
 * Deliberately has NO constructor at all -- proves the interface's own
 * documented contract: PluginRegistry::bootInstance() (P27.3) does a bare
 * `new $class()`, so subscribedEvents() must work correctly with zero
 * injected state, unlike Listener\*'s own Core\SubscriberInterface
 * implementors (which ARE constructed through normal container/DI
 * resolution).
 */
final class ExtensionInterfaceTestFakePlugin implements ExtensionInterface
{
    public bool $installed = false;

    public bool $activated = false;

    public bool $deactivated = false;

    public bool $uninstalled = false;

    /**
     * @var array{0: string, 1: string}|null
     */
    public ?array $updatedFromTo = null;

    public function boot(ExtensionContext $context): void {}

    public function install(): void
    {
        $this->installed = true;
    }

    public function activate(): void
    {
        $this->activated = true;
    }

    public function deactivate(): void
    {
        $this->deactivated = true;
    }

    public function uninstall(): void
    {
        $this->uninstalled = true;
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        $this->updatedFromTo = [$oldVersion, $newVersion];
    }

    public function subscribedEvents(): array
    {
        return [
            ExtensionInterfaceTestFakeEvent::class => $this->onFakeEvent(...),
        ];
    }

    public function onFakeEvent(ExtensionInterfaceTestFakeEvent $event): ExtensionInterfaceTestFakeEvent
    {
        $event->touched = true;

        return $event;
    }
}
