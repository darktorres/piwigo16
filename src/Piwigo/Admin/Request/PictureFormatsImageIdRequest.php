<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['image_id']` for PictureFormatsPageRenderer::render()
 * (page slug "picture_formats") -- P26/SEC-40 Request DTO. Not mandatory:
 * an absent/invalid value now resolves to `null` (`ImageId::tryFrom()`),
 * which the caller must check explicitly before calling findById() --
 * `ImageId::from(0)` throws, so the old "let a fake id 0 fail the lookup"
 * trick no longer applies.
 */
final readonly class PictureFormatsImageIdRequest
{
    private function __construct(
        public ?ImageId $imageId,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArray($_GET, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source, InputValidator $inputValidator): self
    {
        $inputValidator
            ->validate('image_id', $source, false, ValidationPattern::ID);

        return new self(ImageId::tryFrom($source['image_id'] ?? null));
    }
}
