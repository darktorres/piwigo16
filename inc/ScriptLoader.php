<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use SmartyException;

/**
 * Manage a list of required scripts for a page, by optimizing their loading location (head, footer, async)
 * and later on by combining them in a unique file respecting at the same time dependencies.
 */
final class ScriptLoader
{
    /**
     * @var array<string>
     */
    public array $inline_scripts;

    /**
     * @var array<Script>
     */
    private array $registered_scripts;

    private bool $did_head;

    private array $head_done_scripts;

    private bool $did_footer;

    private static array $known_paths = [
        'core.scripts' => 'themes/default/js/scripts.js',
        'jquery' => 'node_modules/jquery/dist/jquery.js',
        'jquery.ui' => 'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/jquery-ui.js',
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
     * @param array<int, string> $require
     */
    public function add_inline(
        string $code,
        array $require
    ): void {
        if ($this->did_footer) {
            trigger_error('Attempt to add inline script but the footer has been written', E_USER_WARNING);
        }

        if (! empty($require)) {
            foreach ($require as $id) {
                if (! isset($this->registered_scripts[$id])) {
                    if (! $this->load_known_required_script($id, 1)) {
                        functions_html::fatal_error("inline script not found require {$id}");
                    }
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
     * @param array<int, string> $require
     */
    public function add(
        string $id,
        int $load_mode,
        array $require,
        ?string $path,
        int|string $version = 0,
        $is_template = false
    ): void {
        if ($this->did_head &&
            $load_mode == 0
        ) {
            trigger_error("Attempt to add script {$id} but the head has been written", E_USER_WARNING);
        } elseif ($this->did_footer) {
            trigger_error("Attempt to add script {$id} but the footer has been written", E_USER_WARNING);
        }

        if (! isset($this->registered_scripts[$id])) {
            $script = new Script($load_mode, $id, $path, $version, $require);
            $script->is_template = $is_template;
            self::fill_well_known($id, $script);
            $this->registered_scripts[$id] = $script;

            // Try to load undefined required script
            foreach ($script->precedents as $script_id) {
                if (! isset($this->registered_scripts[$script_id])) {
                    $this->load_known_required_script($script_id, $load_mode);
                }
            }
        } else {
            $script = $this->registered_scripts[$id];

            if (count($require)) {
                $script->precedents = array_unique(array_merge($script->precedents, $require));
            }

            $script->set_path($path);

            if ($version &&
                version_compare($script->version, $version) < 0
            ) {
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
     * @throws SmartyException
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

            if (! empty($script->path)) {
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
     * @return Combinable[][]
     * @throws SmartyException
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
            if (! is_string($script->load_mode)) {
                $result[$script->load_mode - 1][$id] = $script;
            }
        }

        return [self::do_combine($result[0], 1), self::do_combine($result[1], 2)];
    }

    /**
     * @param Script[] $scripts
     * @return Combinable[]
     * @throws SmartyException
     */
    private static function do_combine(
        array $scripts,
        int $load_mode
    ): array {
        $combiner = new FileCombiner('js', $scripts);
        return $combiner->combine();
    }

    /**
     * Checks dependencies among Scripts.
     * Checks that if B depends on A, then B->load_mode >= A->load_mode in order to respect execution order.
     *
     * @param Script[] $scripts
     */
    private static function check_load_dep(
        array $scripts
    ): void {
        global $conf;

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

                    if ($load == 2 &&
                        $scripts[$precedent]->load_mode == 2 &&
                        ($scripts[$precedent]->is_remote() || ! $conf['template_combine_files'])
                    ) { // we are async -> a predecessor cannot be async unless it can be merged; otherwise script execution order is not guaranteed
                        $scripts[$precedent]->load_mode = 1;
                        $changed = true;
                    }
                }
            }
        } while ($changed);
    }

    /**
     * Fill a script dependencies with the known jQuery UI scripts.
     *
     * @param string $id in FileCombiner::$known_paths
     */
    private static function fill_well_known(
        string $id,
        Script $script
    ): void {
        if (empty($script->path) &&
            isset(self::$known_paths[$id])
        ) {
            $script->path = self::$known_paths[$id];
        }

        if (strncmp($id, 'jquery.', 7) == 0) {
            $required_ids = ['jquery'];

            foreach ($required_ids as $required_id) {
                if (! in_array($required_id, $script->precedents)) {
                    $script->precedents[] = $required_id;
                }
            }
        }
    }

    /**
     * Add a known jQuery UI script to loaded scripts.
     *
     * @param string $id in FileCombiner::$known_paths
     */
    private function load_known_required_script(
        string $id,
        int $load_mode
    ): bool {
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
    private function compute_script_topological_order(
        string $script_id,
        int $recursion_limiter = 0
    ): int {
        if (! isset($this->registered_scripts[$script_id])) {
            trigger_error("Undefined script {$script_id} is required by someone", E_USER_WARNING);
            return 0;
        }

        if ($recursion_limiter >= 5) {
            functions_html::fatal_error('combined script circular dependency');
        }

        $script = $this->registered_scripts[$script_id];

        if (isset($script->extra['order'])) {
            return $script->extra['order'];
        }

        if (count($script->precedents) == 0) {
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
    private static function cmp_by_mode_and_order(
        Script $s1,
        Script $s2
    ): int|float {
        $ret = intval($s1->load_mode) - intval($s2->load_mode);

        if ($ret) {
            return $ret;
        }

        $ret = $s1->extra['order'] - $s2->extra['order'];

        if ($ret) {
            return $ret;
        }

        if ($s1->extra['order'] == 0 &&
           ($s1->is_remote() xor $s2->is_remote())
        ) {
            return $s1->is_remote() ? -1 : 1;
        }

        return strcmp($s1->id, $s2->id);
    }
}
