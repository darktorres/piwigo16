<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Event\LegacyEventBridge;
use Piwigo\Event\Location\LocBeginAbout;
use Piwigo\Event\Picture\PictureModifyBeforeUpdate;
use Piwigo\Event\User\TryLogUser;
use Piwigo\Plugins\EventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

/**
 * Verifies that legacy `EventDispatcher::dispatch/notify(...)` calls
 * fire matching typed events through the PSR-14 dispatcher.
 *
 * Lives only until B17 (legacy dispatcher deletion).
 */
final class LegacyEventBridgeTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Kernel::reset();
        EventDispatcher::reset();
        Config::loadArray(['upload_dir' => './upload']);
        $GLOBALS['lang'] = [];
        $GLOBALS['user'] = ['id' => 2, 'username' => 'guest', 'email' => '', 'language' => 'en_US', 'theme' => 'elegant', 'status' => 'guest', 'enabled_high' => false];
        Kernel::boot();
    }

    #[\Override]
    protected function tearDown(): void
    {
        EventDispatcher::reset();
        Kernel::reset();
        unset($GLOBALS['lang'], $GLOBALS['user']);
    }

    public function testLegacyDispatchFiresMatchingTypedEvent(): void
    {
        $captured = [];
        self::dispatcher()->addListener(PictureModifyBeforeUpdate::class, static function (PictureModifyBeforeUpdate $event) use (&$captured): void {
            $captured[] = $event->data;
        });

        $result = EventDispatcher::dispatch('picture_modify_before_update', ['title' => 'cat']);

        self::assertSame(['title' => 'cat'], $result, 'legacy dispatch still returns its input data');
        self::assertCount(1, $captured, 'typed listener fired exactly once');
        self::assertSame(['title' => 'cat'], $captured[0]);
    }

    public function testLegacyNotifyFiresMatchingTypedEvent(): void
    {
        $count = 0;
        self::dispatcher()->addListener(LocBeginAbout::class, static function () use (&$count): void {
            ++$count;
        });

        EventDispatcher::notify('loc_begin_about');

        self::assertSame(1, $count, 'typed listener fired exactly once for legacy notify');
    }

    public function testLegacyDispatchUsesFinalDataAfterLegacyListenersMutate(): void
    {
        // Register a legacy listener that flips the bool first arg, then
        // verify the typed event sees the mutated value. Uses try_log_user
        // because its (bool, string, string, bool):bool closure shape is
        // already in EventDispatcher::addListener's psalm-param union.
        EventDispatcher::addListener(
            'try_log_user',
            static fn (bool $success, string $username, string $password, bool $rememberMe): bool => true,
        );

        $capturedSuccess = null;
        self::dispatcher()->addListener(TryLogUser::class, static function (TryLogUser $event) use (&$capturedSuccess): void {
            $capturedSuccess = $event->success;
        });

        $result = EventDispatcher::dispatch('try_log_user', false, 'alice', 'secret', false);

        self::assertTrue($result, 'legacy listener mutation visible in return value');
        self::assertTrue($capturedSuccess, 'typed event sees post-legacy-mutation value');
    }

    public function testUnmappedEventDoesNotThrow(): void
    {
        // No DTO scaffolded for this name — bridge must skip silently.
        $result = EventDispatcher::dispatch('not_a_real_event_name', 'value');
        self::assertSame('value', $result);
    }

    public function testLegacyBridgeMapCoversEveryDispatchedEvent(): void
    {
        $missing = LegacyEventBridge::classFor('not_a_real_event_name');
        self::assertNull($missing);

        $found = LegacyEventBridge::classFor('picture_modify_before_update');
        self::assertSame(PictureModifyBeforeUpdate::class, $found);
    }

    private static function dispatcher(): SymfonyEventDispatcher
    {
        $d = Kernel::service(EventDispatcherInterface::class);
        self::assertInstanceOf(SymfonyEventDispatcher::class, $d);
        return $d;
    }
}
