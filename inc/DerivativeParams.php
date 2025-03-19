<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * All needed parameters to generate a derivative image.
 */
final class DerivativeParams
{
    public SizingParams $sizing;

    /**
     * among IMG_*
     */
    public string $type = derivative_std_params::IMG_CUSTOM;

    /**
     * used for non-custom images to regenerate the cached files
     */
    public int $last_mod_time = 0;

    public bool $use_watermark = false;

    /**
     * from 0=no sharpening to 1=max sharpening
     */
    public int|float $sharpen = 0;

    public function __construct(
        SizingParams $sizing
    ) {
        $this->sizing = $sizing;
    }

    public function __sleep(): array
    {
        return ['last_mod_time', 'sizing', 'sharpen'];
    }

    /**
     * Adds tokens depending on sizing configuration.
     */
    public function add_url_tokens(
        array &$tokens
    ): void {
        $this->sizing->add_url_tokens($tokens);
    }

    /**
     * @return array<int>
     */
    public function compute_final_size(
        array $in_size
    ): array {
        $this->sizing->compute($in_size, null, $crop_rect, $scale_size);
        return $scale_size != null ? $scale_size : $in_size;
    }

    public function max_width(): int
    {
        return $this->sizing->ideal_size[0];
    }

    public function max_height(): int
    {
        return $this->sizing->ideal_size[1];
    }

    /**
     * @todo : description of DerivativeParams::is_identity
     */
    public function is_identity(
        array $in_size
    ): bool {
        if ($in_size[0] > $this->sizing->ideal_size[0] or
            $in_size[1] > $this->sizing->ideal_size[1]
        ) {
            return false;
        }

        return true;
    }

    public function will_watermark(
        array $out_size
    ): bool {
        if ($this->use_watermark) {
            $min_size = ImageStdParams::get_watermark()->min_size;
            return $min_size[0] <= $out_size[0] ||
                   $min_size[1] <= $out_size[1];
        }

        return false;
    }
}
