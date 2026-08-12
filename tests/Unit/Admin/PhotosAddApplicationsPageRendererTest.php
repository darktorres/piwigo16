<?php

declare(strict_types=1);

use Piwigo\Admin\PhotosAddApplicationsPageRenderer;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
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
 * config/admin_pages.php), but this class's own logic (a single static
 * PhotosAddApplicationsPageContext assignment) has no branches worth a
 * duplicate end-to-end HTTP test.
 *
 * A real Template is required (not a fake): assignVarFromTemplate()
 * calls Template::parse(), which needs a real
 * photos_add_applications.latte file on the template-dir chain or it
 * hits Template's own htmlRenderer()->fatalError() branch -- same
 * real-fs technique LatteEngineWiringTest.php's own
 * "assignVarFromTemplate() renders a real .latte file" test already
 * established.
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

test('render() assigns the page title and parses photos_add_applications.latte into ADMIN_CONTENT', function (): void {
    $root = photosAddApplicationsTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'photos_add_applications.latte', 'title={$ADMIN_PAGE_TITLE}');
        $template->setTemplateDir($tplDir);

        new PhotosAddApplicationsPageRenderer()
            ->render(LangTestFactory::get(), CurrentTemplateTestFactory::get());

        // assignVarFromTemplate() wraps ADMIN_CONTENT in Latte\Runtime\Html
        // (see that method's own docblock), not a plain string.
        $adminContent = $template->getTemplateVars('ADMIN_CONTENT');
        expect($adminContent)
            ->toBeInstanceOf(Latte\Runtime\Html::class);
        if (! $adminContent instanceof Latte\Runtime\Html) {
            throw new LogicException('unreachable -- asserted above');
        }

        expect($template->getTemplateVars('ADMIN_PAGE_TITLE'))
            ->toBe('Upload Photos')
            ->and((string) $adminContent)
            ->toBe('title=Upload Photos');
    } finally {
        photosAddApplicationsTestRrmdir($root);
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
    }
});
