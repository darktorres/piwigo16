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

        // High priority so this listener fires before the core
        // TryLogUserSubscriber — that subscriber pulls in AuthService and
        // Connection (DB), which aren't available in this unit test. We only
        // care about the bridge wiring here, not the core subscriber.
        $capturedSuccess = null;
        self::dispatcher()->addListener(TryLogUser::class, static function (TryLogUser $event) use (&$capturedSuccess): void {
            $capturedSuccess = $event->success;
        }, 100);

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

    public function testTypedSubscriberMutationFlowsBackToLegacyReturn(): void
    {
        // B6e writeback: when a typed subscriber mutates the event's single
        // mutable property, the bridge reads that value via reflection and
        // returns it as the legacy dispatch's $data. Without this, plugins
        // still calling the legacy API see input unchanged even when typed
        // subscribers ran. render_tag_url's RenderTagUrlSubscriber applies
        // StringUtil::str2url and is wired without DB deps, so it exercises
        // the writeback end-to-end inside the unit test.
        $result = EventDispatcher::dispatch('render_tag_url', 'Hello World!');
        self::assertSame('hello_world', $result, 'typed subscriber mutation flowed back into legacy dispatch return');
    }

    public function testReadonlyOnlyEventReturnsLegacyMutatedDataUnchanged(): void
    {
        // When every DTO field is readonly (no B6b demotion), the bridge
        // detects "no writeback property" and leaves the legacy $data alone.
        // picture_modify_before_update is a notify-shape event with one
        // readonly array field, so any plugin-legacy mutation propagates
        // through legacy listeners without being clobbered by reflection.
        $legacyListener = static function (array $data): array {
            $data['mutated'] = true;
            return $data;
        };
        EventDispatcher::addListener('picture_modify_before_update', $legacyListener);

        $result = EventDispatcher::dispatch('picture_modify_before_update', ['title' => 'cat']);

        self::assertSame(['title' => 'cat', 'mutated' => true], $result);
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
