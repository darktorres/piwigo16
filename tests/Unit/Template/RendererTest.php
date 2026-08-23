<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\Event\GetPageAssets;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\HasHeadLinks;
use Piwigo\Core\HeadLink;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\View;
use Piwigo\Page\PageDataPayload;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * `Renderer::render()`'s own pre-population step (docs/PLAN.md's P42) --
 * applies a View's declared `HasPageAssets`/`ExposesPageData`/
 * `HasHeadLinks` data to `Template` before the View's own `.latte` file
 * runs, and relocates `Template::dispatchPageAssetsOnce()`'s plugin
 * dispatch here from `finalizeHtml()`'s former first line.
 *
 * Every test View below is an anonymous class -- `ViewTemplateTypeRoundTripTest`
 * scans every *named* class in the Composer classmap that implements
 * `View` and requires a matching `{templateType}` `.latte` file; an
 * anonymous class never enters that classmap, so these test doubles stay
 * outside its scope without needing a real fixture template per case.
 */
function rendererTestMakeTemplate(): Template
{
    $t = TemplateTestFactory::build();
    $tplDir = CurrentPathsTestFactory::get()->root . '/tpl/';
    if (! is_dir($tplDir)) {
        mkdir($tplDir, 0o777, true);
    }

    file_put_contents($tplDir . 'renderer-test.latte', 'ok');
    $t->setTemplateDir($tplDir);
    CurrentTemplateTestFactory::get()->set($t);

    return $t;
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-renderer-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root, 0o777, true);
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentUserTestFactory::get()->attachGlobals();
});

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
    if (is_dir($this->root)) {
        exec('rm -rf ' . escapeshellarg($this->root));
    }
});

test('render applies a HasPageAssets View\'s contributions before its own template runs', function (): void {
    $t = rendererTestMakeTemplate();
    $renderer = new Renderer(CurrentTemplateTestFactory::get());
    $view = new #[TemplateAttr('renderer-test.latte')] class implements View, HasPageAssets {
        #[\Override]
        public function pageAssets(): array
        {
            return [AssetContribution::css('view.css', version: false)];
        }
    };

    $renderer->render($view);

    $result = $t->finalizeHtml(Template::COMBINED_CSS_TAG);
    expect($result)
        ->toContain('href="view.css">');
});

test('render applies an ExposesPageData View\'s data/strings to PageState', function (): void {
    rendererTestMakeTemplate();
    $renderer = new Renderer(CurrentTemplateTestFactory::get());
    $view = new #[TemplateAttr('renderer-test.latte')] class implements View, ExposesPageData {
        #[\Override]
        public function exposedPageData(): array
        {
            return [
                'greeting' => 'hello',
            ];
        }

        #[\Override]
        public function exposedStrings(): array
        {
            return ['Loading'];
        }
    };

    $renderer->render($view);

    $lang = LangTestFactory::get();
    $lang->loadArray([
        'Loading' => 'Chargement',
    ]);
    $payload = new PageDataPayload(PageStateTestFactory::get(), $lang);
    expect($payload->toArray())
        ->toBe([
            'data' => [
                'greeting' => 'hello',
            ],
            'strings' => [
                'Loading' => 'Chargement',
            ],
        ]);
});

test('render applies a HasHeadLinks View\'s links via registerHeadLink', function (): void {
    $t = rendererTestMakeTemplate();
    $renderer = new Renderer(CurrentTemplateTestFactory::get());
    $view = new #[TemplateAttr('renderer-test.latte')] class implements View, HasHeadLinks {
        #[\Override]
        public function headLinks(): array
        {
            return [new HeadLink(rel: 'canonical', href: '/canonical.php')];
        }
    };

    $renderer->render($view);

    expect($t->htmlHeadElements)
        ->toBe(['<link rel="canonical" href="/canonical.php">']);
});

test('render is a no-op for a plain View implementing none of the three capability interfaces', function (): void {
    $t = rendererTestMakeTemplate();
    $renderer = new Renderer(CurrentTemplateTestFactory::get());
    $view = new #[TemplateAttr('renderer-test.latte')] class implements View {};

    $renderer->render($view);

    expect($t->htmlHeadElements)
        ->toBe([])
        ->and($t->finalizeHtml(Template::COMBINED_CSS_TAG))
        ->toBe('');
});

test('render dispatches GetPageAssets exactly once across two render() calls on the same Template', function (): void {
    rendererTestMakeTemplate();
    $renderer = new Renderer(CurrentTemplateTestFactory::get());
    $calls = 0;
    EventDispatcherTestFactory::get()->addTypedHandler(GetPageAssets::class, function (GetPageAssets $event) use (&$calls): GetPageAssets {
        $calls++;

        return $event;
    });
    $view = new #[TemplateAttr('renderer-test.latte')] class implements View {};

    $renderer->render($view);
    $renderer->render($view);

    expect($calls)
        ->toBe(1);
});

test('render resolves the current Template fresh on every call, safe against a mid-request Template swap', function (): void {
    rendererTestMakeTemplate();
    $renderer = new Renderer(CurrentTemplateTestFactory::get());
    $view = new #[TemplateAttr('renderer-test.latte')] class implements View, HasPageAssets {
        #[\Override]
        public function pageAssets(): array
        {
            return [AssetContribution::css('first.css', version: false)];
        }
    };
    $renderer->render($view);

    $swapped = rendererTestMakeTemplate();
    $swappedView = new #[TemplateAttr('renderer-test.latte')] class implements View, HasPageAssets {
        #[\Override]
        public function pageAssets(): array
        {
            return [AssetContribution::css('second.css', version: false)];
        }
    };
    $renderer->render($swappedView);

    $result = $swapped->finalizeHtml(Template::COMBINED_CSS_TAG);
    expect($result)
        ->toContain('href="second.css">');
});
