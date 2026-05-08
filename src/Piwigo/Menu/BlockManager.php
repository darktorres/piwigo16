<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;

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
    public function loadRegisteredBlocks(): void
    {
        EventDispatcher::notify('blockmanager_register_blocks', $this);
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
        $conf_id = 'blk_'.$this->id;
        $mb_conf_raw = Config::raw($conf_id);
        if (is_array($mb_conf_raw)) {
            $mb_conf = $mb_conf_raw;
        } elseif (is_string($mb_conf_raw)) {
            $mb_conf = StringUtil::safeUnserialize($mb_conf_raw);
        } else {
            $mb_conf = [];
        }

        $idx = 1;
        foreach ($this->registered_blocks as $id => $block) {
            $stored = $mb_conf[$id] ?? null;
            $pos = is_int($stored) ? $stored : $idx * 50;
            if ($pos > 0) {
                $this->display_blocks[$id] = new DisplayBlock($block);
                $this->display_blocks[$id]->setPosition($pos);
            }
            $idx++;
        }
        $this->sortBlocks();
        EventDispatcher::notify('blockmanager_prepare_display', [$this]);
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
     *
     * @param string $block_id
     */
    public function hideBlock($block_id): void
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
        uasort($this->display_blocks, [self::class, 'cmpByPosition']);
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

        $template->setFilename('menubar', $file);
        EventDispatcher::notify('blockmanager_apply', [$this]);

        foreach ($this->display_blocks as $id => $block) {
            if (empty($block->raw_content) and empty($block->template)) {
                $this->hideBlock($id);
            }
        }
        $this->sortBlocks();
        $template->assign('blocks', $this->display_blocks);
        $template->assignVarFromHandle($var, 'menubar');
    }
}
