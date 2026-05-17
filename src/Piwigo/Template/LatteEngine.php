<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Engine;
use Latte\Feature;
use Latte\Loaders\StringLoader;
use Latte\Policy;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Template\Latte\PiwigoPolicy;

/**
 * Latte template engine — the only engine after Phase F dropped
 * `smarty/smarty`. `Template::parse()` resolves bare filenames against
 * the registered template directories and renders through here; direct
 * callers can also obtain the engine via {@see self::default()}.
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

    public function __construct(string $cacheDirectory, ?Policy $policy = null)
    {
        $this->engine = new Engine();
        $this->engine->setFeature(Feature::StrictTypes);
        $this->engine->setCacheDirectory($cacheDirectory);
        $this->engine->addExtension(new PiwigoExtension());
        if ($policy !== null) {
            $this->engine->setPolicy($policy);
            $this->engine->setSandboxMode();
        }
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
        $cacheDir = (Kernel::isBooted() ? Kernel::service(Paths::class)->root : PHPWG_ROOT_PATH) . Config::dataLocation() . 'templates_c/latte';
        Filesystem::mkgetdir($cacheDir);
        self::ensureGroupWritable($cacheDir);

        return new self($cacheDir);
    }

    /**
     * Sandboxed engine for plugin-supplied templates. Renders under
     * {@see PiwigoPolicy::createPluginPolicy()} — default-deny, allowing
     * structural tags, escape filters, the translation pair, and a small
     * set of read-only Piwigo helpers. Plugin templates that try to call
     * `{php}`, `{include}`, the asset-pipeline functions, or the
     * filesystem-touching filters fail at compile time.
     *
     * The cache directory is segregated from the trusted-engine cache so
     * a malicious plugin can't poison the core compile cache.
     */
    public static function sandboxed(): self
    {
        $cacheDir = (Kernel::isBooted() ? Kernel::service(Paths::class)->root : PHPWG_ROOT_PATH) . Config::dataLocation() . 'templates_c/latte_plugin';
        Filesystem::mkgetdir($cacheDir);
        self::ensureGroupWritable($cacheDir);

        return new self($cacheDir, PiwigoPolicy::createPluginPolicy());
    }

    /**
     * Make `$cacheDir` group-writable so it can be shared between Apache
     * (typically `www-data`) and CLI (the developer's user) without one
     * locking the other out. The parent `_data/templates_c/` typically
     * carries an ACL granting both, but the per-engine subdir is created
     * with `Config::chmodValue()` (0o755 by default), and the resulting
     * mask clamps the ACL effective bits to read+exec — neither user can
     * write through the other's create. Forcing 0o775 unclamps the mask.
     */
    private static function ensureGroupWritable(string $dir): void
    {
        $perms = fileperms($dir);
        if ($perms !== false && ($perms & 0o775) !== 0o775) {
            chmod($dir, ($perms & 0o7000) | 0o775);
        }
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
        $this->engine->setLoader(new StringLoader());
        return $this->engine->renderToString($source, [...$this->assigns, ...$params]);
    }

    /**
     * Compile `$name` and write the cached PHP to the configured cache
     * directory without rendering. Used by the deploy-time warmer at
     * `tools/precompile_templates.php` so the runtime first-request hit
     * does not pay the compile cost. Latte's compile cache keys on
     * `$name` as passed; the warmer must use the same path shape
     * `Template::resolveLatteTemplatePath()` produces at runtime
     * (absolute paths under the default FileLoader).
     */
    public function warmupCache(string $name): void
    {
        $this->engine->warmupCache($name);
    }
}
