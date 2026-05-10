<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Manages a list of CSS files and combining them in a unique file.
 */
final class CssLoader
{
    /** @var Css[] */
    private array $registered_css = [];
    /** @var int used to keep declaration order */
    private int $counter = 0;

    public function __construct()
    {
        $this->clear();
    }

    public function clear(): void
    {
        $this->registered_css = [];
        $this->counter = 0;
    }

    /**
     * @return Combinable[] array of combined CSS.
     */
    public function getCss(): array
    {
        uasort($this->registered_css, self::cmpByOrder(...));
        return array_values($this->registered_css);
    }

    /**
     * Callback for CSS files sorting.
     */
    private static function cmpByOrder(Css $a, Css $b): int
    {
        return $a->order - $b->order;
    }

    /**
     * Adds a new file, if a file with the same $id already exists, the one with
     * the higher $order or higher $version is kept.
     *
     * @param string $id
     * @param string $path
     * @param string|int $version
     * @param int $order
     */
    public function add($id, $path, $version = 0, $order = 0): void
    {
        if (!isset($this->registered_css[$id])) {
            // custom order has a higher impact than declaration order
            $this->registered_css[$id] = new Css($id, $path, $version, $order * 1000 + $this->counter);
            $this->counter++;
            return;
        }
        $css = $this->registered_css[$id];
        $effectiveOrder = $order * 1000 + $this->counter;
        if ($css->order < $effectiveOrder) {
            $css->order = $effectiveOrder;
            $css->path = $path;
        }
        if (version_compare((string) $css->version, (string) $version) < 0) {
            $css->version = $version;
            $css->path = $path;
        }
    }
}
