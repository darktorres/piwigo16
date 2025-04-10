<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Manages a list of CSS files and combining them in a unique file.
 */
final class CssLoader
{
    /**
     * @var array<Css>
     */
    private array $registered_css;

    /**
     * used to keep declaration order
     */
    private int $counter;

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
     * @throws \Smarty\Exception
     */
    public function get_css(): array
    {
        uasort($this->registered_css, $this->cmp_by_order(...));
        $combiner = new FileCombiner('css', $this->registered_css);
        return $combiner->combine();
    }

    /**
     * Adds a new file, if a file with the same $id already exists, the one with
     * the higher $order or higher $version is kept.
     */
    public function add(
        string $id,
        string $path,
        int|string $version = 0,
        int $order = 0,
        bool $is_template = false
    ): void {
        if (! isset($this->registered_css[$id])) {
            // custom order as an higher impact than declaration order
            $css = new Css($id, $path, $version, $order * 1000 + $this->counter);
            $css->is_template = $is_template;
            $this->registered_css[$id] = $css;
            $this->counter++;
        } else {
            $css = $this->registered_css[$id];

            if ($css->order < $order * 1000 ||
                version_compare((string) $css->version, (string) $version) < 0
            ) {
                unset($this->registered_css[$id]);
                $this->add($id, $path, $version, $order, $is_template);
            }
        }
    }

    /**
     * Callback for CSS files sorting.
     */
    private function cmp_by_order(
        Css $a,
        Css $b
    ): int|float {
        return $a->order - $b->order;
    }
}
