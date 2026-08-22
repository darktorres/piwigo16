<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

/**
 * {@see \Piwigo\Comment\CommentRepository::update()}'s own write-map --
 * {@see \Piwigo\Comment\CommentService::updateComment()}'s single real
 * caller already builds this as a known, finite field set.
 */
final readonly class CommentUpdateData
{
    public function __construct(
        public string $content,
        public ?string $websiteUrl,
        public bool $validated,
    ) {}
}
