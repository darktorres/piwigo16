<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

/**
 * `PATCH /api/v1/categories/{id}` body DTO -- fields are optional ("leave a
 * field blank to keep the current value"): a genuinely absent JSON key
 * means "don't touch this field". `visible`/`commentable` are real JSON
 * booleans.
 */
final readonly class CategoryUpdateInput
{
    public function __construct(
        public ?string $name,
        public ?string $comment,
        public ?string $status,
        public ?bool $visible,
        public ?bool $commentable,
        public bool $applyCommentableToSubalbums,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $name = $raw['name'] ?? null;
        $comment = $raw['comment'] ?? null;
        $status = $raw['status'] ?? null;
        $visible = $raw['visible'] ?? null;
        $commentable = $raw['commentable'] ?? null;
        $applyCommentableToSubalbums = $raw['applyCommentableToSubalbums'] ?? null;

        return new self(
            name: is_string($name) ? $name : null,
            comment: is_string($comment) ? $comment : null,
            status: is_string($status) ? $status : null,
            visible: is_bool($visible) ? $visible : null,
            commentable: is_bool($commentable) ? $commentable : null,
            applyCommentableToSubalbums: is_bool($applyCommentableToSubalbums) ? $applyCommentableToSubalbums : false,
        );
    }
}
