<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ApiContext;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Page\NoPhotoYetRenderer -- this class's own docblock already
 * scopes what's Unit-testable: "Tests only exercise the
 * guard-condition-false and nb_photos>0 branches, since exit() would
 * kill the test process." No dedicated Integration/Browser spec of its
 * own -- reached from Bootstrap\RequestBootstrap.php's own inline
 * construction.
 *
 * The real fixture has 4 real images, so countAllImages() > 0 holds
 * naturally with zero setup -- confUpdateParam('no_photo_yet', 'false')
 * on that branch is a genuine no-op write: the fixture's own
 * no_photo_yet row is already the literal string 'false'.
 */
function noPhotoYetTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-no-photo-yet-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function noPhotoYetTestRrmdir(string $dir): void
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
        is_dir($path) ? noPhotoYetTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function noPhotoYetTestRenderer(AdminContext $adminContext, ApiContext $apiContext = new ApiContext()): NoPhotoYetRenderer
{
    $conn = DbConnection::build();

    return new NoPhotoYetRenderer(
        LangTestFactory::get(),
        new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
        TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ImageEntity::class), ImageRepository::class),
        new ConfigService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), ConfigRepository::class), CurrentConfigTestFactory::get()),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        UrlServiceTestFactory::build(),
        Paths::fromRoot(sys_get_temp_dir()),
        $adminContext,
        $apiContext,
        new EventDispatcher(),
        CurrentUserTestFactory::get(),
        CurrentTemplateTestFactory::get(),
        CurrentConfigTestFactory::get(),
        new ProcessCache(),
        CurrentConfigServiceTestFactory::get(),
        new Renderer(CurrentTemplateTestFactory::get()),
        PageStateTestFactory::get(),
        HtmlServiceTestFactory::build(),
        ImageStdParamsTestFactory::get(),
    );
}

test('render() does nothing when the admin context is active', function (): void {
    $root = noPhotoYetTestRoot();
    unset($_SESSION['no_photo_yet']);
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        // This guard is a pure early-return with almost no observable
        // side effect (matching this class's own docblock: "no message
        // inside administration") -- proven here by the fact that
        // CurrentTemplateTestFactory::get() is still the exact same instance
        // set above, not replaced by render()'s own real-theme Template
        // construction inside the guarded block.
        noPhotoYetTestRenderer(new AdminContext(active: true))
            ->render();

        expect(CurrentTemplateTestFactory::get()->get())->toBe($template);
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        noPhotoYetTestRrmdir($root);
    }
});

test('render() does nothing when the API context is active', function (): void {
    $root = noPhotoYetTestRoot();
    unset($_SESSION['no_photo_yet']);
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        // A JSON API response must never be replaced by this HTML page --
        // in particular, /api/v1/session itself has to stay reachable on a
        // zero-photo gallery, or an admin can never log in through the
        // modern frontend to deactivate no_photo_yet in the first place.
        // Same "no observable side effect" proof as the admin-context test
        // above.
        noPhotoYetTestRenderer(new AdminContext(active: false), new ApiContext(active: true))
            ->render();

        expect(CurrentTemplateTestFactory::get()->get())->toBe($template);
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        noPhotoYetTestRrmdir($root);
    }
});

test('render() does nothing when photos already exist, only refreshing the no_photo_yet config flag', function (): void {
    $root = noPhotoYetTestRoot();
    unset($_SESSION['no_photo_yet']);
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $conn = DbConnection::build();
        $before = $conn->fetchOne('SELECT value FROM config' . " WHERE param = 'no_photo_yet'");

        noPhotoYetTestRenderer(new AdminContext(active: false))
            ->render();

        $after = $conn->fetchOne('SELECT value FROM config' . " WHERE param = 'no_photo_yet'");
        expect($after)
            ->toBe($before)
            ->and($after)
            ->toBe('"false"');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        noPhotoYetTestRrmdir($root);
    }
});
