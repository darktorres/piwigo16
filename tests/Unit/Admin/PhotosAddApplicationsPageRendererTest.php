<?php

declare(strict_types=1);

use Piwigo\Admin\PhotosAddApplicationsPageRenderer;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Template\CurrentTemplate;

/**
 * Piwigo\Admin\PhotosAddApplicationsPageRenderer -- zero-constructor,
 * every dependency passed as a render() param (B3 Tier 1 shape). No
 * dedicated Integration/Browser spec of its own -- reached only as the
 * "applications" tab of the "photos_add" page slug, dispatched by
 * PhotosAddSubController (a real Browser-level route hit, see
 * config/admin_pages.php), but this class's own logic (a single static
 * PhotosAddApplicationsPageContext assignment) has no branches worth a
 * duplicate end-to-end HTTP test.
 *
 * A real Template is required (not a fake): assign_var_from_handle()
 * calls Template::parse(), which needs a real, registered .tpl file for
 * the 'photos_add' handle or it hits Template's own htmlRenderer()->
 * fatalError() branch -- same real-fs technique
 * TemplateInstanceTest.php's own "assign_var_from_handle assigns the
 * parsed handle output" test already established.
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
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

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

test('render() assigns the page title and parses the photos_add handle into ADMIN_CONTENT', function (): void {
    $root = photosAddApplicationsTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'photos_add.tpl', 'title={$ADMIN_PAGE_TITLE}');
        $template->set_template_dir($tplDir);
        $template->set_filename('photos_add', 'photos_add.tpl');

        new PhotosAddApplicationsPageRenderer()->render(LangTestFactory::get(), CurrentTemplate::current());

        expect($template->get_template_vars('ADMIN_PAGE_TITLE'))->toBe('Upload Photos')
            ->and($template->get_template_vars('ADMIN_CONTENT'))->toBe('title=Upload Photos');
    } finally {
        photosAddApplicationsTestRrmdir($root);
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
    }
});
