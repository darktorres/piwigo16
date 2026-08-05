<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\HtmlService;

final class ScriptLoader
{
    /**
     * @var array<string, Script>
     */
    private array $registered_scripts;

    /**
     * @var string[]
     */
    public $inline_scripts;

    private bool $did_head;

    /**
     * @var array<string, Script>
     */
    private array $head_done_scripts;

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
     * Singleton/service-locator elimination campaign, Phase 6: resolves
     * the container-shared UrlServiceInterface fresh on every call instead
     * of reading a bare-static value some bootstrap code set once (see
     * Image\SrcImage's own docblock for the full reasoning). Kept as a
     * container resolve rather than a constructor param for the same
     * reason as SrcImage/DerivativeImage -- consistent treatment across
     * the whole UrlService-bridge trio, not because a constructor param
     * would be unsafe here (this class's own single real production
     * construction site, Template.php, could receive one).
     */
    private static function urlService(): UrlServiceInterface
    {
        if (! \Piwigo\Core\Kernel::isBooted()) {
            throw new \RuntimeException('ScriptLoader: no URL service set (RequestBootstrap not run yet?)');
        }
        $urlService = \Piwigo\Core\Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new \RuntimeException('ScriptLoader: no URL service set (RequestBootstrap not run yet?)');
        }

        return $urlService;
    }

    final public function clear(): void
    {
        $this->registered_scripts = [];
        $this->inline_scripts = [];
        $this->head_done_scripts = [];
        $this->did_head = $this->did_footer = false;
    }

    public function did_head(): bool
    {
        return $this->did_head;
    }

    /**
     * @return array<string, Script>
     */
    public function get_all(): array
    {
        return $this->registered_scripts;
    }

    /**
     * @param string $code
     * @param string[] $require
     */
    public function add_inline($code, $require): void
    {
        ! (bool) $this->did_footer || trigger_error('Attempt to add inline script but the footer has been written', E_USER_WARNING);
        if ($require !== []) {
            foreach ($require as $id) {
                if (! isset($this->registered_scripts[$id])) {
                    $this->load_known_required_script($id, 1) or new HtmlService()
                        ->fatalError("inline script not found require {$id}");
                }
                $s = $this->registered_scripts[$id];
                if ($s->load_mode === 2) {
                    $s->load_mode = 1;
                } // until now the implementation does not allow executing inline script depending on another async script
            }
        }
        $this->inline_scripts[] = $code;
    }

    /**
     * @param string $id
     * @param int $load_mode
     * @param string[] $require
     * @param string|null $path null defers to fill_well_known()'s
     *   self::$known_paths lookup by $id — this method's own UI-core-dependency
     *   recursion below passes null deliberately
     * @param string|false $version false disables version-based cache
     *   busting, mirroring Script::__construct()'s own contract (this
     *   method just forwards $version straight into `new Script(...)`)
     */
    public function add($id, $load_mode, $require, $path, $version = '0', bool $is_template = false): void
    {
        if ($this->did_head && $load_mode === 0) {
            trigger_error("Attempt to add script {$id} but the head has been written", E_USER_WARNING);
        } elseif ((bool) $this->did_footer) {
            trigger_error("Attempt to add script {$id} but the footer has been written", E_USER_WARNING);
        }
        if (! isset($this->registered_scripts[$id])) {
            $script = new Script($load_mode, $id, $path, $version, $require);
            $script->is_template = $is_template;
            self::fill_well_known($id, $script);
            $this->registered_scripts[$id] = $script;

            // Load or modify all UI core files
            if ($id === 'jquery.ui' and $script->path === self::$known_paths['jquery.ui']) {
                foreach (self::$ui_core_dependencies as $script_id => $required_ids) {
                    $this->add($script_id, $load_mode, $required_ids, null, $version);
                }
            }

            // Try to load undefined required script
            foreach ($script->precedents as $script_id) {
                if (! isset($this->registered_scripts[$script_id])) {
                    $this->load_known_required_script($script_id, $load_mode);
                }
            }
        } else {
            $script = $this->registered_scripts[$id];
            if ((bool) count($require)) {
                $script->precedents = array_unique(array_merge($script->precedents, $require));
            }
            $script->set_path($path);
            if ((bool) $version && $script->version !== false && version_compare($script->version, $version) < 0) {
                $script->version = $version;
            }
            if ($load_mode < $script->load_mode) {
                $script->load_mode = $load_mode;
            }
        }
    }

    /**
     * Returns combined scripts loaded in header.
     *
     * @return Combinable[]
     */
    public function get_head_scripts(): array
    {
        self::check_load_dep($this->registered_scripts);
        foreach (array_keys($this->registered_scripts) as $id) {
            $this->compute_script_topological_order($id);
        }

        uasort($this->registered_scripts, self::cmp_by_mode_and_order(...));

        foreach ($this->registered_scripts as $id => $script) {
            if ($script->load_mode > 0) {
                break;
            }
            if (! in_array($script->path, [null, ''], true)) {
                $this->head_done_scripts[$id] = $script;
            } else {
                trigger_error("Script {$id} has an undefined path", E_USER_WARNING);
            }
        }
        $this->did_head = true;
        return self::do_combine($this->head_done_scripts, 0);
    }

