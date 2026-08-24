<?php

declare(strict_types=1);

use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentRepository;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RequestMetrics;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Category\CategoryDefaultRenderer -- 13 real constructor deps, no
 * dedicated Integration/Browser spec of its own (reached only via
 * GalleryController, which threads the real per-request page selection
 * through). Only the empty-selection happy path is covered: an empty
 * $items list short-circuits the whole real ImageRepository::findByIds()
 * + per-picture template-shaping loop, leaving CommentCounterInterface
 * unqueried (type-satisfying instance only, matching this campaign's
 * established reasoning).
 */
function categoryDefaultTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-category-default-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function categoryDefaultTestRrmdir(string $dir): void
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
        is_dir($path) ? categoryDefaultTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function categoryDefaultTestImageStdParams(): ImageStdParams
{
    $imageStdParams = Kernel::container()->get(ImageStdParams::class);
    if (! $imageStdParams instanceof ImageStdParams) {
        throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
    }

    return $imageStdParams;
}

test('render() returns no slideshow url and assigns an empty thumbnail set for an empty selection', function (): void {
    $root = categoryDefaultTestRoot();
    unset($_SESSION['pwg_index_deriv']);
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
        $conn = DbConnection::build();

        /**
         * @psalm-suppress InvalidArgument getRepository() always really
         *   returns CommentEntity's own custom repositoryClass at runtime
         *   (CommentRepository), which implements CommentCounterInterface;
         *   Psalm's Doctrine plugin doesn't narrow getRepository()'s
         *   return type per-entity's own repositoryClass binding, only the
         *   generic EntityRepository<T>.
         */
        $renderer = new CategoryDefaultRenderer(
            HtmlServiceTestFactory::build(),
            TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ImageEntity::class), ImageRepository::class),
            TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(CommentEntity::class), CommentRepository::class),
            UrlServiceTestFactory::build(),
            new SessionService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), SessionRepository::class), new CurrentConfig()),
            new EventDispatcher(),
            categoryDefaultTestImageStdParams(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            LangTestFactory::get(),
            new ProcessCache(),
            new RequestMetrics(),
        );

        $result = $renderer->render([], 0, 10, Section::Categories);

        expect($result->slideshowUrl)
            ->toBeNull()
            ->and($result->thumbnails)
            ->toBe([]);
    } finally {
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        categoryDefaultTestRrmdir($root);
    }
});
