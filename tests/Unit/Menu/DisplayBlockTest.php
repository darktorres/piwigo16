<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Menu;

use PHPUnit\Framework\TestCase;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\RegisteredBlock;

final class DisplayBlockTest extends TestCase
{
    private function makeBlock(): DisplayBlock
    {
        return new DisplayBlock(new RegisteredBlock('nav', 'Navigation', 'piwigo'));
    }

    public function testGetBlockReturnsRegisteredBlock(): void
    {
        $block = $this->makeBlock();
        $rb = $block->getBlock();
        self::assertSame('nav', $rb->getId());
    }

    public function testGetTitleFallsBackToBlockName(): void
    {
        $block = $this->makeBlock();
        self::assertSame('Navigation', $block->getTitle());
    }

    public function testSetAndGetTitle(): void
    {
        $block = $this->makeBlock();
        $block->setTitle('My Title');
        self::assertSame('My Title', $block->getTitle());
    }

    public function testSetAndGetPosition(): void
    {
        $block = $this->makeBlock();
        $block->setPosition(100);
        self::assertSame(100, $block->getPosition());
    }

    public function testPublicProperties(): void
    {
        $block = $this->makeBlock();
        $block->data = ['key' => 'value'];
        $block->template = 'my_template.tpl';
        $block->raw_content = '<div>raw</div>';
        $block->id = 'custom_id';
        self::assertSame(['key' => 'value'], $block->data);
        self::assertSame('my_template.tpl', $block->template);
        self::assertSame('<div>raw</div>', $block->raw_content);
        self::assertSame('custom_id', $block->id);
    }
}
