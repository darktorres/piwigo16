<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * All needed parameters to generate a derivative image.
 */
final class DerivativeParams
{
    /** @var string among IMG_* */
    public $type = DerivativeSize::Custom->value;
    /** @var int used for non-custom images to regenerate the cached files */
    public $last_mod_time = 0;
    /** @var bool */
    public $use_watermark = false;
    /** @var float from 0=no sharpening to 1=max sharpening */
    public $sharpen = 0;

    /**
     * @param SizingParams $sizing
     */
    public function __construct(public $sizing)
    {
    }

    public function __serialize(): array
    {
        return ['last_mod_time' => $this->last_mod_time, 'sizing' => $this->sizing, 'sharpen' => $this->sharpen];
    }

    /**
     * @return array{type: string, last_mod_time: int, sharpen: float, sizing: array{ideal_size: array{int, int}, max_crop: float, min_size: array{int, int}|null}}
     */
    public function toArray(): array
    {
        return [
            'type'          => $this->type,
            'last_mod_time' => $this->last_mod_time,
            'sharpen'       => $this->sharpen,
            'sizing'        => $this->sizing->toArray(),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        /** @var array<string, mixed> $sizingRow */
        $sizingRow = is_array($row['sizing'] ?? null) ? $row['sizing'] : [];
        $params = new self(SizingParams::fromArray($sizingRow));
        $params->type          = is_string($row['type'] ?? null) ? $row['type'] : DerivativeSize::Custom->value;
        $params->last_mod_time = is_numeric($row['last_mod_time'] ?? null) ? (int) $row['last_mod_time'] : 0;
        $params->sharpen       = is_numeric($row['sharpen'] ?? null) ? (float) $row['sharpen'] : 0.0;
        return $params;
    }

    /**
     * Adds tokens depending on sizing configuration.
     *
     * @param array &$tokens
     */
    /** @param array<int|string> $tokens */
    public function addUrlTokens(array &$tokens): void
    {
        $this->sizing->addUrlTokens($tokens);
    }

    /**
     * @return int[]
     */
    /**
 * @param array<int|float> $in_size
 * @return array<int|float>
 */
    public function computeFinalSize(array $in_size): array
    {
        $this->sizing->compute($in_size, null, $crop_rect, $scale_size);
        return $scale_size != null ? $scale_size : $in_size;
    }

    /**
     * @return int
     */
    public function maxWidth()
    {
        return $this->sizing->ideal_size[0];
    }

    /**
     * @return int
     */
    public function maxHeight()
    {
        return $this->sizing->ideal_size[1];
    }

    /**
     * @todo : description of DerivativeParams::is_identity
     */
    /** @param array<int|float> $in_size */
    public function isIdentity(array $in_size): bool
    {
        if ($in_size[0] > $this->sizing->ideal_size[0] or
            $in_size[1] > $this->sizing->ideal_size[1]) {
            return false;
        }
        return true;
    }

    /**
     * @return bool
     */
    /** @param array<int|float> $out_size */
    public function willWatermark(array $out_size): bool
    {
        if ($this->use_watermark) {
            $min_size = ImageStdParams::getWatermark()->min_size;
            return $min_size[0] <= $out_size[0]
              || $min_size[1] <= $out_size[1];
        }
        return false;
    }
}
