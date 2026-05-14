<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Users\CurrentUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class KernelBootTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Kernel::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['page'], $GLOBALS['lang'], $GLOBALS['user']);
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

    public function test_typed_accessor_reads_from_loaded_data_after_boot(): void
    {
        $this->simulateGlobals(['conf' => ['upload_dir' => './myupload', 'gallery_title' => 'My Gallery']]);
        Kernel::boot();

        self::assertSame('./myupload', Config::uploadDir());
        self::assertSame('My Gallery', Config::galleryTitle());
    }

    public function test_override_after_boot_visible_via_typed_accessor(): void
    {
        $this->simulateGlobals(['conf' => ['order_by' => 'ORDER BY id ASC']]);
        Kernel::boot();

        Config::override('order_by', 'ORDER BY date_creation DESC');

        self::assertSame('ORDER BY date_creation DESC', Config::orderBy());
    }

    public function test_PageState_addError_works_after_boot(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        PageState::current()->addError('typed error');

        self::assertContains('typed error', PageState::current()->errors);
    }

    public function test_PageState_is_initialised_after_boot(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        // attachGlobals() initialises the PageState singleton; $GLOBALS['page'] is no longer reset.
        self::assertInstanceOf(\Piwigo\Core\PageState::class, \Piwigo\Core\PageState::current());
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

        $user = $GLOBALS['user'];
        self::assertIsArray($user);
        self::assertSame($user['username'], CurrentUser::get()->username);
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

        $langRef = &$GLOBALS['lang'];
        self::assertIsArray($langRef);
        $langRef['new_key'] = 'New Value';
        self::assertSame('New Value', Lang::t('new_key'));
    }

    public function test_container_has_PageState_after_boot(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        self::assertInstanceOf(PageState::class, Kernel::service(PageState::class));
    }

    public function test_container_Config_instance_is_same_as_Config_instance(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        self::assertSame(Config::instance(), Kernel::service(Config::class));
    }

    public function test_reset_clears_booted_flag(): void
    {
        $this->simulateGlobals();
        Kernel::boot();
        self::assertTrue(Kernel::isBooted());

        Kernel::reset();
        self::assertFalse(Kernel::isBooted());
    }

    public function test_container_returns_ContainerInterface_after_boot(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        self::assertInstanceOf(ContainerInterface::class, Kernel::container());
    }

    public function test_container_resolves_Config_to_same_instance(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        self::assertSame(Config::instance(), Kernel::container()->get(Config::class));
    }

    public function test_container_resolves_PageState_to_same_instance(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        self::assertSame(PageState::current(), Kernel::container()->get(PageState::class));
    }

    public function test_container_resolves_LoggerInterface_to_NullLogger_when_registry_empty(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        self::assertInstanceOf(NullLogger::class, Kernel::container()->get(LoggerInterface::class));
    }

    public function test_container_throws_before_boot(): void
    {
        $this->expectException(\LogicException::class);
        Kernel::container();
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @param array{conf?: array<string, mixed>, lang?: array<string, mixed>, user?: array<string, mixed>} $overrides
     */
    private function simulateGlobals(array $overrides = []): void
    {
        $confSeed = $overrides['conf'] ?? ['upload_dir' => './upload'];
        Config::loadArray($confSeed);
        $GLOBALS['page'] = [];
        $GLOBALS['lang'] = $overrides['lang'] ?? [];
        $GLOBALS['user'] = $overrides['user'] ?? ['id' => 2, 'username' => 'guest', 'email' => '', 'language' => 'en_US', 'theme' => 'elegant', 'status' => 'guest', 'enabled_high' => false];
    }
}
