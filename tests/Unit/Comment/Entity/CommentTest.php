<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Comment\Entity;

use PHPUnit\Framework\TestCase;
use Piwigo\Comment\Entity\Comment;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;

final class CommentTest extends TestCase
{
    public function testFromRowMinimal(): void
    {
        $c = Comment::fromRow([
            'id'           => 5,
            'image_id'     => 42,
            'anonymous_id' => '127.0.0.1',
            'validated'    => 1,
        ]);
        self::assertEquals(CommentId::from(5), $c->id);
        self::assertEquals(ImageId::from(42), $c->imageId);
        self::assertSame('127.0.0.1', $c->anonymousId);
        self::assertTrue($c->validated);
        self::assertNull($c->author);
        self::assertNull($c->authorId);
        self::assertNull($c->content);
    }

    public function testToRowEmitsCanonicalShape(): void
    {
        $row = Comment::fromRow([
            'id'           => 5,
            'image_id'     => 42,
            'anonymous_id' => '127.0.0.1',
            'validated'    => 0,
            'author'       => 'Jane',
            'content'      => 'lovely',
        ])->toRow();
        self::assertSame(5, $row['id']);
        self::assertSame(42, $row['image_id']);
        self::assertSame('Jane', $row['author']);
        self::assertSame('lovely', $row['content']);
        self::assertSame(0, $row['validated']);
    }

    public function testRejectsMissingId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Comment::fromRow(['image_id' => 1, 'anonymous_id' => '', 'validated' => 0]);
    }
}
