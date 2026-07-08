<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

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
    public function __construct(protected $id)
    {
    }

    /**
     * Triggers a notice that allows plugins of menu blocks to register the blocks.
     */
    public function load_registered_blocks()
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
    public function register_block($block)
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
    public function prepare_display()
    {
        global $conf;
        $conf_id = 'blk_' . $this->id;
        $mb_conf = $conf[$conf_id] ?? [];
        if (! is_array($mb_conf)) {
            $mb_conf = @unserialize($mb_conf);
        }

        $idx = 1;
        foreach ($this->registered_blocks as $id => $block) {
            $pos = $mb_conf[$id] ?? $idx * 50;
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
     * @return bool
     */
    public function is_hidden($block_id)
    {
        return ! isset($this->display_blocks[$block_id]);
    }

    /**
     * Remove a block from the displayed blocks.
     *
     * @param string $block_id
     */
    public function hide_block($block_id)
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
    public function set_block_position($block_id, $position)
    {
        if (isset($this->display_blocks[$block_id])) {
            $this->display_blocks[$block_id]->set_position($position);
        }
    }

    /**
     * Sorts the blocks.
     */
    protected function sort_blocks()
    {
        uasort($this->display_blocks, ['BlockManager', 'cmp_by_position']);
    }

    /**
     * Callback for blocks sorting.
     */
    protected static function cmp_by_position($a, $b)
    {
        return $a->get_position() - $b->get_position();
    }

    /**
     * Parse the menu and assign the result in a template variable.
     *
     * @param string $var
     * @param string $file
     */
    public function apply($var, $file)
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

/**
 * Represents a menu block registered in a BlockManager object.
 */
class RegisteredBlock
{
    /**
     * @param string $id
     * @param string $name
     * @param string $owner
     */
    public function __construct(protected $id, protected $name, protected $owner)
    {
    }

    /**
     * @return string
     */
    public function get_id()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function get_name()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function get_owner()
    {
        return $this->owner;
    }
}

/**
 * Represents a menu block ready for display in the BlockManager object.
 */
class DisplayBlock
{
    /**
     * @var int
     */
    protected $_position;

    /**
     * @var string
     */
    protected $_title;

    /**
     * @var mixed
     */
    public $data;

    /**
     * @var string
     */
    public $template;

    /**
     * @var string
     */
    public $raw_content;

    public $id;

    /**
     * @param RegisteredBlock $_registeredBlock
     */
    public function __construct(protected $_registeredBlock)
    {
    }

    /**
     * @return RegisteredBlock
     */
    public function get_block()
    {
        return $this->_registeredBlock;
    }

    /**
     * @return int
     */
    public function get_position()
    {
        return $this->_position;
    }

    /**
     * @param int $position
     */
    public function set_position($position)
    {
        $this->_position = $position;
    }

    /**
     * @return string
     */
    public function get_title()
    {
        if (isset($this->_title)) {
            return $this->_title;
        } else {
            return $this->_registeredBlock->get_name();
        }
    }

    /**
     * @param string $title
     */
    public function set_title($title)
    {
        $this->_title = $title;
    }
}
