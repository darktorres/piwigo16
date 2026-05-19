<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Category\Entity;

use PHPUnit\Framework\TestCase;
use Piwigo\Category\Entity\Category;
use Piwigo\Common\Enum\Privacy;
use Piwigo\Common\ValueObject\CategoryId;

final class CategoryTest extends TestCase
{
    public function testFromRowMinimalAndRoundTrip(): void
    {
        $row = [
            'id'           => 7,
            'name'         => 'Vacation',
            'uppercats'    => '3,5,7',
            'status'       => 'public',
            'lastmodified' => '2026-05-18 10:00:00',
        ];

        $cat = Category::fromRow($row);
        self::assertEquals(CategoryId::from(7), $cat->id);
        self::assertSame('Vacation', $cat->name);
        self::assertSame('3,5,7', $cat->uppercats);
        self::assertSame(Privacy::Public, $cat->status);
        self::assertNull($cat->idUppercat);
        self::assertNull($cat->dir);
        self::assertTrue($cat->visible);
        self::assertTrue($cat->commentable);

        $back = $cat->toRow();
        self::assertSame(7, $back['id']);
        self::assertSame('Vacation', $back['name']);
        self::assertSame('public', $back['status']);
        self::assertSame(1, $back['visible']);
    }

    public function testFromRowRejectsMissingId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Category::fromRow(['name' => 'x', 'uppercats' => '', 'status' => 'public', 'lastmodified' => '2026-01-01 00:00:00']);
    }
}
