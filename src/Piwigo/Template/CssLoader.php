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

final class CssLoader
{
    /**
     * @var Css[]
     */
    private array $registered_css;

    /**
     * @var int used to keep declaration order
     */
    private int $counter;

    public function __construct()
    {
        $this->clear();
    }

    final public function clear(): void
    {
        $this->registered_css = [];
        $this->counter = 0;
    }

    /**
     * @return Combinable[] array of combined CSS.
     */
    public function get_css(UrlServiceInterface $urlService): array
    {
        $combiner = new FileCombiner(\Piwigo\Auth\AccessControl::currentForCaching(), 'css', $urlService, \Piwigo\Core\CurrentPaths::get(), \Piwigo\PluginConfig\EventDispatcher::get(), \Piwigo\Template\CurrentTemplate::current(), \Piwigo\Config\CurrentConfig::current(), $this->sortedRegisteredCss());
        return $combiner->combine();
    }

    /**
     * @return Css[]
     */
    private function sortedRegisteredCss(): array
    {
        uasort($this->registered_css, self::cmp_by_order(...));

        return $this->registered_css;
    }

    /**
     * Callback for CSS files sorting.
     */
    private static function cmp_by_order(Css $a, Css $b): int
    {
        return $a->order - $b->order;
    }

    /**
     * Adds a new file, if a file with the same $id already exsists, the one with
     * the higher $order or higher $version is kept.
     *
     * @param string $id
     * @param string $path
     * @param string|false $version false disables version-based cache
     *   busting, mirroring Combinable::$version's own contract; no current
     *   .tpl passes version=, but func_combine_css() forwards it verbatim
     * @param int $order
     * @param bool $is_template
     */
    public function add($id, $path, $version = '0', $order = 0, $is_template = false): void
    {
        if (! isset($this->registered_css[$id])) {
            // costum order as an higher impact than declaration order
            $css = new Css($id, $path, $version, $order * 1000 + $this->counter);
            $css->is_template = $is_template;
            $this->registered_css[$id] = $css;
            $this->counter++;
        } else {
            $css = $this->registered_css[$id];
            if (self::shouldReplace($css, $order, $version)) {
                unset($this->registered_css[$id]);
                $this->add($id, $path, $version, $order, $is_template);
            }
        }
    }

    /**
     * @param int $order
     * @param string|false $version
     */
    private static function shouldReplace(Css $existing, $order, $version): bool
    {
        return $existing->order < $order * 1000
            || $existing->version === false
            || $version === false
            || version_compare($existing->version, $version) < 0;
    }
}
