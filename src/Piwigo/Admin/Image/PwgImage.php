<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Plugins\EventDispatcher;

/**
 * @method bool rotate(int $rotation)
 * @method int getWidth()
 * @method int getHeight()
 * @method bool crop(int $width, int $height, int $x, int $y)
 * @method bool resize(int $width, int $height)
 * @method bool sharpen(int $amount)
 * @method bool compose(self $overlay, int $x, int $y, int $opacity)
 * @method bool strip()
 * @method bool write(string $destination_filepath)
 * @method bool setCompressionQuality(int $quality)
 */
class PwgImage
{
    /** @var ImageInterface|null */
    public $image = null;
    public string $library = '';
    public static string $ext_imagick_version = '';

    public function __construct(public string $source_filepath, ?string $library = null)
    {
        EventDispatcher::notify('load_image_library', [&$this]);

        if ($this->image !== null) {
            return; // A plugin may have load its own library
        }

        $extension = strtolower(ServiceLocator::get(StringUtil::class)->getExtension($this->source_filepath));

        if (!in_array($extension, Config::pictureExtensions())) {
            die('[Image] unsupported file extension');
        }

        $lib = self::getLibrary($library, $extension);
        if (!$lib) {
            die('No image library available on your server.');
        }
        $this->library = $lib;

        $this->image = match($this->library) {
            'gd'          => new ImageGd($this->source_filepath),
            'imagick'     => new ImageImagick($this->source_filepath),
            'ext_imagick' => new ImageExtImagick($this->source_filepath),
            default       => throw new \RuntimeException("Unknown image library: {$this->library}"),
        };
    }

    // Unknow methods will be redirected to image object
    /** @param mixed[] $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $callable = [$this->image, $method];
        if (!is_callable($callable)) {
            throw new \BadMethodCallException("Method $method does not exist on image library");
        }
        return $callable(...$arguments);
    }

    // Piwigo resize function
    /**
     * @return mixed[]
     */
    /** @return array<mixed> */
    public function pwgResize(string $destination_filepath, int $max_width, int $max_height, int $quality, bool $automatic_rotation = true, bool $strip_metadata = false, bool $crop = false, bool $follow_orientation = true): array
    {
        $starttime = ServiceLocator::get(StringUtil::class)->getMoment();

        if ($this->image === null) {
            throw new \LogicException('Image library not initialized');
        }
        // width/height
        $source_width  = $this->image->getWidth();
        $source_height = $this->image->getHeight();

        $rotation = null;
        if ($automatic_rotation) {
            $rotation = self::getRotationAngle($this->source_filepath);
        }
        $resize_dimensions = self::getResizeDimensions($source_width, $source_height, $max_width, $max_height, $rotation, $crop, $follow_orientation);

        // testing on height is useless in theory: if width is unchanged, there
        // should be no resize, because width/height ratio is not modified.
        $rd_width = $resize_dimensions['width'];
        $rd_height = $resize_dimensions['height'];
        $rd_width = is_numeric($rd_width) ? (int) $rd_width : 0;
        $rd_height = is_numeric($rd_height) ? (int) $rd_height : 0;

        if ($rd_width == $source_width and $rd_height == $source_height) {
            // the image doesn't need any resize! We just copy it to the destination
            copy($this->source_filepath, $destination_filepath);
            return $this->getResizeResult($destination_filepath, $rd_width, $rd_height, $starttime);
        }

        $this->image->setCompressionQuality($quality);

        if ($strip_metadata) {
            // we save a few kilobytes. For example a thumbnail with metadata weights 25KB, without metadata 7KB.
            $this->image->strip();
        }

        if (isset($resize_dimensions['crop']) && is_array($resize_dimensions['crop'])) {
            $crop = $resize_dimensions['crop'];
            $crop_width = $crop['width'] ?? 0;
            $crop_height = $crop['height'] ?? 0;
            $crop_x = $crop['x'] ?? 0;
            $crop_y = $crop['y'] ?? 0;
            $this->image->crop(
                is_numeric($crop_width) ? (int) $crop_width : 0,
                is_numeric($crop_height) ? (int) $crop_height : 0,
                is_numeric($crop_x) ? (int) $crop_x : 0,
                is_numeric($crop_y) ? (int) $crop_y : 0
            );
        }

        $this->image->resize($rd_width, $rd_height);

        if (!empty($rotation)) {
            $this->image->rotate($rotation);
        }

        $this->image->write($destination_filepath);

        // everything should be OK if we are here!
        return $this->getResizeResult($destination_filepath, $rd_width, $rd_height, $starttime);
    }

