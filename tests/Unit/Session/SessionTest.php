<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Image\DerivativeSize;
use Piwigo\Session\FlashBag;
use Piwigo\Session\Session;

final class SessionTest extends TestCase
{
    public function testEmptySuperglobalProducesEmptySession(): void
    {
        $s = Session::fromSuperglobal([]);
        self::assertNull($s->userId);
        self::assertNull($s->connectedWith);
        self::assertFalse($s->showMetadata);
        self::assertFalse($s->filterEnabled);
        self::assertSame([], $s->filterCategories);
        self::assertFalse($s->flash->hasAny());
    }

    public function testHydrateCanonicalKeys(): void
    {
        $raw = [
            'pwg_uid'              => 7,
            'connected_with'       => 'pwg_ui',
            'pwg_index_deriv'      => 'medium',
            'pwg_picture_deriv'    => 'large',
            'pwg_mobile_theme'     => 'elegant',
            'pwg_show_metadata'    => true,
            'pwg_filter_enabled'   => true,
            'pwg_referer_image_id' => '42',
            'pwg_filter_categories' => [1, '2', 3, 'bad'],
            'page_infos'           => ['hello', 'world'],
            'page_errors'          => ['oops'],
            'dismissed_upgrade_version' => '17.0.0',
        ];

        $s = Session::fromSuperglobal($raw);
        self::assertEquals(UserId::from(7), $s->userId);
        self::assertSame('pwg_ui', $s->connectedWith);
        self::assertSame(DerivativeSize::Medium, $s->indexDeriv);
        self::assertSame(DerivativeSize::Large, $s->pictureDeriv);
        self::assertEquals(ThemeId::from('elegant'), $s->mobileTheme);
        self::assertTrue($s->showMetadata);
        self::assertTrue($s->filterEnabled);
        self::assertEquals(ImageId::from(42), $s->refererImageId);
        // intList drops the non-numeric 'bad'.
        self::assertSame([1, 2, 3], $s->filterCategories);
        self::assertSame(['hello', 'world'], $s->flash->peek('info'));
        self::assertSame(['oops'], $s->flash->peek('error'));
        self::assertSame('17.0.0', $s->dismissedUpgradeVersion);
    }

    public function testMalformedValuesNarrowToDefaults(): void
    {
        $raw = [
            'pwg_uid'             => 'not-a-uid',
            'pwg_index_deriv'     => 'not-a-size',
            'pwg_mobile_theme'    => 'bad theme id with space',
            'pwg_referer_image_id' => -5,
            'pwg_filter_categories' => 'not-an-array',
        ];

        $s = Session::fromSuperglobal($raw);
        self::assertNull($s->userId);
        self::assertNull($s->indexDeriv);
        self::assertNull($s->mobileTheme);
        self::assertNull($s->refererImageId);
        self::assertSame([], $s->filterCategories);
    }

    public function testPersistRoundTrip(): void
    {
        $raw = [
            'pwg_uid'            => 11,
            'connected_with'     => 'pwg_ui',
            'pwg_show_metadata'  => true,
            'pwg_filter_categories' => [1, 2, 3],
            'page_infos'         => ['a', 'b'],
        ];

        $s = Session::fromSuperglobal($raw);
        $target = [];
        $s->persistInto($target);

        self::assertSame(11, $target['pwg_uid']);
        self::assertSame('pwg_ui', $target['connected_with']);
        self::assertSame(true, $target['pwg_show_metadata']);
        self::assertSame([1, 2, 3], $target['pwg_filter_categories']);
        self::assertSame(['a', 'b'], $target['page_infos']);
    }

    public function testPersistOmitsNullSlots(): void
    {
        $s = new Session();   // all defaults: null / false / []
        $target = [];
        $s->persistInto($target);

        self::assertArrayNotHasKey('pwg_uid', $target);
        self::assertArrayNotHasKey('connected_with', $target);
        self::assertArrayNotHasKey('pwg_filter_categories', $target);
        self::assertArrayNotHasKey('page_infos', $target);
        // Booleans are always written (their default is meaningful state).
        self::assertSame(false, $target['pwg_show_metadata']);
        self::assertSame(false, $target['pwg_filter_enabled']);
    }

    public function testPersistDoesNotClobberUnknownKeys(): void
    {
        // Plugins / legacy scratch must survive a Session round-trip
        // because Session only "owns" the canonical key set.
        $target = [
            'plugin_foo_state'  => ['anything' => 1],
            '_some_legacy_key'  => 'preserved',
        ];

        $s = Session::fromSuperglobal($target);
        $s->showMetadata = true;
        $s->persistInto($target);

        self::assertSame(['anything' => 1], $target['plugin_foo_state']);
        self::assertSame('preserved', $target['_some_legacy_key']);
        self::assertSame(true, $target['pwg_show_metadata']);
    }

    public function testLogoutResetsAllSlots(): void
    {
        $s = Session::fromSuperglobal([
            'pwg_uid'           => 9,
            'connected_with'    => 'pwg_ui',
            'pwg_show_metadata' => true,
            'pwg_filter_categories' => [1, 2],
        ]);

        $s->logout();
        self::assertNull($s->userId);
        self::assertNull($s->connectedWith);
        self::assertFalse($s->showMetadata);
        self::assertSame([], $s->filterCategories);
    }

    public function testMutationsPersist(): void
    {
        $s = Session::fromSuperglobal([]);
        $s->userId         = UserId::from(3);
        $s->connectedWith  = 'ws_session_login';
        $s->showMetadata   = true;
        $s->flash->add('info', 'logged in');

        $target = [];
        $s->persistInto($target);

        self::assertSame(3, $target['pwg_uid']);
        self::assertSame('ws_session_login', $target['connected_with']);
        self::assertSame(true, $target['pwg_show_metadata']);
        self::assertSame(['logged in'], $target['page_infos']);
    }

    public function testFlashBagIsHeldByComposition(): void
    {
        // Default-constructed Session has an empty FlashBag (not null) so
        // every consumer can call $session->flash->add() without guarding.
        $s = new Session();
        self::assertInstanceOf(FlashBag::class, $s->flash);
        self::assertFalse($s->flash->hasAny());
    }
}
