<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Runtime\Html;
use LogicException;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\HasHeadLinks;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;
use ReflectionClass;

/**
 * `render(View): Html` -- resolves the view's `#[Template]` attribute to
 * a `.latte` file and renders it via `Template::renderView()`. No
 * reflection-lookup cache: `ReflectionClass::getAttributes()` is a pure
 * introspection call over already-loaded class metadata (no I/O), called
 * at most a handful of times per request -- not worth a mutable cache on
 * this `readonly` class.
 *
 * `render()`'s own pre-population step (docs/PLAN.md's P42) applies a
 * View's declared `HasPageAssets`/`ExposesPageData`/`HasHeadLinks` data to
 * `$template` *before* the View's own `.latte` file runs -- the
 * declarative replacement for `{do combineCss(...)}`/`{do
 * combineScript(...)}`/`{do footerScript(...)}`/`{do exposeData(...)}`/
 * `{do exposeString(...)}`/`{do htmlHead(...)}`. This fires on every
 * `render()` call, top-level or nested/partial alike, so migrating one
 * View at a time is safe: a declared View's data registers before its own
 * template executes regardless of how many not-yet-migrated imperative
 * calls coexist elsewhere on the same page (`PageAssets::add()`/
 * `Template::exposeData()`/`exposeString()` are dedup-safe regardless of
 * call order).
 *
 * `Template::dispatchPageAssetsOnce()` (the one-shot-guarded dispatch of
 * `Asset\Event\GetPageAssets`, letting *plugins* contribute assets) also
 * moved here from `Template::finalizeHtml()`'s own former first line --
 * this method is called for every real page and partial, so the first
 * call for a given request naturally becomes the dispatch point, before
 * that first View's own declared data is applied, preserving the
 * "dispatched once per request, before anything else reads `PageAssets`"
 * contract exactly. Resolving `$template` fresh via `$this->
 * currentTemplate->get()` on every call (not caching it) also means this
 * is safe against the one real mid-request `Template`-swap case
 * (`Page\NoPhotoYetRenderer`, which constructs a brand-new `Template` and
 * `CurrentTemplate::set()`s it before its own `render()` call) -- the
 * dispatch correctly fires on whichever `Template` is current at call
 * time.
 *
 * `Template::resolveLocalHeadOnce()` (the theme-base "local-head
 * resolver" piece) is the same one-shot shape, right after the plugin
 * dispatch above -- both resolve theme/plugin-scoped content once,
 * before the first page-specific View's own data is applied.
 */
final readonly class Renderer
{
    public function __construct(
        private CurrentTemplate $currentTemplate
    ) {}

    public function render(View $view): Html
    {
        $template = $this->currentTemplate->get();
        $template->dispatchPageAssetsOnce();
        $template->resolveLocalHeadOnce();

        if ($view instanceof HasPageAssets) {
            $template->registerPageAssets($view->pageAssets());
        }

        if ($view instanceof ExposesPageData) {
            foreach ($view->exposedPageData() as $key => $value) {
                $template->exposeData($key, $value);
            }

            foreach ($view->exposedStrings() as $translationKey) {
                $template->exposeString($translationKey);
            }
        }

        if ($view instanceof HasHeadLinks) {
            foreach ($view->headLinks() as $headLink) {
                $template->registerHeadLink($headLink);
            }
        }

        $file = self::resolveTemplateFile($view);

        return new Html($template->renderView($file, $view));
    }

    private static function resolveTemplateFile(View $view): string
    {
        $attributes = new ReflectionClass($view)
            ->getAttributes(TemplateAttr::class);
        if ($attributes === []) {
            throw new LogicException($view::class . ' has no #[Template] attribute');
        }

        return $attributes[0]->newInstance()->file;
    }
}
