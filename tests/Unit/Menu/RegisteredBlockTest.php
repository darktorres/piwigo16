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
        self::assertSame('menubar_categories', $block->get_id());
        self::assertSame('Albums', $block->get_name());
        self::assertSame('piwigo', $block->get_owner());
    }

    public function testConstructorAcceptsStringValues(): void
    {
        $block = new RegisteredBlock('my_block', 'My Block', 'my-plugin');
        self::assertSame('my_block', $block->get_id());
    }
}
