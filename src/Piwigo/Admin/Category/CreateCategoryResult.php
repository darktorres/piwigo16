<?php

declare(strict_types=1);

namespace Piwigo\Admin\Category;

/**
 * Typed replacement for create_virtual_category()'s own loosely-typed
 * return shape (array{error: string}|array{info: string, id: int}) --
 * exactly one of $error/$id is ever meaningful, matching the free
 * function's own two mutually-exclusive return shapes.
 */
final readonly class CreateCategoryResult
{
    private function __construct(
        public bool $success,
        public ?string $message,
        public ?int $categoryId,
    ) {}

    public static function failure(string $error): self
    {
        return new self(success: false, message: $error, categoryId: null);
    }

    public static function success(string $info, int $categoryId): self
    {
        return new self(success: true, message: $info, categoryId: $categoryId);
    }
}
