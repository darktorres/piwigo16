<?php

declare(strict_types=1);

namespace Piwigo\Page\Request;

/**
 * Validated `$_GET['no_photo_yet']` shape for NoPhotoYetRenderer::render()
 * -- P26/SEC-40 Request DTO.
 */
final readonly class NoPhotoYetRequest
{
    private function __construct(
        public ?string $action,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $get
     */
    public static function fromArray(array $get): self
    {
        $action_raw = $get['no_photo_yet'] ?? null;

        return new self(is_string($action_raw) ? $action_raw : null);
    }
}
