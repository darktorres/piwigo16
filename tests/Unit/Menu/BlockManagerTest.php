<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Menu;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\RegisteredBlock;

final class BlockManagerTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private BlockManager $mgr;

    #[\Override]
    protected function setUp(): void
    {
        Config::reset();
        $this->mgr = new BlockManager('menubar');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testGetId(): void
    {
        self::assertSame('menubar', $this->mgr->getId());
    }

    public function testRegisterBlockReturnsTrueOnFirstRegistration(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        self::assertTrue($this->mgr->registerBlock($block));
    }

    public function testRegisterBlockReturnsFalseForDuplicate(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->registerBlock($block);
        self::assertFalse($this->mgr->registerBlock($block));
    }

    public function testRegisteredBlocksAppearsInList(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->registerBlock($block);
        self::assertArrayHasKey('nav', $this->mgr->getRegisteredBlocks());
    }

    public function testIsHiddenReturnsTrueForUnknownBlock(): void
    {
        // A block not in display_blocks is considered hidden (not visible).
        self::assertTrue($this->mgr->isHidden('nonexistent'));
    }

    public function testHideBlockMakesBlockHidden(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->registerBlock($block);
        Config::loadArray(['blk_menubar' => []]);
        $this->mgr->prepareDisplay();

        self::assertFalse($this->mgr->isHidden('nav'), 'should be visible after prepare_display');
        $this->mgr->hideBlock('nav');
        self::assertTrue($this->mgr->isHidden('nav'));
    }

    public function testGetBlockNullBeforePrepare(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->registerBlock($block);
        self::assertNull($this->mgr->getBlock('nav'), 'display block not created until prepare_display');
    }

    public function testPrepareDisplayCreatesDisplayBlocks(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->registerBlock($block);
        Config::loadArray(['blk_menubar' => []]);
        $this->mgr->prepareDisplay();
        self::assertInstanceOf(DisplayBlock::class, $this->mgr->getBlock('nav'));
    }

    public function testSetBlockPosition(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->registerBlock($block);
        Config::loadArray(['blk_menubar' => []]);
        $this->mgr->prepareDisplay();
        $this->mgr->setBlockPosition('nav', 999);
        $block = $this->mgr->getBlock('nav');
        self::assertNotNull($block);
        self::assertSame(999, $block->getPosition());
    }
}
