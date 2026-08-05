<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;

/**
 * Same "point CurrentPaths at a fresh temp root" Template setup as
 * PictureCommentRendererTest.php -- see that file's own docblock.
 * render()'s only branch reachable without a real image file is
 * show_exif=false and show_iptc=false together, checked before
 * $picture['current']['src_image'] (and MetadataService's own
 * filesystem-bound getExifData()/getIptcData(), which read the image
 * file via getimagesize()/exif_read_data() rather than the database)
 * are ever touched; every other branch needs a real image file and
 * stays at Integration level.
 */
function picture_metadata_test_rrmdir(string $dir): void
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
        is_dir($path) ? picture_metadata_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function picture_metadata_test_current_config(): CurrentConfig
{
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new \LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }

    return $currentConfig;
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-picture-metadata-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    picture_metadata_test_current_config()->setDataLocation('data/');
    picture_metadata_test_current_config()->setDataDirChecked('1');
    CurrentTemplate::current()->set(new Template());
});

afterEach(function (): void {
    picture_metadata_test_rrmdir(CurrentPaths::get()->root);
    CurrentTemplate::current()->reset();
    Kernel::reset();
    \Piwigo\Config\CurrentConfig::current()->reset();
});

test('render appends nothing when both show_exif and show_iptc are disabled', function (): void {
    picture_metadata_test_current_config()->setShowExif(false);
    picture_metadata_test_current_config()->setShowIptc(false);
    $renderer = new PictureMetadataRenderer();

    $renderer->render(\Piwigo\Core\Lang::current(), [], new CurrentLogger(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current());

    expect(CurrentTemplate::current()->get()->get_template_vars('metadata'))->toBeNull();
});