    /** @return array<mixed> */
    public static function getResizeDimensions(int|float $width, int|float $height, int|float $max_width, int|float $max_height, ?int $rotation = null, bool $crop = false, bool $follow_orientation = true): array
    {
        $rotate_for_dimensions = false;
        if (isset($rotation) and in_array(abs($rotation), [90, 270])) {
            $rotate_for_dimensions = true;
        }

        if ($rotate_for_dimensions) {
            [$width, $height] = [$height, $width];
        }

        if ($crop) {
            $x = 0;
            $y = 0;

            if ($width < $height and $follow_orientation) {
                [$max_width, $max_height] = [$max_height, $max_width];
            }

            $img_ratio = $width / $height;
            $dest_ratio = $max_width / $max_height;

            if ($dest_ratio > $img_ratio) {
                $destHeight = round($width * $max_height / $max_width);
                $y = round(($height - $destHeight) / 2);
                $height = $destHeight;
            } elseif ($dest_ratio < $img_ratio) {
                $destWidth = round($height * $max_width / $max_height);
                $x = round(($width - $destWidth) / 2);
                $width = $destWidth;
            }
        }

        $ratio_width  = $width / $max_width;
        $ratio_height = $height / $max_height;
        $destination_width = $width;
        $destination_height = $height;

        // maximal size exceeded ?
        if ($ratio_width > 1 or $ratio_height > 1) {
            if ($ratio_width < $ratio_height) {
                $destination_width = round($width / $ratio_height);
                $destination_height = $max_height;
            } else {
                $destination_width = $max_width;
                $destination_height = round($height / $ratio_width);
            }
        }

        if ($rotate_for_dimensions) {
            [$destination_width, $destination_height] = [$destination_height, $destination_width];
        }

        $result = [
          'width' => $destination_width,
          'height' => $destination_height,
          ];

        if ($crop and ($x or $y)) {
            $result['crop'] = [
              'width' => $width,
              'height' => $height,
              'x' => $x,
              'y' => $y,
              ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public static function webpInfo(string $source_filepath): array
    {
        // function based on https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php
        //
        // https://github.com/webmproject/libwebp/blob/master/src/dec/webp_dec.c
        // https://developers.google.com/speed/webp/docs/riff_container
        // https://developers.google.com/speed/webp/docs/webp_lossless_bitstream_specification
        // https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php

        $fp = fopen($source_filepath, 'rb');
        if (!$fp) {
            throw new \RuntimeException("webp_info(): fopen($source_filepath): Failed");
        }
        $buf = fread($fp, 25);
        fclose($fp);
        if ($buf === false) {
            throw new \RuntimeException("webp_info(): fread($source_filepath): Failed");
        }

        switch (true) {
            case strlen($buf) < 25:
            case !str_starts_with($buf, 'RIFF'):
            case substr($buf, 8, 4) != 'WEBP':
            case substr($buf, 12, 3) != 'VP8':
                throw new \RuntimeException('webp_info(): not a valid webp image');

            case $buf[15] == ' ':
                // Simple File Format (Lossy)
                return [
                  'type'            => 'VP8',
                  'has-animation'   => false,
                  'has-transparent' => false,
                ];


            case $buf[15] == 'L':
                // Simple File Format (Lossless)
                return [
                  'type'            => 'VP8L',
                  'has-animation'   => false,
                  'has-transparent' => (bool) (!!(ord($buf[24]) & 0x00000010)),
                ];

            case $buf[15] == 'X':
                // Extended File Format
                return [
                  'type'            => 'VP8X',
                  'has-animation'   => (bool) (!!(ord($buf[20]) & 0x00000002)),
                  'has-transparent' => (bool) (!!(ord($buf[20]) & 0x00000010)),
                ];

            default:
                throw new \RuntimeException('webp_info(): could not detect webp type');
        }
    }

    public static function getRotationAngle(string $source_filepath): ?int
    {
        $imgsize = getimagesize($source_filepath);
        if ($imgsize === false) {
            return null;
        }
        [$width, $height, $type] = $imgsize;
        if (IMAGETYPE_JPEG != $type) {
            return null;
        }

        if (!function_exists('exif_read_data')) {
            return null;
        }

        $rotation = 0;

        $exif = ServiceLocator::get(StringUtil::class)->pwgSafeExifReadData($source_filepath);

        if (isset($exif['Orientation']) and is_scalar($exif['Orientation']) and preg_match('/^\s*(\d)/', (string) $exif['Orientation'], $matches)) {
            $orientation = $matches[1];
            if (in_array($orientation, [3, 4])) {
                $rotation = 180;
            } elseif (in_array($orientation, [5, 6])) {
                $rotation = 270;
            } elseif (in_array($orientation, [7, 8])) {
                $rotation = 90;
            }
        }

        return $rotation;
    }

    public static function getRotationCodeFromAngle(int $rotation_angle): int
    {
        return match ($rotation_angle) {
            90  => 1,
            180 => 2,
            270 => 3,
            default => 0,
        };
    }

    public static function getRotationAngleFromCode(int $rotation_code): int
    {
        return match ($rotation_code % 4) {
            1 => 90,
            2 => 180,
            3 => 270,
            default => 0,
        };
    }

    /**
     * Returns a normalized convolution kernel for sharpening.
     * @return array<int, array<int, float>>
     */
    public static function getSharpenMatrix(int $amount): array
    {
        // Amount should be in the range of 48-10
        $amount = round(abs(-48 + ($amount * 0.38)), 2);

        $matrix = [
          [-1,   -1,    -1],
          [-1, $amount, -1],
          [-1,   -1,    -1],
          ];

        $norm = array_sum(array_map(array_sum(...), $matrix));

        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $matrix[$i][$j] /= $norm;
            }
        }

        return $matrix;
    }

    /** @return array<string,mixed> */
    protected function getResizeResult(string $destination_filepath, int|float $width, int|float $height, ?float $time = null): array
    {
        return [
          'source'      => $this->source_filepath,
          'destination' => $destination_filepath,
          'width'       => $width,
          'height'      => $height,
          'size'        => floor(filesize($destination_filepath) / 1024).' KB',
          'time'        => $time ? number_format((StringUtil::getMoment() - $time) * 1000, 2, '.', ' ').' ms' : null,
          'library'     => $this->library,
        ];
    }

    public static function isImagick(): bool
    {
        return (extension_loaded('imagick') and class_exists('Imagick'));
    }

    public static function getExtImagickCommand(): string
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }

