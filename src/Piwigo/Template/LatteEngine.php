<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Engine;
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
final class LatteEngine
{
    private readonly Engine $engine;

    public function __construct(string $cacheDirectory, bool $autoRefresh, PiwigoExtension $extension)
    {
        $this->engine = new Engine();
        $this->engine->setCacheDirectory($cacheDirectory);
        $this->engine->setAutoRefresh($autoRefresh);
        $this->engine->addExtension($extension);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function render(string $absolutePath, array $params): string
    {
        return $this->engine->renderToString($absolutePath, $params);
    }

    /**
     * `_data/templates_c/latte` -- the directory `Piwigo\Command\
     * CacheClearCommand` already assumes exists and purges (it predates
     * this class, written in anticipation of it). Caller (`Template`'s own
     * constructor) is responsible for `FilesystemHelper::mkgetdir()`-ing
     * this before constructing a `LatteEngine`, same as it already does for
     * Smarty's own compile dir.
     */
    public static function defaultCacheDir(string $root, string $dataLocation): string
    {
        return $root . $dataLocation . 'templates_c/latte';
    }
}
