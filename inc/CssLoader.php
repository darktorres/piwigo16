<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use SmartyException;

/**
 * Manages a list of CSS files and combining them in a unique file.
 */
class CssLoader
{
    /**
     * @var Css[]
     */
    private $registered_css;

    /**
     * @var int used to keep declaration order
     */
    private $counter;

    public function __construct()
    {
        $this->clear();
    }

    public function clear()
    {
        $this->registered_css = [];
        $this->counter = 0;
    }

    /**
     * @return Combinable[] array of combined CSS.
     * @throws SmartyException
     */
    public function get_css()
    {
        uasort($this->registered_css, self::cmp_by_order(...));
        $combiner = new FileCombiner('css', $this->registered_css);
        return $combiner->combine();
    }

    /**
     * Adds a new file, if a file with the same $id already exists, the one with
     * the higher $order or higher $version is kept.
     *
     * @param string $id
     * @param string $path
     * @param string $version
     * @param int $order
     * @param bool $is_template
     */
    public function add($id, $path, $version = 0, $order = 0, $is_template = false)
    {
        if (! isset($this->registered_css[$id])) {
            // custom order as an higher impact than declaration order
            $css = new Css($id, $path, $version, $order * 1000 + $this->counter);
            $css->is_template = $is_template;
            $this->registered_css[$id] = $css;
            $this->counter++;
        } else {
            $css = $this->registered_css[$id];
            if ($css->order < $order * 1000 || version_compare($css->version, $version) < 0) {
                unset($this->registered_css[$id]);
                $this->add($id, $path, $version, $order, $is_template);
            }
        }
    }

    /**
     * Callback for CSS files sorting.
     */
    private static function cmp_by_order($a, $b)
    {
        return $a->order - $b->order;
    }
}
