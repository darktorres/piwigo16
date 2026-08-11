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
    public function loadRegisteredBlocks(): void
    {
        $this->eventDispatcher->dispatchNotify(new BlockManagerRegisterBlocks($this));
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return RegisteredBlock[]
     */
    public function getRegisteredBlocks()
    {
        return $this->registered_blocks;
    }

    /**
     * Add a block with the menu. Usually called in 'blockmanager_register_blocks' event.
     *
     * @param RegisteredBlock $block
     */
    public function registerBlock($block): bool
    {
        if (isset($this->registered_blocks[$block->getId()])) {
            return false;
        }
        $this->registered_blocks[$block->getId()] = $block;
        return true;
    }

    /**
     * Performs one time preparation of registered blocks for display.
     * Triggers 'blockmanager_prepareDisplay' event where plugins can
     * reposition or hide blocks
     */
    public function prepareDisplay(): void
    {
        // blk_menubar is the only real BlockManager id anywhere in this
        // codebase (confirmed by grepping every `new BlockManager(...)`
        // call site) -- a real CurrentConfig property instead of the
        // former dynamic 'blk_' . $id bag key. Already decoded -- no
        // manual unserialize() needed.
        $mb_conf = $this->currentConfig->blkMenubar ?? [];

        $idx = 1;
        foreach ($this->registered_blocks as $id => $block) {
            // $mb_conf[$id], when present, is already a real int -- its own
            // sanitizing hook drops non-numeric entries entirely rather
            // than coercing them, so no is_numeric()/(int) cast is needed
            // here either.
            $pos = $mb_conf[$id] ?? $idx * 50;
            if ($pos > 0) {
                $this->display_blocks[$id] = new DisplayBlock($block);
                $this->display_blocks[$id]->setPosition($pos);
            }
            $idx++;
        }
        $this->sortBlocks();
        $this->eventDispatcher->dispatchNotify(new BlockManagerPrepareDisplay($this));
        $this->sortBlocks();
    }

    /**
     * Returns true if the block is hidden.
     *
     * @param string $block_id
     */
    public function isHidden($block_id): bool
    {
        return ! isset($this->display_blocks[$block_id]);
    }

    /**
     * Remove a block from the displayed blocks.
     *
     * @param string $block_id
     */
    public function hideBlock($block_id): void
    {
        unset($this->display_blocks[$block_id]);
    }

    /**
     * Returns a visible block.
     *
     * @param string $block_id
     * @return DisplayBlock|null
     */
    public function getBlock($block_id)
    {
        return $this->display_blocks[$block_id] ?? null;
    }

    /**
     * Changes the position of a block.
     *
     * @param string $block_id
     * @param int $position
     */
    public function setBlockPosition($block_id, $position): void
    {
        if (isset($this->display_blocks[$block_id])) {
            $this->display_blocks[$block_id]->setPosition($position);
        }
    }

    private function sortBlocks(): void
    {
        uasort($this->display_blocks, self::cmpByPosition(...));
    }

    /**
     * Callback for blocks sorting.
     */
    private static function cmpByPosition(DisplayBlock $a, DisplayBlock $b): int
    {
        return $a->getPosition() <=> $b->getPosition();
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
                $this->hideBlock($id);
            }
        }
        $this->sortBlocks();
        $template->assignContext(new MenubarBlocksPageContext($this->display_blocks));
        $template->assign_var_from_handle($var, 'menubar');
    }
}
