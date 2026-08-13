<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use LogicException;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Projection\FooterScripts;
use RuntimeException;

final class ScriptLoader
{
    /**
     * @var array<string, Script>
     */
    private array $registeredScripts;

    /**
     * @var string[]
     */
    public array $inlineScripts;

    private bool $didHead;

    /**
     * @var array<string, Script>
     */
    private array $headDoneScripts;

    private ?bool $did_footer = null;

    /**
     * @var array<string, string>
     */
    private static array $known_paths = [
        'core.scripts' => 'themes/default/js/scripts.js',
        'jquery' => 'themes/default/js/jquery.min.js',
        'jquery.ui' => 'themes/default/js/ui/minified/jquery.ui.core.min.js',
        'jquery.ui.effect' => 'themes/default/js/ui/minified/jquery.ui.effect.min.js',
    ];

    /**
     * @var array<string, string[]>
     */
    private static array $ui_core_dependencies = [
        'jquery.ui.widget' => ['jquery'],
        'jquery.ui.position' => ['jquery'],
        'jquery.ui.mouse' => ['jquery', 'jquery.ui', 'jquery.ui.widget'],
    ];

    public function __construct()
    {
        $this->clear();
    }

    /**
     * Container resolve, not a constructor property -- this class has
     * hundreds of real `new ScriptLoader()` construction sites (built
     * fresh inside Template::__construct() itself), so a required param
     * here would ripple across all of them for 2 defensive
     * fatalError()-only error-path call sites. `HtmlRenderingInterface` is
     * already bound in container.php, and this file is already on
     * StructuralTest.php's own `Kernel::container()` allow-list for its
     * other remaining shim reads.
     */
    private function htmlRenderer(): HtmlRenderingInterface
    {
        $htmlRenderer = Kernel::container()->get(HtmlRenderingInterface::class);
        if (! $htmlRenderer instanceof HtmlRenderingInterface) {
            throw new LogicException('Container returned an unexpected type for ' . HtmlRenderingInterface::class);
        }

        return $htmlRenderer;
    }

