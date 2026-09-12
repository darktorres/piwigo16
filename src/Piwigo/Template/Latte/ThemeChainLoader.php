<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Loader;
use Latte\Loaders\FileLoader;
use Override;
use Piwigo\Template\TemplateLocator;

/**
 * Real bug found by P29.6 item 44's own live verification, not a
 * theoretical gap: `TemplateLocator::resolve()` already walks a
 * `Template` instance's own theme directory chain (child theme first,
 * falling back to its parent) for the *entry* `.latte` file
 * (`Template::resolveLatteTemplatePath()`), giving a child theme's
 * same-named file priority -- but every `{layout}`/`{include}` reference
 * found *inside* an already-loaded template is resolved by Latte's own
 * stock `Loaders\FileLoader` instead, whose `getReferredName()` treats a
 * bare name as relative to *whichever file textually contains that
 * reference*, with no theme-chain awareness at all.
 *
 * This silently defeats a real, shipped override: `themes/modus/template/
 * index.latte` jumps via an explicit absolute path into `themes/default/
 * template/index.latte` to inherit its real body markup (the only way to
 * reach a same-named parent file without infinitely re-matching itself,
 * confirmed by this whole campaign's own Phase 0 verification spike) --
 * but *that* file's own `{layout 'layout.latte'}` is a bare reference,
 * so it resolves to `themes/default/template/layout.latte` always,
 * regardless of which theme was actually active or who jumped in to
 * reach it. `themes/modus/template/layout.latte` -- the file holding
 * modus's entire `$MODUS_DISPLAY_PAGE_BANNER` gate (P29.6 Phase 6, item
 * 27) -- was never compiled or used for any real page, confirmed
 * directly by inspecting `_data/templates_c/latte/`: only a
 * `default-template-layout.latte--*.php` ever existed, never a
 * `modus-`prefixed one.
 *
 * This loader closes that gap generally (every theme, every chain
 * depth), not just for modus's own case: any bare reference is resolved
 * through the *same* `TemplateLocator` the owning `Template` instance
 * already built for its entry file, so a child theme's own same-named
 * override is found first no matter which file in the chain contains
 * the reference. An absolute path (Latte's own `#/|\\|[a-z]:|phar:#iA`
 * regex, matched identically to `Loaders\FileLoader::getReferredName()`)
 * always passes through unchanged first -- this is the *only* mechanism
 * by which a theme can deliberately reach into a specific different
 * theme's file (as `index.latte`'s own jump above does), and it must
 * keep meaning exactly that, not become theme-chain-searchable itself.
 * A bare name the chain doesn't resolve (nothing outside the gallery/
 * admin/mail three theme families this engine ever serves has been
 * found to hit this) falls back to stock `FileLoader` behavior, so no
 * previously-working reference can regress into a hard failure.
 */
final readonly class ThemeChainLoader implements Loader
{
    private FileLoader $fallback;

    public function __construct(
        private TemplateLocator $templateLocator,
        private string $projectRoot,
    ) {
        $this->fallback = new FileLoader();
    }

    #[Override]
    public function getContent(string $name): string
    {
        return $this->fallback->getContent($name);
    }

    #[Override]
    public function getReferredName(string $name, string $referringName): string
    {
        if (preg_match('#/|\\\\|[a-z]:|phar:#iA', $name) === 1) {
            return $name;
        }

        return $this->templateLocator->resolve($name, $this->projectRoot)
            ?? $this->fallback->getReferredName($name, $referringName);
    }

    #[Override]
    public function getUniqueId(string $name): string
    {
        return $this->fallback->getUniqueId($name);
    }
}
