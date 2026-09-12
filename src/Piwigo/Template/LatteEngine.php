<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Bridges\Tracy\TracyExtension;
use Latte\Engine;
use Latte\Feature;
use Piwigo\Core\Env;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Template\Latte\ThemeChainLoader;

/**
 * Thin wrapper around `Latte\Engine`, constructed once per owning `Template`
 * instance (see `Template::latteEngine()`) -- not a shared/static engine,
 * since `PiwigoExtension` holds a direct reference to its owning `Template`
 * (see that class's own docblock for why: avoids any dependency on whether
 * this `Template` is "the" registered current-request one, which matters for
 * throwaway instances like `MailService`'s).
 *
 * Uses a custom `Latte\Loader` (`ThemeChainLoader`) built from the SAME
 * `TemplateLocator` `Template::resolveLatteTemplatePath()` already resolves
 * the entry file through -- see that class's own docblock for the real bug
 * this fixes (P29.6 item 44): without it, only the *entry* `.latte` file
 * benefits from theme-chain-aware resolution; every `{layout}`/`{include}`
 * reference found *inside* an already-loaded template falls through to
 * Latte's own stock `Loaders\FileLoader`, which has no notion of a theme
 * chain at all. See docs/PLAN.md's P31 section, "Template-directory
 * resolution", for the surrounding `TemplateLocator`/`ThemeChain`
 * extraction this builds on.
 */
final readonly class LatteEngine
{
    private Engine $engine;

    /**
     * @param ?string $locale a `LangCode`-shaped (`ll_RR`) ICU locale, or
     *     null. Drives `|number`'s own locale-aware formatting (falls back
     *     to a plain `number_format()` call when null, matching this
     *     engine's pre-P33G behavior exactly -- see `Engine::setLocale()`).
     */
    public function __construct(string $cacheDirectory, bool $autoRefresh, PiwigoExtension $extension, ?string $locale, TemplateLocator $templateLocator, string $projectRoot)
    {
        $this->engine = new Engine();
        $this->engine->setCacheDirectory($cacheDirectory);
        $this->engine->setAutoRefresh($autoRefresh);
        $this->engine->addExtension($extension);
        $this->engine->setLoader(new ThemeChainLoader($templateLocator, $projectRoot));
        // P31's mechanical conversion left every paired-tag block's own
        // source indentation as literal output whitespace; the tree is
        // now consistently reformatted (see the two preceding commits),
        // so Dedent can strip it. ScopedLoopVariables stops {foreach}
        // loop variables leaking into the surrounding scope after
        // {/foreach} (a Smarty-era default this rewrite never relied on).
        $this->engine->setFeature(Feature::Dedent);
        $this->engine->setFeature(Feature::ScopedLoopVariables);
        $this->engine->setLocale($locale);
        // TracyExtension's own constructor calls Tracy\Debugger::getBar()
        // unconditionally -- only worth registering when Debugger::enable()
        // has actually run (see Piwigo\Bootstrap\TracyBootstrap's own
        // docblock), otherwise it's a panel nothing ever renders.
        if (Env::isTracyEnabled()) {
            $this->engine->addExtension(new TracyExtension());
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function render(string $absolutePath, array $params): string
    {
        return $this->engine->renderToString($absolutePath, $params);
    }

    public function warmupCache(string $absolutePath): void
    {
        $this->engine->warmupCache($absolutePath);
    }

    /**
     * `_data/templates_c/latte` -- the directory `Piwigo\Command\
     * CacheClearCommand` already assumes exists and purges (it predates
     * this class, written in anticipation of it). Caller (`Template::
     * latteEngine()`) is responsible for `FilesystemHelper::mkgetdir()`-ing
     * this before constructing a `LatteEngine`.
     */
    public static function defaultCacheDir(string $root, string $dataLocation): string
    {
        return $root . $dataLocation . 'templates_c/latte';
    }
}
