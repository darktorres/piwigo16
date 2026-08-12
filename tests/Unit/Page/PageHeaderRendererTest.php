<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Page\PageHeaderRenderer -- has no constructor at all (matches
 * B5's own T1 pattern; see this class's own docblock for why: RedirectService's
 * early-crash fallback `new`s it directly, so PHP-DI never autowires a
 * constructor through it). No dedicated Integration/Browser spec of its
 * own -- reached only as a shared page-chrome helper from every real page
 * render.
 *
 * Only the no-refresh, no-mobile-banner-override, no-header-notes happy
 * path is covered -- the $refresh/$urlLink meta-refresh branch and the
 * admin-context mobile-banner override both need Kernel::boot() with a
 * real AdminContext, a materially bigger unit of setup for 2 more
 * boolean branches.
 */
function pageHeaderTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-page-header-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function pageHeaderTestRrmdir(string $dir): void
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
        is_dir($path) ? pageHeaderTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('render() assigns the page title and gallery chrome with no refresh meta and no header notes', function (): void {
    $root = pageHeaderTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'header.latte', 'title={$PAGE_TITLE}');
        $template->setTemplateDir($tplDir);

        $pageState = new PageState();
        $eventDispatcher = new EventDispatcher();

        new PageHeaderRenderer()
            ->render('<b>My Gallery</b>', $eventDispatcher, $pageState, CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());

        expect($template->getTemplateVars('PAGE_TITLE'))
            ->toBe('My Gallery')
            ->and($template->getTemplateVars('meta_ref'))
            ->toBe(1)
            ->and($template->getTemplateVars('page_refresh'))
            ->toBeNull()
            ->and($template->getTemplateVars('header_notes'))
            ->toBeNull();
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pageHeaderTestRrmdir($root);
    }
});
