<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Config\Config;

/**
 * Manage a list of required scripts for a page, by optimizing their loading location (head, footer, async)
 * and later on by combining them in a unique file respecting at the same time dependencies.
 */
class ScriptLoader
{
    /** @var Script[] */
    private array $registered_scripts;
    /** @var string[] */
    public $inline_scripts;

    private bool $did_head = false;
    /** @var array */
    /** @var array<string, Script> */
    private array $head_done_scripts = [];
    private ?bool $did_footer = null;

    /** @var array<string, string> */
    private static array $known_paths = [
        'core.scripts' => 'themes/_base/js/scripts.js',
      ];

    public function __construct()
    {
        $this->clear();
    }

    public function clear(): void
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
     * @return Script[]
     */
    public function get_all(): array
    {
        return $this->registered_scripts;
    }

    /**
     * @param string[] $require
     */
    /** @param string[] $require */
    public function add_inline(string $code, array $require): void
    {
        !$this->did_footer || trigger_error('Attempt to add inline script but the footer has been written', E_USER_WARNING);
        if (!empty($require)) {
            foreach ($require as $id) {
                if (!isset($this->registered_scripts[$id])) {
                    $this->load_known_required_script($id, 1) or fatal_error("inline script not found require $id");
                }
                $s = $this->registered_scripts[$id];
                if ($s->load_mode == 2) {
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
     * @param string|null $path
     * @param string|int $version
     */
    /** @param string[] $require */
    public function add(string $id, int|string $load_mode, array $require, ?string $path, string|int $version = 0, bool $is_template = false): void
    {
        if ($this->did_head && $load_mode == 0) {
            trigger_error("Attempt to add script $id but the head has been written", E_USER_WARNING);
        } elseif ($this->did_footer) {
            trigger_error("Attempt to add script $id but the footer has been written", E_USER_WARNING);
        }
        if (! isset($this->registered_scripts[$id])) {
            if ($manifest = self::manifest()) {
                $entry = $manifest[$id] ?? null;
                if (is_array($entry) && is_string($entry['file'] ?? null)) {
                    $path = 'dist/' . $entry['file'];
                    // Do NOT clear $require — required scripts (legacy plugins and other Vite
                    // entries) are not encoded in manifest chunks; they still load separately.
                }
            }
            $script = new Script((int) $load_mode, $id, $path, $version, $require);
            $script->is_template = $is_template;
            self::fill_well_known($id, $script);
            $this->registered_scripts[$id] = $script;

            // Try to load undefined required script
            foreach ($script->precedents as $script_id) {
                if (! isset($this->registered_scripts[$script_id])) {
                    $this->load_known_required_script($script_id, (int) $load_mode);
                }
            }
        } else {
            // Re-registration: resolve manifest path so a second combine_script call with
            // a legacy path does not overwrite the already-resolved dist/ path.
            if ($manifest = self::manifest()) {
                $entry = $manifest[$id] ?? null;
                if (is_array($entry) && is_string($entry['file'] ?? null)) {
                    $path = 'dist/' . $entry['file'];
                }
            }
            $script = $this->registered_scripts[$id];
            if (count($require)) {
                $script->precedents = array_unique(array_merge($script->precedents, $require));
            }
            $script->set_path($path);
            if ($version && version_compare((string) $script->version, (string) $version) < 0) {
                $script->version = $version;
            }
            if ($load_mode < $script->load_mode) {
                $script->load_mode = (int) $load_mode;
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
            if (!empty($script->path)) {
                $this->head_done_scripts[$id] = $script;
            } else {
                trigger_error("Script $id has an undefined path", E_USER_WARNING);
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
        if (!$this->did_head) {
            self::check_load_dep($this->registered_scripts);
        }
        $this->did_footer = true;
        $todo = [];
        foreach ($this->registered_scripts as $id => $script) {
            if (!isset($this->head_done_scripts[$id])) {
                $todo[$id] = $script;
            }
        }

        foreach (array_keys($todo) as $id) {
            $this->compute_script_topological_order($id);
        }

        uasort($todo, self::cmp_by_mode_and_order(...));

        $result = [ [], [] ];
        foreach ($todo as $id => $script) {
            $result[$script->load_mode - 1][$id] = $script;
        }
        return [ self::do_combine($result[0], 1), self::do_combine($result[1], 2) ];
    }

    /**
     * @param Script[] $scripts
     * @return Combinable[]
     */
    private static function do_combine(array $scripts, int $load_mode): array
    {
        $combiner = new FileCombiner('js', $scripts);
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
                    if (!isset($scripts[$precedent])) {
                        continue;
                    }
                    if ($scripts[$precedent]->load_mode > $load) {
                        $scripts[$precedent]->load_mode = $load;
                        $changed = true;
                    }
                    if ($load == 2 && $scripts[$precedent]->load_mode == 2 && ($scripts[$precedent]->is_remote() or !Config::templateCombineFiles())) {// we are async -> a predecessor cannot be async unlesss it can be merged; otherwise script execution order is not guaranteed
                        $scripts[$precedent]->load_mode = 1;
                        $changed = true;
                    }
                }
            }
        } while ($changed);
    }

    /**
     * Fill a script path from known_paths if not already set.
     *
     * @param string $id in FileCombiner::$known_paths
     */
    private static function fill_well_known(string $id, Script $script): void
    {
        if (empty($script->path) && isset(self::$known_paths[$id])) {
            $script->path = self::$known_paths[$id];
        }
    }

    /**
     * Add a known script to loaded scripts if it appears in known_paths.
     *
     * @param string $id in FileCombiner::$known_paths
     */
    private function load_known_required_script($id, int $load_mode): bool
    {
        if (isset(self::$known_paths[$id])) {
            $this->add($id, $load_mode, [], null);
            return true;
        }
        return false;
    }

    /**
     * Compute script order depending on dependencies.
     * Assigned to $script->extra['order'].
     */
    private function compute_script_topological_order(string $script_id, int $recursion_limiter = 0): int
    {
        if (!isset($this->registered_scripts[$script_id])) {
            trigger_error("Undefined script $script_id is required by someone", E_USER_WARNING);
            return 0;
        }
        $recursion_limiter < 5 or fatal_error('combined script circular dependency');
        $script = $this->registered_scripts[$script_id];
        if (isset($script->extra['order'])) {
            return is_int($script->extra['order']) ? $script->extra['order'] : 0;
        }
        if (count($script->precedents) == 0) {
            $script->extra['order'] = 0;
            return 0;
        }
        $max = 0;
        foreach ($script->precedents as $precedent) {
            $max = max($max, $this->compute_script_topological_order($precedent, $recursion_limiter + 1));
        }
        $max++;
        $script->extra['order'] = $max;
        return $max;
    }

    /** @var array<mixed, mixed>|false|null */
    private static array|false|null $manifest = null;

    /**
     * Returns the Piwigo manifest (dist/manifest.json) if it exists.
     * Returns null when the file is absent (legacy concat path is used).
     *
     * @return array<mixed, mixed>|null
     */
    public static function getManifest(): ?array
    {
        return self::manifest();
    }

    /** @return array<mixed, mixed>|null */
    private static function manifest(): ?array
    {
        if (self::$manifest !== null) {
            return self::$manifest ?: null;
        }
        $f = PHPWG_ROOT_PATH . 'dist/manifest.json';
        if (!is_file($f)) {
            self::$manifest = false;
            return null;
        }
        $decoded = json_decode((string) file_get_contents($f), true);
        self::$manifest = is_array($decoded) ? $decoded : false;
        return self::$manifest ?: null;
    }

    /**
     * Callback for scripts sorter.
     */
    private static function cmp_by_mode_and_order(Script $s1, Script $s2): int
    {
        $ret = $s1->load_mode - $s2->load_mode;
        if ($ret) {
            return $ret;
        }

        $order1 = is_int($s1->extra['order'] ?? null) ? $s1->extra['order'] : 0;
        $order2 = is_int($s2->extra['order'] ?? null) ? $s2->extra['order'] : 0;
        $ret = $order1 - $order2;
        if ($ret) {
            return $ret;
        }

        if ($order1 == 0 and ($s1->is_remote() xor $s2->is_remote())) {
            return $s1->is_remote() ? -1 : 1;
        }
        return strcmp((string) $s1->id, (string) $s2->id);
    }
}
