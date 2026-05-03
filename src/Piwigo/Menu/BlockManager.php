<?php

declare(strict_types=1);

namespace Piwigo\Menu;

/**
 * @package functions\menubar
 */

/**
 * Manages a set of RegisteredBlock and DisplayBlock.
 */
class BlockManager
{
    /** @var RegisteredBlock[] */
    protected $registered_blocks = [];
    /** @var DisplayBlock[] */
    protected $display_blocks = [];

    /**
     * @param string $id
     */
    public function __construct(protected $id)
    {
    }

    /**
     * Triggers a notice that allows plugins of menu blocks to register the blocks.
     */
    public function load_registered_blocks(): void
    {
        trigger_notify('blockmanager_register_blocks', [$this]);
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
        $conf_id = 'blk_'.$this->id;
        $mb_conf_raw = \Piwigo\Config\Config::raw($conf_id);
        if (is_array($mb_conf_raw)) {
            $mb_conf = $mb_conf_raw;
        } elseif (is_string($mb_conf_raw)) {
            $mb_conf = safe_unserialize($mb_conf_raw);
        } else {
            $mb_conf = [];
        }

        $idx = 1;
        foreach ($this->registered_blocks as $id => $block) {
            $stored = $mb_conf[$id] ?? null;
            $pos = is_int($stored) ? $stored : $idx * 50;
            if ($pos > 0) {
                $this->display_blocks[$id] = new DisplayBlock($block);
                $this->display_blocks[$id]->set_position($pos);
            }
            $idx++;
        }
        $this->sort_blocks();
        trigger_notify('blockmanager_prepare_display', [$this]);
        $this->sort_blocks();
    }

    /**
     * Returns true if the block is hidden.
     *
     * @param string $block_id
     */
    public function is_hidden($block_id): bool
    {
        return !isset($this->display_blocks[$block_id]);
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
    protected static function cmp_by_position(DisplayBlock $a, DisplayBlock $b): int
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
        global $template;

        $template->set_filename('menubar', $file);
        trigger_notify('blockmanager_apply', [$this]);

        foreach ($this->display_blocks as $id => $block) {
            if (empty($block->raw_content) and empty($block->template)) {
                $this->hide_block($id);
            }
        }
        $this->sort_blocks();
        $template->assign('blocks', $this->display_blocks);
        $template->assign_var_from_handle($var, 'menubar');
    }
}