    /**
     * Resolves the container-shared UrlServiceInterface fresh on every
     * call (see Image\SrcImage's own docblock for the full reasoning).
     * Kept as a container resolve rather than a constructor param for the
     * same reason as SrcImage/DerivativeImage -- consistent treatment
     * across the whole UrlService-bridge trio, not because a constructor
     * param would be unsafe here (this class's own single real production
     * construction site, Template.php, could receive one).
     */
    private static function urlService(): UrlServiceInterface
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('ScriptLoader: no URL service set (RequestBootstrap not run yet?)');
        }
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new RuntimeException('ScriptLoader: no URL service set (RequestBootstrap not run yet?)');
        }

        return $urlService;
    }

    /**
     * Same "no DI, genuinely static factory API" shape as urlService()
     * above -- doCombine()/checkLoadDep() are both `private static`,
     * no `$this` to inject through.
     */
    private static function eventDispatcher(): EventDispatcher
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('ScriptLoader: no EventDispatcher set (RequestBootstrap not run yet?)');
        }
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new RuntimeException('ScriptLoader: no EventDispatcher set (RequestBootstrap not run yet?)');
        }

        return $eventDispatcher;
    }

    /**
     * Same reasoning as eventDispatcher() above.
     */
    private static function currentTemplate(): CurrentTemplate
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('ScriptLoader: no CurrentTemplate set (RequestBootstrap not run yet?)');
        }
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        if (! $currentTemplate instanceof CurrentTemplate) {
            throw new RuntimeException('ScriptLoader: no CurrentTemplate set (RequestBootstrap not run yet?)');
        }

        return $currentTemplate;
    }

    /**
     * Same reasoning as eventDispatcher() above.
     */
    private static function currentConfig(): CurrentConfig
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('ScriptLoader: no CurrentConfig set (RequestBootstrap not run yet?)');
        }
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new RuntimeException('ScriptLoader: no CurrentConfig set (RequestBootstrap not run yet?)');
        }

        return $currentConfig;
    }

    /**
     * Same reasoning as currentConfig() above.
     */
    private static function paths(): Paths
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('ScriptLoader: no Paths set (RequestBootstrap not run yet?)');
        }
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new RuntimeException('ScriptLoader: no Paths set (RequestBootstrap not run yet?)');
        }

        return $paths;
    }

    final public function clear(): void
    {
        $this->registeredScripts = [];
        $this->inlineScripts = [];
        $this->headDoneScripts = [];
        $this->didHead = $this->did_footer = false;
    }

    public function didHead(): bool
    {
        return $this->didHead;
    }

    /**
     * @return array<string, Script>
     */
    public function getAll(): array
    {
        return $this->registeredScripts;
    }

    /**
     * @param string[] $require
     */
    public function addInline(string $code, array $require): void
    {
        ! (bool) $this->did_footer || trigger_error('Attempt to add inline script but the footer has been written', E_USER_WARNING);
        if ($require !== []) {
            foreach ($require as $id) {
                if (! isset($this->registeredScripts[$id])) {
                    $this->loadKnownRequiredScript($id, 1) or $this->htmlRenderer()
                        ->fatalError("inline script not found require {$id}");
                }
                $s = $this->registeredScripts[$id];
                if ($s->loadMode === 2) {
                    $s->loadMode = 1;
                } // until now the implementation does not allow executing inline script depending on another async script
            }
        }
        $this->inlineScripts[] = $code;
    }

    /**
     * @param string[] $require
     * @param string|null $path null defers to fillWellKnown()'s
     *   self::$known_paths lookup by $id — this method's own UI-core-dependency
     *   recursion below passes null deliberately
     * @param string|false $version false disables version-based cache
     *   busting, mirroring Script::__construct()'s own contract (this
     *   method just forwards $version straight into `new Script(...)`)
     */
    public function add(string $id, int $load_mode, array $require, ?string $path, string|false $version = '0', bool $is_template = false): void
    {
        if ($this->didHead && $load_mode === 0) {
            trigger_error("Attempt to add script {$id} but the head has been written", E_USER_WARNING);
        } elseif ((bool) $this->did_footer) {
            trigger_error("Attempt to add script {$id} but the footer has been written", E_USER_WARNING);
        }
        if (! isset($this->registeredScripts[$id])) {
            $script = new Script($load_mode, $id, $path, $version, $require);
            $script->is_template = $is_template;
            self::fillWellKnown($id, $script);
            $this->registeredScripts[$id] = $script;

            // Load or modify all UI core files
            if ($id === 'jquery.ui' and $script->path === self::$known_paths['jquery.ui']) {
                foreach (self::$ui_core_dependencies as $script_id => $required_ids) {
                    $this->add($script_id, $load_mode, $required_ids, null, $version);
                }
            }

            // Try to load undefined required script
            foreach ($script->precedents as $script_id) {
                if (! isset($this->registeredScripts[$script_id])) {
                    $this->loadKnownRequiredScript($script_id, $load_mode);
                }
            }
        } else {
            $script = $this->registeredScripts[$id];
            if ((bool) count($require)) {
                $script->precedents = array_unique(array_merge($script->precedents, $require));
            }
            $script->setPath($path);
            if ((bool) $version && $script->version !== false && version_compare($script->version, $version) < 0) {
                $script->version = $version;
            }
            if ($load_mode < $script->loadMode) {
                $script->loadMode = $load_mode;
            }
        }
    }

    /**
     * Returns combined scripts loaded in header.
     *
     * @return Combinable[]
     */
    public function getHeadScripts(AccessLevelChecker $accessLevelChecker): array
    {
        self::checkLoadDep($this->registeredScripts);
        foreach (array_keys($this->registeredScripts) as $id) {
            $this->computeScriptTopologicalOrder($id);
        }

        uasort($this->registeredScripts, self::cmpByModeAndOrder(...));

        foreach ($this->registeredScripts as $id => $script) {
            if ($script->loadMode > 0) {
                break;
            }
            if (! in_array($script->path, [null, ''], true)) {
                $this->headDoneScripts[$id] = $script;
            } else {
                trigger_error("Script {$id} has an undefined path", E_USER_WARNING);
            }
        }
        $this->didHead = true;
        return $this->doCombine($this->headDoneScripts, 0, $accessLevelChecker);
    }

    /**
     * Returns combined scripts loaded in footer.
     */
    public function getFooterScripts(AccessLevelChecker $accessLevelChecker): FooterScripts
    {
        if (! $this->didHead) {
            self::checkLoadDep($this->registeredScripts);
        }
        $this->did_footer = true;
        $todo = [];
        foreach ($this->registeredScripts as $id => $script) {
            if (! isset($this->headDoneScripts[$id])) {
                $todo[$id] = $script;
            }
        }

        foreach (array_keys($todo) as $id) {
            $this->computeScriptTopologicalOrder($id);
        }

        uasort($todo, self::cmpByModeAndOrder(...));

        $result = [[], []];
        foreach ($todo as $id => $script) {
            // load_mode 0 (head) scripts are handled by getHeadScripts();
            // only 1 (footer-sync) and 2 (footer-async) belong here.
            if ($script->loadMode > 0) {
                $result[$script->loadMode - 1][$id] = $script;
            }
        }
        return new FooterScripts($this->doCombine($result[0], 1, $accessLevelChecker), $this->doCombine($result[1], 2, $accessLevelChecker));
    }

    /**
     * @param Script[] $scripts
     * @return Combinable[]
     */
    private function doCombine(array $scripts, int $load_mode, AccessLevelChecker $accessLevelChecker): array
    {
        $combiner = new FileCombiner($accessLevelChecker, 'js', self::urlService(), self::paths(), self::eventDispatcher(), self::currentTemplate(), self::currentConfig(), $scripts);
        return $combiner->combine();
    }

    /**
     * Checks dependencies among Scripts.
     * Checks that if B depends on A, then B->loadMode >= A->loadMode in order to respect execution order.
     *
     * @param Script[] $scripts
     */
    private static function checkLoadDep(array $scripts): void
    {
        do {
            $changed = false;
            foreach ($scripts as $id => $script) {
                $load = $script->loadMode;
                foreach ($script->precedents as $precedent) {
                    if (! isset($scripts[$precedent])) {
                        continue;
                    }
                    if ($scripts[$precedent]->loadMode > $load) {
                        $scripts[$precedent]->loadMode = $load;
                        $changed = true;
                    }
                    if ($load === 2 && $scripts[$precedent]->loadMode === 2 && ($scripts[$precedent]->isRemote(self::urlService()) or ! self::currentConfig()->templateCombineFiles)) {// we are async -> a predecessor cannot be async unlesss it can be merged; otherwise script execution order is not guaranteed
                        $scripts[$precedent]->loadMode = 1;
                        $changed = true;
                    }
                }
            }
        } while ($changed);
    }

    /**
     * Fill a script dependancies with the known jQuery UI scripts.
     *
     * @param string $id in self::$known_paths
     */
    private static function fillWellKnown(string $id, Script $script): void
    {
        if (in_array($script->path, [null, ''], true) && isset(self::$known_paths[$id])) {
            $script->path = self::$known_paths[$id];
        }
        if (str_starts_with($id, 'jquery.')) {
            $required_ids = ['jquery'];

            if (str_starts_with($id, 'jquery.ui.effect-')) {
                $required_ids = ['jquery', 'jquery.ui.effect'];

                if (in_array($script->path, [null, ''], true)) {
                    $script->path = dirname(self::$known_paths['jquery.ui.effect']) . "/{$id}.min.js";
                }
            } elseif (str_starts_with($id, 'jquery.ui.')) {
                if (! isset(self::$ui_core_dependencies[$id])) {
                    $required_ids = array_merge(['jquery', 'jquery.ui'], array_keys(self::$ui_core_dependencies));
                }

                if (in_array($script->path, [null, ''], true)) {
                    $script->path = dirname(self::$known_paths['jquery.ui']) . "/{$id}.min.js";
                }
            }

            foreach ($required_ids as $required_id) {
                if (! in_array($required_id, $script->precedents, true)) {
                    $script->precedents[] = $required_id;
                }
            }
        }
    }

    /**
     * Add a known jQuery UI script to loaded scripts.
     *
     * @param string $id in self::$known_paths
     */
    private function loadKnownRequiredScript(string $id, int $load_mode): bool
    {
        if (isset(self::$known_paths[$id]) or str_starts_with($id, 'jquery.ui.')) {
            $this->add($id, $load_mode, [], null);
            return true;
        }
        return false;
    }

    /**
     * Compute script order depending on dependencies.
     * Assigned to $script->extra['order'].
     */
    private function computeScriptTopologicalOrder(string $script_id, int $recursion_limiter = 0): int
    {
        if (! isset($this->registeredScripts[$script_id])) {
            trigger_error("Undefined script {$script_id} is required by someone", E_USER_WARNING);
            return 0;
        }
        $recursion_limiter < 5 or $this->htmlRenderer()
            ->fatalError('combined script circular dependency');
        $script = $this->registeredScripts[$script_id];
        if (isset($script->extra['order'])) {
            return $script->extra['order'];
        }
        if (count($script->precedents) === 0) {
            return $script->extra['order'] = 0;
        }
        $max = 0;
        foreach ($script->precedents as $precedent) {
            $max = max($max, $this->computeScriptTopologicalOrder($precedent, $recursion_limiter + 1));
        }
        $max++;
        return $script->extra['order'] = $max;
    }

    /**
     * Callback for scripts sorter.
     */
    private static function cmpByModeAndOrder(Script $s1, Script $s2): int
    {
        // both scripts always went through computeScriptTopologicalOrder()
        // in the loop right before the uasort() call that uses this
        // comparator (getHeadScripts()/getFooterScripts()), which always
        // sets extra['order'] before returning
        assert(isset($s1->extra['order']) && isset($s2->extra['order']));

        $ret = intval($s1->loadMode) - intval($s2->loadMode);
        if ((bool) $ret) {
            return $ret;
        }

        $ret = $s1->extra['order'] - $s2->extra['order'];
        if ((bool) $ret) {
            return $ret;
        }

        if ($s1->extra['order'] === 0 and ($s1->isRemote(self::urlService()) xor $s2->isRemote(self::urlService()))) {
            return $s1->isRemote(self::urlService()) ? -1 : 1;
        }
        return strcmp($s1->id, $s2->id);
    }
}
