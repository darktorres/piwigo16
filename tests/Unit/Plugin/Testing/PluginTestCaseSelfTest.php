<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugin\Testing;

use Piwigo\Event\Ws\WsInvokeAllowed;
use Piwigo\Plugin\PluginInterface;
use Piwigo\Plugin\Testing\PluginTestCase;
use Piwigo\Ws\PwgError;
use Psr\Container\ContainerInterface;

/**
 * Self-tests for PluginTestCase. Demonstrates the full plugin-author
 * workflow (bind services → boot plugin → dispatch event → assert
 * mutation) so that the file doubles as documentation.
 */
final class PluginTestCaseSelfTest extends PluginTestCase
{
    public function testBootPluginRegistersSubscribedEventHandlers(): void
    {
        $plugin = new class () implements PluginInterface {
            public bool $booted = false;

            public ?ContainerInterface $bootContainer = null;

            #[\Override]
            public function getId(): string
            {
                return 'sample';
            }

            #[\Override]
            public function getVersion(): string
            {
                return '1.0.0';
            }

            #[\Override]
            public function getName(): string
            {
                return 'Sample';
            }

            #[\Override]
            public function boot(ContainerInterface $container): void
            {
                $this->booted = true;
                $this->bootContainer = $container;
            }

            public function onWsInvoke(WsInvokeAllowed $event): void
            {
                $event->value = new PwgError(403, 'blocked-by-plugin');
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
                return [WsInvokeAllowed::class => 'onWsInvoke'];
            }
        };

        $this->bootPlugin($plugin);

        self::assertTrue($plugin->booted);
        self::assertSame($this->container, $plugin->bootContainer);

        $event = new WsInvokeAllowed(true, 'pwg.images.add', []);
        $this->dispatch($event);

        self::assertInstanceOf(PwgError::class, $event->value);
        self::assertSame('blocked-by-plugin', $event->value->message());
    }

    public function testBindServiceMakesValuesVisibleViaContainer(): void
    {
        $stub = new \stdClass();
        $this->bindService('my.stub', $stub);

        self::assertSame($stub, $this->container->get('my.stub'));
    }
}
