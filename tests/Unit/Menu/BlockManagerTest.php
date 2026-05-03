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
    private BlockManager $mgr;

    protected function setUp(): void
    {
        Config::reset();
        $this->mgr = new BlockManager('menubar');
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testGetId(): void
    {
        self::assertSame('menubar', $this->mgr->get_id());
    }

    public function testRegisterBlockReturnsTrueOnFirstRegistration(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        self::assertTrue($this->mgr->register_block($block));
    }

    public function testRegisterBlockReturnsFalseForDuplicate(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->register_block($block);
        self::assertFalse($this->mgr->register_block($block));
    }

    public function testRegisteredBlocksAppearsInList(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->register_block($block);
        self::assertArrayHasKey('nav', $this->mgr->get_registered_blocks());
    }

    public function testIsHiddenReturnsTrueForUnknownBlock(): void
    {
        // A block not in display_blocks is considered hidden (not visible).
        self::assertTrue($this->mgr->is_hidden('nonexistent'));
    }

    public function testHideBlockMakesBlockHidden(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->register_block($block);
        Config::loadArray(['blk_menubar' => []]);
        $this->mgr->prepare_display();

        self::assertFalse($this->mgr->is_hidden('nav'), 'should be visible after prepare_display');
        $this->mgr->hide_block('nav');
        self::assertTrue($this->mgr->is_hidden('nav'));
    }

    public function testGetBlockNullBeforePrepare(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->register_block($block);
        self::assertNull($this->mgr->get_block('nav'), 'display block not created until prepare_display');
    }

    public function testPrepareDisplayCreatesDisplayBlocks(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->register_block($block);
        Config::loadArray(['blk_menubar' => []]);
        $this->mgr->prepare_display();
        self::assertInstanceOf(DisplayBlock::class, $this->mgr->get_block('nav'));
    }

    public function testSetBlockPosition(): void
    {
        $block = new RegisteredBlock('nav', 'Navigation', 'piwigo');
        $this->mgr->register_block($block);
        Config::loadArray(['blk_menubar' => []]);
        $this->mgr->prepare_display();
        $this->mgr->set_block_position('nav', 999);
        self::assertSame(999, $this->mgr->get_block('nav')->get_position());
    }
}
