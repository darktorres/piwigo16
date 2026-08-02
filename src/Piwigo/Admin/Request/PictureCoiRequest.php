<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for PictureCoiPageRenderer::render()
 * (page slug "picture_coi") -- P27/SEC-40 Request DTO. `coi` precomputes
 * the original's 4-char center-of-interest code (or `null` when `l` is
 * absent/empty) -- a self-contained, side-effect-free transform of
 * `l`/`t`/`r`/`b` via the pure `DerivativeUrlCodec::fractionToChar()`
 * utility, with no dependency on renderer-local state.
 */
final readonly class PictureCoiRequest
{
    private function __construct(
        public int $imageId,
        public bool $isSubmitted,
        public ?string $coi,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post): self
    {
        InputValidator::createStatic()
            ->validate('image_id', $get, false, ValidationPattern::ID);

        $image_id = 0;
        if (isset($get['image_id']) && is_numeric($get['image_id'])) {
            $image_id = (int) $get['image_id'];
        }

        $coi_l = $post['l'] ?? null;
        $coi_l_str = is_string($coi_l) ? $coi_l : '';

        $coi = null;
        if ($coi_l_str !== '') {
            $to_fraction = static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0;

            $coi = DerivativeUrlCodec::fractionToChar($to_fraction($coi_l))
              . DerivativeUrlCodec::fractionToChar($to_fraction($post['t'] ?? null))
              . DerivativeUrlCodec::fractionToChar($to_fraction($post['r'] ?? null))
              . DerivativeUrlCodec::fractionToChar($to_fraction($post['b'] ?? null));
        }

        return new self(
            $image_id,
            isset($post['submit']),
            $coi,
        );
    }
}
