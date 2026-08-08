<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

/**
 * {@see \Piwigo\Tag\TagService::createTag()}'s own fixed result shape --
 * exactly one of `error`/(`info`,`id`) is ever meaningful, matching the
 * method's own former two mutually-exclusive array shapes.
 */
final readonly class TagCreateOutcome
{
    private function __construct(
        public ?string $error,
        public ?string $info,
        public ?int $id,
    ) {}

    public static function failure(string $error): self
    {
        return new self(error: $error, info: null, id: null);
    }

    public static function success(string $info, int $id): self
    {
        return new self(error: null, info: $info, id: $id);
    }
}