        if (!isset($page['ext_imagick_command'])) {
            $retval = null;
            $cmd_out = null;
            // check if magick is in path (command -v is bash-only; use where.exe on Windows)
            $find_cmd = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
            exec($find_cmd.' '.Config::extImagickDir().'magick', $cmd_out, $retval);
            if (0 == $retval) {
                $page['ext_imagick_command'] = 'magick';
            } else {
                $page['ext_imagick_command'] = 'convert';
            }
        }

        return is_string($page['ext_imagick_command']) ? $page['ext_imagick_command'] : 'convert';
    }

    public static function isExtImagick(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        exec(Config::extImagickDir().self::getExtImagickCommand().' -version', $returnarray);
        if (!empty($returnarray[0]) and preg_match('/ImageMagick/i', $returnarray[0])) {
            if (preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
                self::$ext_imagick_version = $match[1];
            }
            return true;
        }
        return false;
    }

    public static function isGd(): bool
    {
        return function_exists('gd_info');
    }

    public static function getLibrary(?string $library = null, ?string $extension = null): string|false
    {
        if (is_null($library)) {
            $library = Config::graphicsLibrary();
        }

        // Choose image library
        switch (strtolower((string) $library)) {
            case 'auto':
            case 'ext_imagick':
                if ($extension != 'gif' and self::isExtImagick()) {
                    return 'ext_imagick';
                }
                // no break
            case 'imagick':
                if ($extension != 'gif' and self::isImagick()) {
                    return 'imagick';
                }
                // no break
            case 'gd':
                if (self::isGd()) {
                    return 'gd';
                }
                // no break
            default:
                if ($library != 'auto') {
                    // Requested library not available. Try another library
                    return self::getLibrary('auto', $extension);
                }
        }
        return false;
    }

    public function destroy(): bool
    {
        if ($this->image === null) {
            return true;
        }
        if (method_exists($this->image, 'destroy')) {
            return (bool)$this->image->destroy();
        }
        return true;
    }
}
