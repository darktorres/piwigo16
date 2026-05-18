<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
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
        unset($GLOBALS['lang'], $GLOBALS['user']);
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
        $this->simulateGlobals(['conf' => ['order_by' => [['field' => 'id', 'dir' => 'ASC']]]]);
        Kernel::boot();

        Config::override('order_by', [['field' => 'date_creation', 'dir' => 'DESC']]);

        self::assertSame([['field' => 'date_creation', 'dir' => 'DESC']], Config::orderBy());
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

        // Kernel::boot() initialises the PageState singleton via PageState::current().
        self::assertInstanceOf(PageState::class, PageState::current());
    }

    public function test_CurrentUser_get_returns_guest_after_boot_alone(): void
    {
        // Kernel::boot() calls attachGlobals() which creates a default guest User.
        // The real user is set by UserBootstrap::bootstrap() (AuthMiddleware), not by boot().
        $this->simulateGlobals();
        Kernel::boot();

        $user = CurrentUser::get();
        self::assertSame('guest', $user->status);
    }

    public function test_CurrentUser_set_allows_overriding_user_after_boot(): void
    {
        $this->simulateGlobals();
        Kernel::boot();

        $alice = User::fromUserArray(['id' => 5, 'username' => 'alice', 'email' => 'alice@example.com',
            'language' => 'en_US', 'theme' => 'elegant', 'status' => 'webmaster', 'enabled_high' => true]);
        CurrentUser::set($alice);

        self::assertSame(5, CurrentUser::get()->id);
        self::assertSame('alice', CurrentUser::get()->username);
    }

    public function test_Lang_t_reads_from_globals_after_boot(): void
    {
        $this->simulateGlobals(['lang' => ['guest' => 'Guest', 'Login' => 'Login']]);
        Kernel::boot();

        self::assertSame('Guest', Lang::t('guest'));
    }

    public function test_lang_pre_boot_data_snapshotted_by_attachGlobals(): void
    {
        // Data in $GLOBALS['lang'] before boot is captured by attachGlobals() into Lang::$data
        $this->simulateGlobals(['lang' => ['key' => 'Value']]);
        Kernel::boot();

        self::assertSame('Value', Lang::t('key'));
        // After attachGlobals(), $GLOBALS['lang'] is unset — the bridge is removed.
        self::assertArrayNotHasKey('lang', $GLOBALS);
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
        $GLOBALS['lang'] = $overrides['lang'] ?? [];
        $GLOBALS['user'] = $overrides['user'] ?? ['id' => 2, 'username' => 'guest', 'email' => '', 'language' => 'en_US', 'theme' => 'elegant', 'status' => 'guest', 'enabled_high' => false];
    }
}
