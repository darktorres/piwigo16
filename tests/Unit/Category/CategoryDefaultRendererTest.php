<?php

declare(strict_types=1);

use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Comment\CommentEntity;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Template\CurrentTemplate;
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
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

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
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'thumbnails.tpl', 'count={$thumbnails|@count}');
        $template->set_template_dir($tplDir);

        $conn = DbConnection::build();

        $renderer = new CategoryDefaultRenderer(
            HtmlServiceTestFactory::build(),
            $template,
            EntityManagerFactory::build($conn)->getRepository(ImageEntity::class),
            EntityManagerFactory::build($conn)->getRepository(CommentEntity::class),
            UrlServiceTestFactory::build(),
            new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), new CurrentConfig()),
            new EventDispatcher(),
            categoryDefaultTestImageStdParams(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            LangTestFactory::get(),
            new ProcessCache(),
            new PageState(),
        );

        $slideshowUrl = $renderer->render([], 0, 10, Section::Categories);

        // render() explicitly clear_assign('thumbnails')s right after
        // parsing the handle -- 'thumbnails' itself is genuinely gone by
        // the time render() returns; THUMBNAILS (the parsed output) is
        // the real, lasting observable.
        expect($slideshowUrl)->toBeNull()
            ->and($template->get_template_vars('THUMBNAILS'))->toBe('count=0');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        categoryDefaultTestRrmdir($root);
    }
});
