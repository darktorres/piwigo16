<?php

declare(strict_types=1);

use Piwigo\Admin\PhotosAddApplicationsPageRenderer;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Admin\PhotosAddApplicationsPageRenderer -- zero-constructor,
 * every dependency passed as a render() param (B3 Tier 1 shape). No
 * dedicated Integration/Browser spec of its own -- reached only as the
 * "applications" tab of the "photos_add" page slug, dispatched by
 * PhotosAddSubController (a real Browser-level route hit, see
 * config/admin_pages.php), but this class's own logic (rendering a
 * PhotosAddApplicationsView carrying nothing but the upstream host)
 * has no branches worth a duplicate end-to-end HTTP test.
 *
 * That host is the one thing worth pinning here: the template used to
 * hardcode `piwigo.org` in all 22 of its screenshot/extension URLs, so
 * every view of the page made 9 live third-party image requests, which
 * AppInfo::DOMAIN exists specifically to prevent.
 *
 * A real Template is required (not a fake): Renderer::render() calls
 * Template::renderView(), which needs a real
 * photos_add_applications.latte file on the template-dir chain or it
 * hits Template's own htmlRenderer()->fatalError() branch -- same
 * real-fs technique LatteEngineWiringTest.php's own
 * "assignVarFromTemplate() renders a real .latte file" test already
 * established. `ADMIN_PAGE_TITLE` is assigned onto the template bag
 * only after this render call returns (see AdminContentPageContext),
 * so the fake fixture file's own body never references it -- unlike
 * this render call's own downstream ADMIN_PAGE_TITLE assign, which the
 * test checks separately.
 */
function photosAddApplicationsTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-photos-add-applications-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    // Template::__construct() unconditionally mkgetdir()s a real
    // templates_c compile directory and (unless setDataDirChecked('1')
    // already skips it) hits a real DB write via CurrentConfigService --
    // same technique PictureCommentRendererTest.php already established.
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function photosAddApplicationsTestRrmdir(string $dir): void
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
        is_dir($path) ? photosAddApplicationsTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('render() assigns the page title and renders photos_add_applications.latte into ADMIN_CONTENT', function (): void {
    $root = photosAddApplicationsTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'photos_add_applications.latte', 'static content at {$phpwgUrl}');
        $template->setTemplateDir($tplDir);

        $result = new PhotosAddApplicationsPageRenderer()
            ->render(LangTestFactory::get(), CurrentTemplateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()));

        expect($result->pageTitle)
            ->toBe('Upload Photos')
            ->and((string) $result->content)
            ->toBe('static content at ' . AppInfo::URL)
            ->and(AppInfo::URL)
            ->not->toContain('piwigo.org');
    } finally {
        photosAddApplicationsTestRrmdir($root);
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
    }
});