    /**
     * Returns combined scripts loaded in footer.
     *
     * @return array{0: Combinable[], 1: Combinable[]}
     */
    public function get_footer_scripts(): array
    {
        if (! $this->did_head) {
            self::check_load_dep($this->registered_scripts);
        }
        $this->did_footer = true;
        $todo = [];
        foreach ($this->registered_scripts as $id => $script) {
            if (! isset($this->head_done_scripts[$id])) {
                $todo[$id] = $script;
            }
        }

        foreach (array_keys($todo) as $id) {
            $this->compute_script_topological_order($id);
        }

        uasort($todo, self::cmp_by_mode_and_order(...));

        $result = [[], []];
        foreach ($todo as $id => $script) {
            // load_mode 0 (head) scripts are handled by get_head_scripts();
            // only 1 (footer-sync) and 2 (footer-async) belong here.
            if ($script->load_mode > 0) {
                $result[$script->load_mode - 1][$id] = $script;
            }
        }
        return [self::do_combine($result[0], 1), self::do_combine($result[1], 2)];
    }

    /**
     * @param Script[] $scripts
     * @return Combinable[]
     */
    private static function do_combine(array $scripts, int $load_mode): array
    {
        $combiner = new FileCombiner(\Piwigo\Auth\AccessControl::currentForCaching(), 'js', self::urlService(), \Piwigo\Core\CurrentPaths::get(), \Piwigo\PluginConfig\EventDispatcher::get(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), $scripts);
        return $combiner->combine();
    }

    /**
     * Checks dependencies among Scripts.
     * Checks that if B depends on A, then B->load_mode >= A->load_mode in order to respect execution order.
     *
     * @param Script[] $scripts
     */
    private static function check_load_dep(array $scripts): void
    {
        do {
            $changed = false;
            foreach ($scripts as $id => $script) {
                $load = $script->load_mode;
                foreach ($script->precedents as $precedent) {
                    if (! isset($scripts[$precedent])) {
                        continue;
                    }
                    if ($scripts[$precedent]->load_mode > $load) {
                        $scripts[$precedent]->load_mode = $load;
                        $changed = true;
                    }
                    if ($load === 2 && $scripts[$precedent]->load_mode === 2 && ($scripts[$precedent]->is_remote(self::urlService()) or ! \Piwigo\Config\CurrentConfig::current()->templateCombineFiles())) {// we are async -> a predecessor cannot be async unlesss it can be merged; otherwise script execution order is not guaranteed
                        $scripts[$precedent]->load_mode = 1;
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
    private static function fill_well_known($id, Script $script): void
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
     * @param int $load_mode
     */
    private function load_known_required_script($id, $load_mode): bool
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
     *
     * @param string $script_id
     */
    private function compute_script_topological_order($script_id, int $recursion_limiter = 0): int
    {
        if (! isset($this->registered_scripts[$script_id])) {
            trigger_error("Undefined script {$script_id} is required by someone", E_USER_WARNING);
            return 0;
        }
        $recursion_limiter < 5 or new HtmlService()
            ->fatalError('combined script circular dependency');
        $script = $this->registered_scripts[$script_id];
        if (isset($script->extra['order'])) {
            return $script->extra['order'];
        }
        if (count($script->precedents) === 0) {
            return $script->extra['order'] = 0;
        }
        $max = 0;
        foreach ($script->precedents as $precedent) {
            $max = max($max, $this->compute_script_topological_order($precedent, $recursion_limiter + 1));
        }
        $max++;
        return $script->extra['order'] = $max;
    }

    /**
     * Callback for scripts sorter.
     */
    private static function cmp_by_mode_and_order(Script $s1, Script $s2): int
    {
        // both scripts always went through compute_script_topological_order()
        // in the loop right before the uasort() call that uses this
        // comparator (get_head_scripts()/get_footer_scripts()), which always
        // sets extra['order'] before returning
        assert(isset($s1->extra['order']) && isset($s2->extra['order']));

        $ret = intval($s1->load_mode) - intval($s2->load_mode);
        if ((bool) $ret) {
            return $ret;
        }

        $ret = $s1->extra['order'] - $s2->extra['order'];
        if ((bool) $ret) {
            return $ret;
        }

        if ($s1->extra['order'] === 0 and ($s1->is_remote(self::urlService()) xor $s2->is_remote(self::urlService()))) {
            return $s1->is_remote(self::urlService()) ? -1 : 1;
        }
        return strcmp($s1->id, $s2->id);
    }
}
