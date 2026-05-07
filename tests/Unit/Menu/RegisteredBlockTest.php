<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Menu;

use PHPUnit\Framework\TestCase;
use Piwigo\Menu\RegisteredBlock;

final class RegisteredBlockTest extends TestCase
{
    public function testGetters(): void
    {
        $block = new RegisteredBlock('menubar_categories', 'Albums', 'piwigo');
        self::assertSame('menubar_categories', $block->getId());
        self::assertSame('Albums', $block->getName());
        self::assertSame('piwigo', $block->getOwner());
    }

    public function testConstructorAcceptsStringValues(): void
    {
        $block = new RegisteredBlock('my_block', 'My Block', 'my-plugin');
        self::assertSame('my_block', $block->getId());
    }
}
