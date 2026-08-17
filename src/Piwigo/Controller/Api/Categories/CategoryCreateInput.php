<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

/**
 * `POST /api/v1/categories` body DTO -- mirrors `Ws\Categories\AddParams`'s
 * own fields, fed from a decoded JSON body instead of WS's `$params`
 * array. `visible`/`commentable` are real JSON booleans here rather than
 * WS's `WsParamType::BOOL`-coerced string params.
 */
final readonly class CategoryCreateInput
{
    public function __construct(
        public string $name,
        public ?int $parentId,
        public ?string $comment,
        public bool $visible,
        public ?string $status,
        public bool $commentable,
        public ?string $position,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $name = $raw['name'] ?? null;
        $parentId = $raw['parentId'] ?? null;
        $comment = $raw['comment'] ?? null;
        $visible = $raw['visible'] ?? null;
        $status = $raw['status'] ?? null;
        $commentable = $raw['commentable'] ?? null;
        $position = $raw['position'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
            parentId: is_int($parentId) ? $parentId : null,
            comment: is_string($comment) ? $comment : null,
            visible: is_bool($visible) ? $visible : true,
            status: is_string($status) ? $status : null,
            commentable: is_bool($commentable) ? $commentable : true,
            position: is_string($position) ? $position : null,
        );
    }
}
