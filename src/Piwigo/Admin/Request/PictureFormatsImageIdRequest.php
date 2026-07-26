<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['image_id']` for PictureFormatsPageRenderer::render()
 * (page slug "picture_formats") -- P27/SEC-40 Request DTO. Not mandatory:
 * an absent/invalid value already safely resolves to a nonexistent image
 * id 0 downstream, which the caller's own findById()-then-fatalError()
 * check handles correctly.
 */
final readonly class PictureFormatsImageIdRequest
{
    private function __construct(
        public int $imageId,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        new InputValidator()
            ->validate('image_id', $source, false, ValidationPattern::ID);

        $image_id_raw = $source['image_id'] ?? null;

        return new self(is_numeric($image_id_raw) ? (int) $image_id_raw : 0);
    }
}
