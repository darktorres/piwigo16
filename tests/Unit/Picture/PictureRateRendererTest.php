<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Rate\RateEntity;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;

/**
 * Same "point CurrentPaths at a fresh temp root" Template setup as
 * PictureCommentRendererTest.php -- see that file's own docblock.
 * render() also stays DB-free whenever rate_enabled=true but
 * $picture['current']['rating_score'] is null -- neither repository call
 * is reached in that case either; every branch that does need a real
 * RateRepository row stays at Integration level.
 */
function picture_rate_test_rrmdir(string $dir): void
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
        is_dir($path) ? picture_rate_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-picture-rate-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    // Captured on $this, not re-read via CurrentPathsTestFactory::get() in
    // afterEach() below -- if Kernel::boot() throws here (a prior test left
    // Kernel booted against a different root without resetting), afterEach()
    // still runs, and re-resolving through the container would delete
    // whatever root that earlier, unrelated test left bound instead of this
    // test's own fixture root.
    $this->root = $root;
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentTemplate::current()->set(TemplateTestFactory::build());
});

afterEach(function (): void {
    picture_rate_test_rrmdir($this->root);
    CurrentTemplate::current()->reset();
    Kernel::reset();
    CurrentConfigTestFactory::get()->reset();
});

test('render does nothing when rating is disabled', function (): void {
    CurrentConfigTestFactory::get()->rateEnabled = false;
    $accessControl = Kernel::container()->get(AccessControl::class);
    if (! $accessControl instanceof AccessControl) {
        throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
    }
    $renderer = new PictureRateRenderer($accessControl, EntityManagerFactory::build(DbConnection::build())->getRepository(RateEntity::class), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get());

    $renderer->render(42, UrlServiceTestFactory::build(), [], '/picture.php');

    expect(CurrentTemplate::current()->get()->get_template_vars('rate_summary'))->toBeNull()
        ->and(CurrentTemplate::current()->get()->get_template_vars('rating'))->toBeNull();
});
