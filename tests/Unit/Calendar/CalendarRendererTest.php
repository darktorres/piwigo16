<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\CalendarNavCachePool;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Calendar\CalendarRenderer;
use Piwigo\Category\CategoryRepository;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMetrics;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Calendar\CalendarRenderer -- 12 real constructor deps, no
 * dedicated Integration/Browser spec of its own (reached only via
 * GalleryController). Covers CalendarService::buildInnerSql()'s own
 * real, documented "nothing to do" early return -- an empty $items list
 * on a non-"categories" section returns null before any real DB query
 * runs, matching that method's own `if ($items === []) { return null; }`
 * guard. The "categories" section branch (a materially heavier real
 * subcategory/permission DB query) is not covered here.
 */
function calendarRendererTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-calendar-renderer-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function calendarRendererTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? calendarRendererTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function calendarRendererTestImageStdParams(): ImageStdParams
{
    $imageStdParams = Kernel::container()->get(ImageStdParams::class);
    if (! $imageStdParams instanceof ImageStdParams) {
        throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
    }

    return $imageStdParams;
}

test('render() returns a no-op result for a non-categories section with no items', function (): void {
    $root = calendarRendererTestRoot();
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Normal,
        enabledHigh: false,
    ));

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        $conn = DbConnection::build();
        $renderer = new CalendarRenderer(
            LangTestFactory::get(),
            HtmlServiceTestFactory::build(),
            $template,
            UrlServiceTestFactory::build(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            new EventDispatcher(),
            new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
            calendarRendererTestImageStdParams(),
            new RequestMetrics(),
            new PermissionService(
                new PermissionRepository(EntityManagerFactory::build($conn)),
                EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
                new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfigTestFactory::get()),
                CurrentUserTestFactory::get(),
                new FilterState(),
                new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
            ),
            EntityManagerFactory::build($conn),
            new CalendarNavCachePool(CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30)),
        );

        $result = $renderer->render(Section::Tags, null, [], 'posted', null, null, [], false);

        expect($result->items)
            ->toBe([])
            ->and($result->comment)
            ->toBe('')
            ->and($result->chronologyDate)
            ->toBe([])
            ->and($result->chronologyStyle)
            ->toBeNull()
            ->and($result->chronologyView)
            ->toBeNull();
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        calendarRendererTestRrmdir($root);
    }
});
