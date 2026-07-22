<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Menu;

use Piwigo\Template\Template;

/**
 * Manages a set of RegisteredBlock and DisplayBlock.
 */
class BlockManager
{
    /**
     * @var RegisteredBlock[]
     */
    protected $registered_blocks = [];

    /**
     * @var DisplayBlock[]
     */
    protected $display_blocks = [];

    /**
     * @param string $id
     */
    public function __construct(
        protected $id
    ) {}

    /**
     * Triggers a notice that allows plugins of menu blocks to register the blocks.
     */
    public function load_registered_blocks(): void
    {
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('blockmanager_register_blocks', [$this]);
    }

    /**
     * @return string
     */
    public function get_id()
    {
        return $this->id;
    }

    /**
     * @return RegisteredBlock[]
     */
    public function get_registered_blocks()
    {
        return $this->registered_blocks;
    }

    /**
     * Add a block with the menu. Usually called in 'blockmanager_register_blocks' event.
     *
     * @param RegisteredBlock $block
     */
    public function register_block($block): bool
    {
        if (isset($this->registered_blocks[$block->get_id()])) {
            return false;
        }
        $this->registered_blocks[$block->get_id()] = $block;
        return true;
    }

    /**
     * Performs one time preparation of registered blocks for display.
     * Triggers 'blockmanager_prepare_display' event where plugins can
     * reposition or hide blocks
     */
    public function prepare_display(): void
    {
        $conf_id = 'blk_' . $this->id;
        $mb_conf = \Piwigo\Config\Config::all()[$conf_id] ?? [];
        if (! is_array($mb_conf)) {
            $mb_conf = is_string($mb_conf) ? @unserialize($mb_conf) : false;
        }
        if (! is_array($mb_conf)) {
            $mb_conf = [];
        }

        $idx = 1;
        foreach ($this->registered_blocks as $id => $block) {
            $raw_pos = $mb_conf[$id] ?? $idx * 50;
            $pos = is_numeric($raw_pos) ? (int) $raw_pos : $idx * 50;
            if ($pos > 0) {
                $this->display_blocks[$id] = new DisplayBlock($block);
                $this->display_blocks[$id]->set_position($pos);
            }
            $idx++;
        }
        $this->sort_blocks();
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('blockmanager_prepare_display', [$this]);
        $this->sort_blocks();
    }

    /**
     * Returns true if the block is hidden.
     *
     * @param string $block_id
     */
    public function is_hidden($block_id): bool
    {
        return ! isset($this->display_blocks[$block_id]);
    }

    /**
     * Remove a block from the displayed blocks.
     *
     * @param string $block_id
     */
    public function hide_block($block_id): void
    {
        unset($this->display_blocks[$block_id]);
    }

    /**
     * Returns a visible block.
     *
     * @param string $block_id
     * @return DisplayBlock|null
     */
    public function get_block($block_id)
    {
        return $this->display_blocks[$block_id] ?? null;
    }

    /**
     * Changes the position of a block.
     *
     * @param string $block_id
     * @param int $position
     */
    public function set_block_position($block_id, $position): void
    {
        if (isset($this->display_blocks[$block_id])) {
            $this->display_blocks[$block_id]->set_position($position);
        }
    }

    /**
     * Sorts the blocks.
     */
    protected function sort_blocks(): void
    {
        uasort($this->display_blocks, [self::class, 'cmp_by_position']);
    }

    /**
     * Callback for blocks sorting.
     */
    protected static function cmp_by_position(DisplayBlock $a, DisplayBlock $b): int|float
    {
        return $a->get_position() - $b->get_position();
    }

    /**
     * Parse the menu and assign the result in a template variable.
     *
     * @param string $var
     * @param string $file
     */
    public function apply($var, $file): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $template->set_filename('menubar', $file);
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('blockmanager_apply', [$this]);

        foreach ($this->display_blocks as $id => $block) {
            if (in_array($block->raw_content, [null, ''], true) and in_array($block->template, [null, ''], true)) {
                $this->hide_block($id);
            }
        }
        $this->sort_blocks();
        $template->assign('blocks', $this->display_blocks);
        $template->assign_var_from_handle($var, 'menubar');
    }
}
