<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Piwigo\Admin\AdminMenuGroup;
use Piwigo\Admin\AdminPage;
use Piwigo\Admin\AdminPageRegistry;
use Piwigo\Controller\Admin\AlbumController;
use Piwigo\Controller\Admin\ExtensionsController;
use Piwigo\Controller\Admin\UsersController;
use Piwigo\Core\AccessLevel;

final class AdminPageRegistryTest extends TestCase
{
    public function testRegisterFindHasCount(): void
    {
        $registry = new AdminPageRegistry();
        self::assertSame(0, $registry->count());

        $page = new AdminPage(
            slug: 'albums',
            label: 'admin.menu.album.albums',
            controllerClass: AlbumController::class,
            menuGroup: AdminMenuGroup::Albums,
            permission: AccessLevel::Administrator,
        );
        $registry->register($page);

        self::assertSame(1, $registry->count());
        self::assertTrue($registry->has('albums'));
        self::assertSame($page, $registry->find('albums'));
        self::assertNull($registry->find('does_not_exist'));
    }

    public function testDoubleRegistrationOfSameSlugThrows(): void
    {
        $registry = new AdminPageRegistry();
        $registry->register(new AdminPage('users', 'l', UsersController::class, AdminMenuGroup::Users));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already registered/');
        $registry->register(new AdminPage('users', 'l', ExtensionsController::class, AdminMenuGroup::Users));
    }

    public function testByGroupReturnsOnlyMatchingPages(): void
    {
        $registry = new AdminPageRegistry();
        $registry->register(new AdminPage('albums', 'a', AlbumController::class, AdminMenuGroup::Albums));
        $registry->register(new AdminPage('cat_modify', 'b', AlbumController::class, AdminMenuGroup::Albums));
        $registry->register(new AdminPage('user_list', 'c', UsersController::class, AdminMenuGroup::Users));

        $albums = $registry->byGroup(AdminMenuGroup::Albums);
        self::assertCount(2, $albums);
        $slugs = array_map(static fn (AdminPage $p): string => $p->slug, $albums);
        self::assertContains('albums', $slugs);
        self::assertContains('cat_modify', $slugs);

        self::assertCount(1, $registry->byGroup(AdminMenuGroup::Users));
        self::assertSame([], $registry->byGroup(AdminMenuGroup::Tools));
    }

    public function testAllReturnsMapKeyedBySlug(): void
    {
        $registry = new AdminPageRegistry();
        $registry->register(new AdminPage('plugins', 'l', ExtensionsController::class, AdminMenuGroup::Plugins));
        $all = $registry->all();
        self::assertArrayHasKey('plugins', $all);
        self::assertSame('plugins', $all['plugins']->slug);
    }
}
