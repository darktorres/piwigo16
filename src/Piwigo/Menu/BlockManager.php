<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Menu;

use Piwigo\Config\CurrentConfig;
use Piwigo\Event\BlockManager\BlockManagerApply;
use Piwigo\Event\BlockManager\BlockManagerPrepareDisplay;
use Piwigo\Menu\Event\BlockManagerRegisterBlocks;
use Piwigo\Menu\Projection\MenubarBlocksPageContext;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

/**
 * Manages a set of RegisteredBlock and DisplayBlock.
 */
final class BlockManager
{
    /**
     * @var RegisteredBlock[]
     */
    private $registered_blocks = [];

    /**
     * @var DisplayBlock[]
     */
    private $display_blocks = [];

    /**
     * @param string $id
     */
    public function __construct(
        private $id,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * Triggers a notice that allows plugins of menu blocks to register the blocks.
     */
    public function load_registered_blocks(): void
    {
        $this->eventDispatcher->dispatchNotify(new BlockManagerRegisterBlocks($this));
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
        // blk_menubar is the only real BlockManager id anywhere in this
        // codebase (confirmed by grepping every `new BlockManager(...)`
        // call site) -- a real CurrentConfig property instead of the
        // former dynamic 'blk_' . $id bag key. Already decoded -- no
        // manual unserialize() needed.
        $mb_conf = $this->currentConfig->blkMenubar() ?? [];

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
        $this->eventDispatcher->dispatchNotify(new BlockManagerPrepareDisplay($this));
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
    private function sort_blocks(): void
    {
        uasort($this->display_blocks, self::cmp_by_position(...));
    }

    /**
     * Callback for blocks sorting.
     */
    private static function cmp_by_position(DisplayBlock $a, DisplayBlock $b): int
    {
        return $a->get_position() <=> $b->get_position();
    }

    /**
     * Parse the menu and assign the result in a template variable.
     *
     * @param string $var
     * @param string $file
     */
    public function apply($var, $file): void
    {
        $template = $this->currentTemplate->get();

        $template->set_filename('menubar', $file);
        $this->eventDispatcher->dispatchNotify(new BlockManagerApply($this));

        foreach ($this->display_blocks as $id => $block) {
            if (in_array($block->raw_content, [''], true) and in_array($block->template, [''], true)) {
                $this->hide_block($id);
            }
        }
        $this->sort_blocks();
        $template->assignContext(new MenubarBlocksPageContext($this->display_blocks));
        $template->assign_var_from_handle($var, 'menubar');
    }
}
