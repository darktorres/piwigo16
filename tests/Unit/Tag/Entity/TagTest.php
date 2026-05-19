<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tag\Entity;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Tag\Entity\Tag;

final class TagTest extends TestCase
{
    public function testFromRowAndToRowRoundTrip(): void
    {
        $row = [
            'id'           => 3,
            'name'         => 'Sunset',
            'url_name'     => 'sunset',
            'lastmodified' => '2026-05-18 10:00:00',
        ];

        $tag = Tag::fromRow($row);
        self::assertEquals(TagId::from(3), $tag->id);
        self::assertSame('Sunset', $tag->name);
        self::assertSame('sunset', $tag->urlName);
        self::assertSame($row, $tag->toRow());
    }

    public function testRejectsMissingId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tag::fromRow(['name' => 'x', 'url_name' => 'x', 'lastmodified' => '2026-01-01 00:00:00']);
    }
}
