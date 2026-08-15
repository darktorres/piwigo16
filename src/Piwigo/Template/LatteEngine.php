<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Engine;
use Latte\Feature;
use Piwigo\Template\Latte\PiwigoExtension;

/**
 * Thin wrapper around `Latte\Engine`, constructed once per owning `Template`
 * instance (see `Template::latteEngine()`) -- not a shared/static engine,
 * since `PiwigoExtension` holds a direct reference to its owning `Template`
 * (see that class's own docblock for why: avoids any dependency on whether
 * this `Template` is "the" registered current-request one, which matters for
 * throwaway instances like `MailService`'s).
 *
 * No custom `Latte\Loader` -- `Template::resolveLatteTemplatePath()` resolves
 * bare filenames to a real, absolute filesystem path before ever calling
 * into this class, and Latte's own default `FileLoader` (constructed with no
 * base dir) accepts an absolute path directly. See docs/PLAN.md's P31
 * section, "Template-directory resolution".
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
    public function __construct(string $cacheDirectory, bool $autoRefresh, PiwigoExtension $extension, ?string $locale)
    {
        $this->engine = new Engine();
        $this->engine->setCacheDirectory($cacheDirectory);
        $this->engine->setAutoRefresh($autoRefresh);
        $this->engine->addExtension($extension);
        // P31's mechanical conversion left every paired-tag block's own
        // source indentation as literal output whitespace; the tree is
        // now consistently reformatted (see the two preceding commits),
        // so Dedent can strip it. ScopedLoopVariables stops {foreach}
        // loop variables leaking into the surrounding scope after
        // {/foreach} (a Smarty-era default this rewrite never relied on).
        $this->engine->setFeature(Feature::Dedent);
        $this->engine->setFeature(Feature::ScopedLoopVariables);
        $this->engine->setLocale($locale);
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
