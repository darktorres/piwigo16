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
            'piwigo_needs_update'  => true,
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
        self::assertTrue($s->piwigoNeedsUpdate);
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

    public function testPersistRoundTripPreservesUnmutatedState(): void
    {
        // Snapshot-diff: hydrate from a populated $_SESSION, persist back
        // without mutations — the original keys must still be there because
        // they were preserved (not because Session re-wrote them; persistInto
        // sees no diff and leaves the target alone).
        $raw = [
            'pwg_uid'            => 11,
            'connected_with'     => 'pwg_ui',
            'pwg_show_metadata'  => true,
            'pwg_filter_categories' => [1, 2, 3],
            'page_infos'         => ['a', 'b'],
        ];

        $s = Session::fromSuperglobal($raw);
        $target = $raw;          // simulate the in-place $_SESSION mutation pattern
        $s->persistInto($target);

        self::assertSame($raw, $target);
    }

    public function testPersistOmitsNullAndFalseSlots(): void
    {
        $s = new Session();   // all defaults: null / false / []
        $target = [];
        $s->persistInto($target);

        self::assertArrayNotHasKey('pwg_uid', $target);
        self::assertArrayNotHasKey('connected_with', $target);
        self::assertArrayNotHasKey('pwg_filter_categories', $target);
        self::assertArrayNotHasKey('page_infos', $target);
        // Bool flags persist only when true so legacy isset() checks stay
        // accurate — a false flag must NOT leave a set key behind.
        self::assertArrayNotHasKey('pwg_show_metadata', $target);
        self::assertArrayNotHasKey('pwg_filter_enabled', $target);
        self::assertArrayNotHasKey('no_photo_yet', $target);
        self::assertArrayNotHasKey('upload_hide_warnings', $target);
    }

    public function testTrueBoolFlagPersists(): void
    {
        $s = new Session();
        $s->showMetadata = true;
        $target = [];
        $s->persistInto($target);
        // Round-trip matches what `isset($_SESSION['pwg_show_metadata'])`
        // sees on the legacy side.
        self::assertTrue(isset($target['pwg_show_metadata']));
        self::assertSame(true, $target['pwg_show_metadata']);
    }

    public function testFalseBoolFlagClearsExistingKey(): void
    {
        // Hydrate with the flag set, flip it off, persist — the key must be
        // removed so legacy isset() checks return false.
        $s = Session::fromSuperglobal(['pwg_show_metadata' => true]);
        self::assertTrue($s->showMetadata);
        $s->showMetadata = false;
        $target = ['pwg_show_metadata' => true];
        $s->persistInto($target);
        self::assertArrayNotHasKey('pwg_show_metadata', $target);
    }

    public function testValidResetPasswordCodeIsArrayPayload(): void
    {
        $payload = ['code' => 'abc', 'pwg_uid' => 7];
        $target  = ['valid_reset_password_code' => $payload];
        $s = Session::fromSuperglobal($target);
        self::assertSame($payload, $s->validResetPasswordCode);

        // No mutation — payload survives the hydrate/persist round-trip
        // because snapshot-diff sees no change for this slot.
        $s->persistInto($target);
        self::assertSame($payload, $target['valid_reset_password_code']);
    }

    public function testValidResetPasswordCodeMutationPersists(): void
    {
        // Mutate the slot — snapshot-diff writes the new payload to target.
        $s = Session::fromSuperglobal([]);
        $newPayload = ['code' => 'xyz', 'pwg_uid' => 9];
        $s->validResetPasswordCode = $newPayload;

        $target = [];
        $s->persistInto($target);
        self::assertSame($newPayload, $target['valid_reset_password_code']);
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

    public function testPersistLeavesForeignKeysAlone(): void
    {
        // Snapshot-diff contract: Session has no snapshot entry for keys it
        // didn't read (plugin scratch, raw $_SESSION writes by unmigrated
        // consumers like AuthService writing pwg_uid during login), so the
        // diff is empty for those keys and persistInto must leave them
        // untouched.
        $target = [
            'plugin_foo'        => ['some' => 'state'],
            'pwg_uid'           => 7,        // unmigrated AuthService write
            'pwg_show_metadata' => true,     // unmigrated toggle
        ];

        $s = Session::fromSuperglobal([]);   // hydrate from empty: every slot at default
        $s->flash->add('info', 'hello');     // ONLY mutation
        $s->persistInto($target);

        // Flash mutation made it in.
        self::assertSame(['hello'], $target['page_infos']);
        // Foreign keys are exactly as the caller left them.
        self::assertSame(['some' => 'state'], $target['plugin_foo']);
        self::assertSame(7, $target['pwg_uid']);
        self::assertSame(true, $target['pwg_show_metadata']);
    }

    public function testPersistUnsetsSlotThatWentToDefault(): void
    {
        // Hydrate with userId set, mutate to null (logout-style), persist:
        // the key must be removed from target. Snapshot.pwg_uid = 5,
        // current state has no pwg_uid → diff → unset target['pwg_uid'].
        $s = Session::fromSuperglobal(['pwg_uid' => 5]);
        $s->userId = null;

        $target = ['pwg_uid' => 5];
        $s->persistInto($target);
        self::assertArrayNotHasKey('pwg_uid', $target);
    }

    public function testPersistIsNoOpWhenNothingChanged(): void
    {
        $raw = [
            'pwg_uid'           => 5,
            'connected_with'    => 'pwg_ui',
            'pwg_show_metadata' => true,
        ];
        $s = Session::fromSuperglobal($raw);

        // No mutations.
        $target = $raw;
        $s->persistInto($target);

        // Target unchanged.
        self::assertSame($raw, $target);
    }
}
