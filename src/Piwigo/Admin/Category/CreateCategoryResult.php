<?php

declare(strict_types=1);

namespace Piwigo\Admin\Category;

/** Discriminated result from CategoryAdminService::createVirtualCategory(). */
final readonly class CreateCategoryResult
{
    private function __construct(
        public bool $isError,
        public string|null $error = null,
        public int|null $id = null,
        public string|null $info = null,
    ) {
    }

    public static function error(string $message): self
    {
        return new self(isError: true, error: $message);
    }

    public static function success(int $id, string $info): self
    {
        return new self(isError: false, id: $id, info: $info);
    }
}
