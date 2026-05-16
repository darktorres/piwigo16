<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Piwigo\Event\BlockManager\BlockManagerApply;
use Piwigo\Event\BlockManager\BlockManagerPrepareDisplay;
use Piwigo\Event\BlockManager\BlockManagerRegisterBlocks;
use Piwigo\Template\TemplateRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Manages a set of RegisteredBlock and DisplayBlock for the menubar.
 *
 * v17 removed the per-menu-id dynamic conf-key indirection — only the
 * menubar exists. Layout persistence is delegated to MenubarLayoutRepository.
 */
final class BlockManager
{
    /** @var array<string, RegisteredBlock> */
    protected array $registered_blocks = [];
    /** @var array<string, DisplayBlock> */
    protected array $display_blocks = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly MenubarLayoutRepository $layout,
    ) {
    }

    /**
     * Triggers a notice that allows plugins of menu blocks to register the blocks.
     */
    public function loadRegisteredBlocks(): void
    {
        $this->dispatcher->dispatch(new BlockManagerRegisterBlocks($this));
    }

    /**
     * @return array<string, RegisteredBlock>
     */
    public function getRegisteredBlocks(): array
    {
        return $this->registered_blocks;
    }

    /**
     * Add a block with the menu. Usually called in 'blockmanager_register_blocks' event.
     */
    public function registerBlock(RegisteredBlock $block): bool
    {
        if (isset($this->registered_blocks[$block->getId()])) {
            return false;
        }
        $this->registered_blocks[$block->getId()] = $block;
        return true;
    }

    /**
     * Performs one time preparation of registered blocks for display.
     * Triggers 'blockmanager_prepare_display' event where plugins can
     * reposition or hide blocks
     */
    public function prepareDisplay(): void
    {
        $mb_conf = $this->layout->load();

        $idx = 1;
        foreach ($this->registered_blocks as $id => $block) {
            $pos = $mb_conf[$id] ?? ($idx * 50);
            if ($pos > 0) {
                $this->display_blocks[$id] = new DisplayBlock($block);
                $this->display_blocks[$id]->setPosition($pos);
            }
            $idx++;
        }
        $this->sortBlocks();
        $this->dispatcher->dispatch(new BlockManagerPrepareDisplay($this));
        $this->sortBlocks();
    }

    /**
     * Returns true if the block is hidden.
     */
    public function isHidden(string $block_id): bool
    {
        return !isset($this->display_blocks[$block_id]);
    }

    /**
     * Remove a block from the displayed blocks.
     */
    public function hideBlock(string $block_id): void
    {
        unset($this->display_blocks[$block_id]);
    }

    /**
     * Returns a visible block.
     */
    public function getBlock(string $block_id): ?DisplayBlock
    {
        return $this->display_blocks[$block_id] ?? null;
    }

    /**
     * Changes the position of a block.
     */
    public function setBlockPosition(string $block_id, int $position): void
    {
        if (isset($this->display_blocks[$block_id])) {
            $this->display_blocks[$block_id]->setPosition($position);
        }
    }

    /**
     * Sorts the blocks.
     */
    protected function sortBlocks(): void
    {
        uasort($this->display_blocks, self::cmpByPosition(...));
    }

    /**
     * Callback for blocks sorting.
     */
    protected static function cmpByPosition(DisplayBlock $a, DisplayBlock $b): int
    {
        return $a->getPosition() - $b->getPosition();
    }

    /**
     * Parse the menu and assign the result in a template variable.
     */
    public function apply(string $var, string $file): void
    {
        $template = TemplateRegistry::current();

        $this->dispatcher->dispatch(new BlockManagerApply($this));

        foreach ($this->display_blocks as $id => $block) {
            if (empty($block->raw_content) and empty($block->template)) {
                $this->hideBlock($id);
            }
        }
        $this->sortBlocks();
        $template->assign('blocks', $this->display_blocks);
        $template->assignVarFromTemplate($var, $file);
    }
}
