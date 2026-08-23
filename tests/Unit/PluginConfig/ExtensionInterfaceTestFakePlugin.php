<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\PluginConfig;

use Piwigo\PluginConfig\ExtensionContext;
use Piwigo\PluginConfig\ExtensionInterface;

/**
 * Deliberately has NO constructor at all -- proves the interface's own
 * documented contract: PluginRegistry::bootInstance() does a bare
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

    #[\Override]
    public function boot(ExtensionContext $context): void {}

    #[\Override]
    public function install(): void
    {
        $this->installed = true;
    }

    #[\Override]
    public function activate(): void
    {
        $this->activated = true;
    }

    #[\Override]
    public function deactivate(): void
    {
        $this->deactivated = true;
    }

    #[\Override]
    public function uninstall(): void
    {
        $this->uninstalled = true;
    }

    #[\Override]
    public function update(string $oldVersion, string $newVersion): void
    {
        $this->updatedFromTo = [$oldVersion, $newVersion];
    }

    #[\Override]
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
