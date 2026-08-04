<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Rate\RateRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;

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
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    CurrentConfig::setDataDirChecked('1');
    CurrentTemplate::current()->set(new Template());
});

afterEach(function (): void {
    picture_rate_test_rrmdir(CurrentPaths::get()->root);
    CurrentTemplate::current()->reset();
    Kernel::reset();
    CurrentConfig::reset();
});

test('render does nothing when rating is disabled', function (): void {
    CurrentConfig::setRateEnabled(false);
    $renderer = new PictureRateRenderer(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Rate\RateEntity::class), \Piwigo\Users\CurrentUser::current(), \Piwigo\Template\CurrentTemplate::current());

    $renderer->render(42, new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), [], '/picture.php');

    expect(CurrentTemplate::current()->get()->get_template_vars('rate_summary'))->toBeNull()
        ->and(CurrentTemplate::current()->get()->get_template_vars('rating'))->toBeNull();
});
