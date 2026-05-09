<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Engine;
use Latte\Feature;
use Piwigo\Config\Config;
use Piwigo\Core\Util;
use Piwigo\Template\Latte\PiwigoExtension;

/**
 * Latte template engine, paired with Smarty during the §1.2 Wave 2
 * conversion window. The dispatcher in TemplateRegistry routes `.latte`
 * files here; `.tpl` files continue through Template.php (Smarty).
 *
 * Strict types are enabled so accidental nullables surface at compile-time
 * rather than printing "Notice: Trying to access array offset on null" into
 * the rendered page.
 */
final class LatteEngine implements TemplateEngine
{
    private readonly Engine $engine;

    /** @var array<string, mixed> */
    private array $assigns = [];

    public function __construct(string $cacheDirectory)
    {
        $this->engine = new Engine();
        $this->engine->setFeature(Feature::StrictTypes);
        $this->engine->setCacheDirectory($cacheDirectory);
        $this->engine->addExtension(new PiwigoExtension());
    }

    /**
     * Build a Latte engine wired to Piwigo's runtime cache directory —
     * the same _data/templates_c/ root Smarty uses, with `latte/` as
     * the Latte-side subdirectory so the two engines don't collide on
     * filenames during the migration window.
     *
     * Phase B.5 dispatcher entry point: callers that want to render a
     * `.latte` file from anywhere in the codebase can do
     * `LatteEngine::default()->render($path, $params)` without
     * threading the cache-directory configuration through.
     */
    public static function default(): self
    {
        $cacheDir = PHPWG_ROOT_PATH . Config::dataLocation() . 'templates_c/latte';
        Util::mkgetdir($cacheDir);

        return new self($cacheDir);
    }

    #[\Override]
    public function assign(string $name, mixed $value): void
    {
        $this->assigns[$name] = $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    #[\Override]
    public function render(string $template, array $params = []): string
    {
        return $this->engine->renderToString($template, [...$this->assigns, ...$params]);
    }

    /**
     * Render an inline template source — used by tests and rare runtime
     * call-sites that don't have a file path. The default StringLoader
     * (constructed with no map) treats its `$name` argument as the source.
     *
     * @param array<string, mixed> $params
     */
    public function renderFromString(string $source, array $params = []): string
    {
        $this->engine->setLoader(new \Latte\Loaders\StringLoader());
        return $this->engine->renderToString($source, [...$this->assigns, ...$params]);
    }
}
