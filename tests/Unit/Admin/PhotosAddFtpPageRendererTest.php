<?php

declare(strict_types=1);

use Piwigo\Admin\PhotosAddFtpPageRenderer;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Template\CurrentTemplate;

/**
 * Piwigo\Admin\PhotosAddFtpPageRenderer -- zero-constructor, every
 * dependency passed as a render() param (B3 Tier 1 shape). No dedicated
 * Integration/Browser spec -- reached only as the "ftp" tab of the
 * "photos_add" page slug (dispatched by PhotosAddSubController).
 *
 * A deliberately empty (no real language/ tree) Paths root -- Lang::load()'s
 * own real-content path just reads a bundled help/*.html file verbatim
 * with no branching of its own, while the `is_string($x) ? $x : ''`
 * fallback right here IS this class's own real decision logic; a
 * disposable temp root exercises that fallback directly and
 * deterministically, without depending on real bundled language/ content
 * this class doesn't own.
 */
function photosAddFtpTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-photos-add-ftp-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function photosAddFtpTestRrmdir(string $dir): void
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
        is_dir($path) ? photosAddFtpTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('render() falls back to empty ftp help content when the real language file is missing, and still assigns the page title', function (): void {
    $root = photosAddFtpTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'photos_add.tpl', 'ftp={$FTP_HELP_CONTENT}|title={$ADMIN_PAGE_TITLE}');
        $template->set_template_dir($tplDir);
        $template->set_filename('photos_add', 'photos_add.tpl');

        new PhotosAddFtpPageRenderer()->render(LangTestFactory::get(), CurrentTemplate::current());

        expect($template->get_template_vars('FTP_HELP_CONTENT'))->toBe('')
            ->and($template->get_template_vars('ADMIN_PAGE_TITLE'))->toBe('Upload Photos')
            ->and($template->get_template_vars('ADMIN_CONTENT'))->toBe('ftp=|title=Upload Photos');
    } finally {
        photosAddFtpTestRrmdir($root);
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
    }
});
