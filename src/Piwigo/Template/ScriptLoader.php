<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Html\HtmlService;

/**
 * Manage a list of required scripts for a page, by optimizing their loading location (head, footer, async)
 * and later on by combining them in a unique file respecting at the same time dependencies.
 */
final class ScriptLoader
{
    /** @var array<string, Script> */
    private array $registered_scripts = [];
    /** @var string[] */
    public array $inline_scripts = [];

    private bool $did_head = false;
    /** @var array */
    /** @var array<string, Script> */
    private array $head_done_scripts = [];
    private ?bool $did_footer = null;

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

    public function didHead(): bool
    {
        return $this->did_head;
    }

    /**
     * @return array<string, Script>
     */
    public function getAll(): array
    {
        return $this->registered_scripts;
    }

    /**
     * @param string[] $require
     */
    /** @param string[] $require */
    public function addInline(string $code, array $require): void
    {
        if ($this->did_footer) {
            throw new \LogicException('Attempt to add inline script but the footer has been written');
        }
        if (!empty($require)) {
            foreach ($require as $id) {
                if (!isset($this->registered_scripts[$id])) {
                    HtmlService::fatalError("inline script not found require $id");
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
    public function add(string $id, int|string $load_mode, array $require, ?string $path, string|int $version = 0): void
    {
        if ($this->did_head && $load_mode == 0) {
            throw new \LogicException("Attempt to add script $id but the head has been written");
        } elseif ($this->did_footer) {
            throw new \LogicException("Attempt to add script $id but the footer has been written");
        }
        if (($manifest = self::manifest()) !== null) {
            $entry = $manifest[$id] ?? null;
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $path = 'dist/' . $entry['file'];
            }
        }
        if (! isset($this->registered_scripts[$id])) {
            $this->registered_scripts[$id] = new Script((int) $load_mode, $id, $path, $version, $require);
        } else {
            $script = $this->registered_scripts[$id];
            if ($path !== null && $path !== '') {
                $script->path = $path;
            }
            if ($require !== []) {
                $script->precedents = array_unique(array_merge($script->precedents, $require));
            }
        }
    }

    /**
     * Returns combined scripts loaded in header.
     *
     * @return Combinable[]
     */
    public function getHeadScripts(): array
    {
        self::checkLoadDep($this->registered_scripts);
        foreach (array_keys($this->registered_scripts) as $id) {
            $this->computeScriptTopologicalOrder($id);
        }

        uasort($this->registered_scripts, self::cmpByModeAndOrder(...));

        foreach ($this->registered_scripts as $id => $script) {
            if ($script->load_mode > 0) {
                break;
            }
            if (!empty($script->path)) {
                $this->head_done_scripts[$id] = $script;
            } else {
                throw new \LogicException("Script $id has an undefined path");
            }
        }
        $this->did_head = true;
        return array_values($this->head_done_scripts);
    }

    /**
     * Returns combined scripts loaded in footer.
     *
     * @return array{0: Combinable[], 1: Combinable[]}
     */
    public function getFooterScripts(): array
    {
        if (!$this->did_head) {
            self::checkLoadDep($this->registered_scripts);
        }
        $this->did_footer = true;
        $todo = [];
        foreach ($this->registered_scripts as $id => $script) {
            if (!isset($this->head_done_scripts[$id])) {
                $todo[$id] = $script;
            }
        }

        foreach (array_keys($todo) as $id) {
            $this->computeScriptTopologicalOrder($id);
        }

        uasort($todo, self::cmpByModeAndOrder(...));

        $result = [ [], [] ];
        foreach ($todo as $id => $script) {
            $result[$script->load_mode - 1][$id] = $script;
        }
        return [ array_values($result[0]), array_values($result[1]) ];
    }

    /**
     * Checks dependencies among Scripts.
     * Checks that if B depends on A, then B->load_mode >= A->load_mode in order to respect execution order.
     *
     * @param Script[] $scripts
     */
    private static function checkLoadDep(array $scripts): void
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
                    if ($load == 2 && $scripts[$precedent]->load_mode == 2) {// predecessor of an async script must be footer to guarantee execution order
                        $scripts[$precedent]->load_mode = 1;
                        $changed = true;
                    }
                }
            }
        } while ($changed);
    }

    /**
     * Compute script order depending on dependencies.
     * Assigned to $script->order.
     */
    private function computeScriptTopologicalOrder(string $script_id, int $recursion_limiter = 0): int
    {
        if (!isset($this->registered_scripts[$script_id])) {
            throw new \LogicException("Undefined script $script_id is required by someone");
        }
        $recursion_limiter < 5 or HtmlService::fatalError('combined script circular dependency');
        $script = $this->registered_scripts[$script_id];
        if ($script->order !== null) {
            return $script->order;
        }
        if (count($script->precedents) == 0) {
            $script->order = 0;
            return 0;
        }
        $max = 0;
        foreach ($script->precedents as $precedent) {
            $max = max($max, $this->computeScriptTopologicalOrder($precedent, $recursion_limiter + 1));
        }
        $max++;
        $script->order = $max;
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
            return self::$manifest !== false ? self::$manifest : null;
        }
        $f = PHPWG_ROOT_PATH . 'dist/manifest.json';
        if (!is_file($f)) {
            self::$manifest = false;
            return null;
        }
        $decoded = json_decode((string) file_get_contents($f), true);
        self::$manifest = is_array($decoded) ? $decoded : false;
        return self::$manifest !== false ? self::$manifest : null;
    }

    /**
     * Callback for scripts sorter.
     */
    private static function cmpByModeAndOrder(Script $s1, Script $s2): int
    {
        $ret = $s1->load_mode - $s2->load_mode;
        if ($ret) {
            return $ret;
        }

        $order1 = $s1->order ?? 0;
        $order2 = $s2->order ?? 0;
        $ret = $order1 - $order2;
        if ($ret) {
            return $ret;
        }

        if ($order1 == 0 and ($s1->isRemote() xor $s2->isRemote())) {
            return $s1->isRemote() ? -1 : 1;
        }
        return strcmp($s1->id, $s2->id);
    }
}
