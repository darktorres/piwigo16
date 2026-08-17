<?php

declare(strict_types=1);

use Piwigo\PluginConfig\ExtensionInterface;
use Piwigo\Tests\Unit\PluginConfig\ExtensionInterfaceTestFakeEvent;
use Piwigo\Tests\Unit\PluginConfig\ExtensionInterfaceTestFakePlugin;

/**
 * PluginRegistry reads subscribedEvents() off a manifest-resolved
 * `ExtensionInterface $plugin` variable, never the concrete class name --
 * this is the real call shape to exercise.
 *
 * @return array<class-string, Closure|list<Closure>>
 */
function subscribedEventsViaInterface(ExtensionInterface $plugin): array
{
    return $plugin->subscribedEvents();
}

test('subscribedEvents works correctly on a bare new $class() with no injected state', function (): void {
    $plugin = new ExtensionInterfaceTestFakePlugin();

    $events = subscribedEventsViaInterface($plugin);

    expect($events)
        ->toHaveKey(ExtensionInterfaceTestFakeEvent::class);

    $handler = $events[ExtensionInterfaceTestFakeEvent::class];
    expect($handler)
        ->toBeInstanceOf(Closure::class);

    // subscribedEvents()'s declared return type (Closure|list<Closure>)
    // keeps $handler's static type too wide to invoke directly -- the
    // instanceof check above already proved it's a bare Closure here, so
    // this narrows for real rather than re-declaring the same fact via a
    // no-op assert()/@var override.
    if (! $handler instanceof Closure) {
        throw new LogicException('handler must be a bare Closure in this fixture');
    }

    $event = new ExtensionInterfaceTestFakeEvent();
    $handler($event);

    expect($event->touched)
        ->toBeTrue();
});

/**
 * Calls every ExtensionInterface lifecycle hook through the interface
 * type itself, not the concrete fake -- PluginRegistry will call
 * these against a manifest-resolved `ExtensionInterface $plugin`
 * variable, never a concrete class name, so this is the real call shape
 * to exercise.
 */
function callLifecycleHooksViaInterface(ExtensionInterface $plugin): void
{
    $plugin->install();
    $plugin->activate();
    $plugin->deactivate();
    $plugin->uninstall();
    $plugin->update('1.0.0', '2.0.0');
}

test('install/activate/deactivate/uninstall/update lifecycle hooks are independently callable', function (): void {
    $plugin = new ExtensionInterfaceTestFakePlugin();

    callLifecycleHooksViaInterface($plugin);

    expect($plugin->installed)
        ->toBeTrue()
        ->and($plugin->activated)
        ->toBeTrue()
        ->and($plugin->deactivated)
        ->toBeTrue()
        ->and($plugin->uninstalled)
        ->toBeTrue()
        ->and($plugin->updatedFromTo)
        ->toBe(['1.0.0', '2.0.0']);
});
