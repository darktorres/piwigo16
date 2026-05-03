<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Users\CurrentUser;

final class KernelBootTest extends TestCase
{
    protected function setUp(): void
    {
        Kernel::reset();
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        // Clean up $GLOBALS side-effects from attachGlobals() calls
        unset($GLOBALS['conf'], $GLOBALS['page'], $GLOBALS['lang'], $GLOBALS['user']);
    }

    public function test_isBooted_false_before_boot(): void
    {
        self::assertFalse(Kernel::isBooted());
    }

    public function test_boot_is_idempotent(): void
    {
        $this->simulateGlobals();
        Kernel::boot();
        Kernel::boot(); // second call must not throw or corrupt state
        self::assertTrue(Kernel::isBooted());
    }

    public function test_Config_get_reads_from_globals_after_boot(): void
    {
        $this->simulateGlobals(['conf' => ['upload_dir' => './myupload', 'gallery_title' => 'My Gallery']]);
        Kernel::boot();

        self::assertSame('./myupload', Config::get('upload_dir'));
        self::assertSame('My Gallery', Config::galleryTitle());
    }

    public function test_conf_write_after_boot_visible_via_Config_get(): void
    {
        $this->simulateGlobals(['conf' => ['order_by' => 'ORDER BY id ASC']]);
        Kernel::boot();

        $GLOBALS['conf']['order_by'] = 'ORDER BY date_creation DESC';

        self::assertSame('ORDER BY date_creation DESC', Config::get('order_by'));
    }

    public function test_PageState_addError_visible_via_page_global_after_boot(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        PageState::current()->addError('typed error');

        self::assertContains('typed error', $GLOBALS['page']['errors']);
    }

    public function test_page_global_push_visible_via_PageState_after_boot(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        $GLOBALS['page']['errors'][] = 'global error';

        self::assertContains('global error', PageState::current()->errors);
    }

    public function test_existing_page_errors_preserved_after_boot(): void
    {
        $this->simulateGlobals(['page' => ['errors' => ['pre-boot error'], 'warnings' => [], 'messages' => [], 'infos' => [], 'body_classes' => [], 'body_data' => [], 'execution_uuid' => 'abc']]);
        Kernel::boot();

        self::assertContains('pre-boot error', PageState::current()->errors);
    }

    public function test_CurrentUser_get_returns_user_after_boot(): void
    {
        $this->simulateGlobals(['user' => ['id' => 5, 'username' => 'alice', 'email' => 'alice@example.com', 'language' => 'en_US', 'theme' => 'elegant', 'status' => 'webmaster', 'enabled_high' => true]]);
        Kernel::boot();

        $user = CurrentUser::get();
        self::assertSame(5, $user->id);
        self::assertSame('alice', $user->username);
        self::assertSame('alice@example.com', $user->email);
    }

    /** Step 3 exit signal: CurrentUser::get()->username === $user['username'] after boot. */
    public function test_CurrentUser_username_equals_global_user_username_after_boot(): void
    {
        $this->simulateGlobals(['user' => ['id' => 7, 'username' => 'bob', 'email' => '', 'language' => 'fr_FR', 'theme' => 'elegant', 'status' => 'normal', 'enabled_high' => false]]);
        Kernel::boot();

        self::assertSame($GLOBALS['user']['username'], CurrentUser::get()->username);
    }

    public function test_Lang_t_reads_from_globals_after_boot(): void
    {
        $this->simulateGlobals(['lang' => ['guest' => 'Guest', 'Login' => 'Login']]);
        Kernel::boot();

        self::assertSame('Guest', Lang::t('guest'));
    }

    public function test_lang_global_write_after_boot_visible_via_Lang(): void
    {
        $this->simulateGlobals(['lang' => ['key' => 'Value']]);
        Kernel::boot();

        $GLOBALS['lang']['new_key'] = 'New Value';
        self::assertSame('New Value', Lang::t('new_key'));
    }

    public function test_ServiceLocator_has_PageState_after_boot(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        self::assertTrue(ServiceLocator::has(PageState::class));
        self::assertInstanceOf(PageState::class, ServiceLocator::get(PageState::class));
    }

    public function test_ServiceLocator_Config_instance_is_same_as_Config_instance(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        self::assertTrue(ServiceLocator::has(Config::class));
        self::assertSame(Config::instance(), ServiceLocator::get(Config::class));
    }

    public function test_reset_clears_booted_flag(): void
    {
        $this->simulateGlobals();
        Kernel::boot();
        self::assertTrue(Kernel::isBooted());

        Kernel::reset();
        self::assertFalse(Kernel::isBooted());
    }

    // ---- helpers ---------------------------------------------------------

    /** @param array<string,mixed> $overrides */
    private function simulateGlobals(array $overrides = []): void
    {
        $GLOBALS['conf'] = $overrides['conf'] ?? ['upload_dir' => './upload'];
        $GLOBALS['page'] = $overrides['page'] ?? ['errors' => [], 'warnings' => [], 'messages' => [], 'infos' => [], 'body_classes' => [], 'body_data' => [], 'execution_uuid' => 'test-uuid'];
        $GLOBALS['lang'] = $overrides['lang'] ?? [];
        $GLOBALS['user'] = $overrides['user'] ?? ['id' => 2, 'username' => 'guest', 'email' => '', 'language' => 'en_US', 'theme' => 'elegant', 'status' => 'guest', 'enabled_high' => false];
    }
}
