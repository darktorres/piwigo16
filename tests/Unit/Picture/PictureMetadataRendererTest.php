<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PictureElementTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

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
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }

    return $currentConfig;
}

function picture_metadata_test_entity_manager(): EntityManagerInterface
{
    $entityManager = Kernel::container()->get(EntityManagerInterface::class);
    if (! $entityManager instanceof EntityManagerInterface) {
        throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
    }

    return $entityManager;
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-picture-metadata-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    // Captured on $this, not re-read via CurrentPathsTestFactory::get() in
    // afterEach() below -- if Kernel::boot() throws here (a prior test left
    // Kernel booted against a different root without resetting), afterEach()
    // still runs, and re-resolving through the container would delete
    // whatever root that earlier, unrelated test left bound instead of this
    // test's own fixture root.
    $this->root = $root;
    Kernel::boot(Paths::fromRoot($root));
    picture_metadata_test_current_config()
        ->dataLocation = 'data/';
    picture_metadata_test_current_config()
        ->dataDirChecked = '1';
    CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build());
});

afterEach(function (): void {
    picture_metadata_test_rrmdir($this->root);
    CurrentTemplateTestFactory::get()->reset();
    Kernel::reset();
    CurrentConfigTestFactory::get()->reset();
});

test('render appends nothing when both show_exif and show_iptc are disabled', function (): void {
    picture_metadata_test_current_config()->showExif = false;
    picture_metadata_test_current_config()
        ->showIptc = false;
    $renderer = new PictureMetadataRenderer();

    $metadata = $renderer->render(LangTestFactory::get(), PictureElementTestFactory::build(), new CurrentLogger(), new EventDispatcher(), CurrentConfigTestFactory::get(), CurrentUserTestFactory::get(), CurrentPathsTestFactory::get(), picture_metadata_test_entity_manager());

    expect($metadata)
        ->toBeNull();
});
