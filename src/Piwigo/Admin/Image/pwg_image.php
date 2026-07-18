<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Image;

/**
 * Unknown methods are forwarded to $this->image (an imageInterface
 * implementor) via __call(). These @method tags mirror that interface.
 *
 * @method int|float get_width()
 * @method int|float get_height()
 * @method bool set_compression_quality(int $quality)
 * @method bool crop(int|float $width, int|float $height, int|float $x, int|float $y)
 * @method bool strip()
 * @method bool rotate(int|float $rotation)
 * @method bool resize(int|float $width, int|float $height)
 * @method bool sharpen(int|float $amount)
 * @method bool compose(pwg_image $overlay, int|float $x, int|float $y, int|float $opacity)
 * @method bool write(string $destination_filepath)
 */
class pwg_image
{
    /**
     * @var imageInterface|null null until either a 'load_image_library'
     *   event listener sets it (see the trigger_notify() call in
     *   __construct()) or __construct() itself instantiates the chosen
     *   library class
     */
    public ?imageInterface $image = null;

    /**
     * @var string|false false is only ever transient: get_library() can
     *   return false, but __construct() dies() immediately when that
     *   happens, so a successfully constructed instance always holds a
     *   non-empty string here
     */
    public string|false $library = '';

    public static string $ext_imagick_version = '';

    public function __construct(
        public string $source_filepath,
        ?string $library = null
    ) {

        trigger_notify('load_image_library', [&$this]);

        if (is_object($this->image)) {
            return; // A plugin may have load its own library
        }

        $extension = strtolower(\Piwigo\Core\StringHelper::getExtension($this->source_filepath));

        $picture_ext = is_array(\Piwigo\Config\Config::pictureExtensions()) ? \Piwigo\Config\Config::pictureExtensions() : [];
        if (! in_array($extension, $picture_ext)) {
            die('[Image] unsupported file extension');
        }

        if (! (bool) ($this->library = self::get_library($library, $extension))) {
            die('No image library available on your server.');
        }

        $this->image = match ($this->library) {
            'ext_imagick' => new image_ext_imagick($this->source_filepath),
            'imagick' => new image_imagick($this->source_filepath),
            'gd' => new image_gd($this->source_filepath),
            default => throw new \Exception("pwg_image: unknown image library '{$this->library}'"),
        };
    }

    // Unknow methods will be redirected to image object
    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $image = $this->getImage();
        return $image->{$method}(...$arguments);
    }

    /**
     * Narrows $image from imageInterface|null to imageInterface. By the
     * time any method other than __construct() runs, $image is always
     * set — either by a 'load_image_library' listener or by
     * __construct() itself (which die()s if no library is available).
     */
    private function getImage(): imageInterface
    {
        if (! $this->image instanceof imageInterface) {
            throw new \LogicException('pwg_image: no image library instantiated');
        }
        return $this->image;
    }

    // Piwigo resize function
    /**
     * @return array{source: string, destination: string, width: int|float, height: int|float, size: string, time: string|null, library: string|false}
     */
    public function pwg_resize(string $destination_filepath, int $max_width, int $max_height, int $quality, bool $automatic_rotation = true, bool $strip_metadata = false, bool $crop = false, bool $follow_orientation = true): array
    {
        $starttime = \Piwigo\Core\TimingHelper::getMoment();
        $image = $this->getImage();

        // width/height
        $source_width = $image->get_width();
        $source_height = $image->get_height();

        $rotation = null;
        if ($automatic_rotation) {
            $rotation = self::get_rotation_angle($this->source_filepath);
        }
        $resize_dimensions = self::get_resize_dimensions($source_width, $source_height, $max_width, $max_height, $rotation, $crop, $follow_orientation);

        // testing on height is useless in theory: if width is unchanged, there
        // should be no resize, because width/height ratio is not modified.
        if ($resize_dimensions['width'] == $source_width and $resize_dimensions['height'] == $source_height) {
            // the image doesn't need any resize! We just copy it to the destination
            copy($this->source_filepath, $destination_filepath);
            return $this->get_resize_result($destination_filepath, $resize_dimensions['width'], $resize_dimensions['height'], $starttime);
        }

        $image->set_compression_quality($quality);

        if ($strip_metadata) {
            // we save a few kilobytes. For example a thumbnail with metadata weights 25KB, without metadata 7KB.
            $image->strip();
        }

        if (isset($resize_dimensions['crop'])) {
            $image->crop($resize_dimensions['crop']['width'], $resize_dimensions['crop']['height'], $resize_dimensions['crop']['x'], $resize_dimensions['crop']['y']);
        }

        $image->resize($resize_dimensions['width'], $resize_dimensions['height']);

        if (! empty($rotation)) {
            $image->rotate($rotation);
        }

        $image->write($destination_filepath);

        // everything should be OK if we are here!
        return $this->get_resize_result($destination_filepath, $resize_dimensions['width'], $resize_dimensions['height'], $starttime);
    }

    /**
     * @return array{width: int|float, height: int|float, crop?: array{width: int|float, height: int|float, x: int|float, y: int|float}}
     */
    public static function get_resize_dimensions(int|float $width, int|float $height, int $max_width, int $max_height, ?int $rotation = null, bool $crop = false, bool $follow_orientation = true): array
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

        $ratio_width = $width / $max_width;
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

        if ($crop and ((bool) $x or (bool) $y)) {
            $result['crop'] = [
                'width' => $width,
                'height' => $height,
                'x' => $x,
                'y' => $y,
            ];
        }
        return $result;
    }

    /**
     * @return array{type: string, has-animation: bool, has-transparent: bool}
     */
    public static function webp_info(string $source_filepath): array
    {
        // function based on https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php
        //
        // https://github.com/webmproject/libwebp/blob/master/src/dec/webp_dec.c
        // https://developers.google.com/speed/webp/docs/riff_container
        // https://developers.google.com/speed/webp/docs/webp_lossless_bitstream_specification
        // https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php

        $fp = fopen($source_filepath, 'rb');
        if (! (bool) $fp) {
            throw new \Exception("webp_info(): fopen({$source_filepath}): Failed");
        }
        $buf = fread($fp, 25);
        fclose($fp);

        if ($buf === false) {
            throw new \Exception("webp_info(): fread({$source_filepath}): Failed");
        }

        switch (true) {
            case strlen($buf) < 25:
            case ! str_starts_with($buf, 'RIFF'):
            case substr($buf, 8, 4) != 'WEBP':
            case substr($buf, 12, 3) != 'VP8':
                throw new \Exception('webp_info(): not a valid webp image');
            case $buf[15] == ' ':
                // Simple File Format (Lossy)
                return [
                    'type' => 'VP8',
                    'has-animation' => false,
                    'has-transparent' => false,
                ];

            case $buf[15] == 'L':
                // Simple File Format (Lossless)
                return [
                    'type' => 'VP8L',
                    'has-animation' => false,
                    'has-transparent' => (bool) (ord($buf[24]) & 0x00000010),
                ];

            case $buf[15] == 'X':
                // Extended File Format
                return [
                    'type' => 'VP8X',
                    'has-animation' => (bool) (ord($buf[20]) & 0x00000002),
                    'has-transparent' => (bool) (ord($buf[20]) & 0x00000010),
                ];

            default:
                throw new \Exception('webp_info(): could not detect webp type');
        }
    }

    public static function get_rotation_angle(string $source_filepath): ?int
    {
        $size = getimagesize($source_filepath);
        if ($size === false) {
            throw new \Exception("get_rotation_angle(): getimagesize({$source_filepath}): Failed");
        }
        [$width, $height, $type] = $size;
        if ($type != IMAGETYPE_JPEG) {
            return null;
        }

        if (! function_exists('exif_read_data')) {
            return null;
        }

        $rotation = 0;

        $exif = @exif_read_data($source_filepath);

        $exif_orientation = is_array($exif) && isset($exif['Orientation']) && is_scalar($exif['Orientation'])
            ? (string) $exif['Orientation']
            : null;

        if ($exif_orientation !== null and (bool) preg_match('/^\s*(\d)/', $exif_orientation, $matches)) {
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

    public static function get_rotation_code_from_angle(?int $rotation_angle): int
    {
        return match ($rotation_angle) {
            // null (no EXIF orientation / non-JPEG source, per
            // get_rotation_angle()) means "no rotation", same as 0.
            null, 0 => 0,
            90 => 1,
            180 => 2,
            270 => 3,
            default => throw new \Exception("get_rotation_code_from_angle(): unexpected rotation angle {$rotation_angle}"),
        };
    }

    /**
     * @param int|numeric-string $rotation_code i.php's only caller passes
     *   $row['rotation'] straight from a mysqli fetch_assoc() result, which
     *   comes back as a numeric string (confirmed empirically against the
     *   real test DB — this driver does not use native int/float fetching)
     */
    public static function get_rotation_angle_from_code(int|string $rotation_code): int
    {
        return match ($rotation_code % 4) {
            0 => 0,
            1 => 90,
            2 => 180,
            3 => 270,
            default => throw new \Exception("get_rotation_angle_from_code(): unexpected rotation code {$rotation_code}"),
        };
    }

    /**
     * Returns a normalized convolution kernel for sharpening
     *
     * @return array<int, array<int, int|float>>
     */
    public static function get_sharpen_matrix(int|float $amount): array
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

    /**
     * @return array{source: string, destination: string, width: int|float, height: int|float, size: string, time: string|null, library: string|false}
     */
    protected function get_resize_result(string $destination_filepath, int|float $width, int|float $height, ?float $time = null): array
    {
        // this is purely diagnostic/log output -- fall back to 0 KB rather
        // than throwing if the destination somehow isn't readable.
        $destination_filesize = filesize($destination_filepath);
        $destination_filesize = $destination_filesize !== false ? $destination_filesize : 0;
        return [
            'source' => $this->source_filepath,
            'destination' => $destination_filepath,
            'width' => $width,
            'height' => $height,
            'size' => floor($destination_filesize / 1024) . ' KB',
            'time' => ((bool) $time) ? number_format((\Piwigo\Core\TimingHelper::getMoment() - $time) * 1000, 2, '.', ' ') . ' ms' : null,
            'library' => $this->library,
        ];
    }

    public static function is_imagick(): bool
    {
        return extension_loaded('imagick') and class_exists('Imagick');
    }

    public static function get_ext_imagick_command(): string
    {
        // Per-process memoization of an exec() probe -- a static local
        // rather than a class property since this static method has no
        // instance to hang state off of.
        static $command = null;

        if (! is_string($command)) {
            $retval = null;
            $cmd_out = null;
            $imagick_dir = \Piwigo\Config\Config::extImagickDir();
            // check if magick is in path
            // [SEC-16] escapeshellarg() quotes the dir prefix; the adjacent
            // quoted+unquoted shell tokens still concatenate into one word
            // (e.g. '/usr/bin/'magick), so this stays functionally identical.
            exec('command -v ' . escapeshellarg($imagick_dir) . 'magick', $cmd_out, $retval);
            $command = ($retval == 0) ? 'magick' : 'convert';
        }

        return $command;
    }

    public static function is_ext_imagick(): bool
    {

        if (! function_exists('exec')) {
            return false;
        }

        $imagick_dir = \Piwigo\Config\Config::extImagickDir();
        // [SEC-16] see the escapeshellarg() note above.
        @exec(escapeshellarg($imagick_dir) . self::get_ext_imagick_command() . ' -version', $returnarray);
        if (! empty($returnarray[0]) and (bool) preg_match('/ImageMagick/i', $returnarray[0])) {
            if ((bool) preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
                self::$ext_imagick_version = $match[1];
            }
            return true;
        }
        return false;
    }

    public static function is_gd(): bool
    {
        return function_exists('gd_info');
    }

    public static function get_library(?string $library = null, ?string $extension = null): string|false
    {

        if ($library === null) {
            $conf_library = \Piwigo\Config\Config::graphicsLibrary();
            $library = $conf_library;
        }

        // Choose image library
        switch (strtolower($library)) {
            case 'auto':
            case 'ext_imagick':
                if ($extension != 'gif' and self::is_ext_imagick()) {
                    return 'ext_imagick';
                }
                // no break
            case 'imagick':
                if ($extension != 'gif' and self::is_imagick()) {
                    return 'imagick';
                }
                // no break
            case 'gd':
                if (self::is_gd()) {
                    return 'gd';
                }
                // no break
            default:
                if ($library != 'auto') {
                    // Requested library not available. Try another library
                    return self::get_library('auto', $extension);
                }
        }
        return false;
    }

    /**
     * P23 batch 8d: ported from admin/include/functions.php's
     * get_graphics_library() -- a natural extension of this class's own
     * library-detection concern (is_imagick()/is_ext_imagick()/is_gd()/
     * get_library() above), appending a version-string suffix on top of
     * get_library()'s bare identifier.
     */
    public static function get_graphics_library(): string|false
    {

        $library = self::get_library();

        switch ($library) {
            case 'ext_imagick':
                $ext_imagick_dir = \Piwigo\Config\Config::extImagickDir();
                exec($ext_imagick_dir . self::get_ext_imagick_command() . ' -version', $returnarray);
                if ((bool) preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
                    $library .= '/' . $match[1];
                }
                break;

            case 'imagick':
                $version = \Imagick::getVersion();
                if ((bool) preg_match('/ImageMagick \d+\.\d+\.\d+-?\d*/', $version['versionString'], $match)) {
                    $library .= '/' . $match[0];
                }
                break;

            case 'gd':
                $gd_info = gd_info();
                $gd_version = $gd_info['GD Version'] ?? null;
                $gd_version = is_string($gd_version) ? $gd_version : '';
                $library .= '/' . $gd_version;
                break;
        }

        return $library;
    }

    public static function get_graphics_library_label(): string
    {
        [$library_code, $library_version] = explode('/', (string) self::get_graphics_library());

        $label_for_lib = [
            'imagick' => 'ImageMagick',
            'ext_imagick' => 'External ImageMagick',
            'gd' => 'GD',
        ];

        return $label_for_lib[$library_code] . ' ' . $library_version;
    }

    public function destroy(): bool
    {
        $image = $this->getImage();
        if (method_exists($image, 'destroy')) {
            // $image's static type is imageInterface, which doesn't declare
            // destroy() (only image_gd implements it; a plugin-provided
            // backend loaded via the 'load_image_library' event may also
            // implement it, per __construct()'s comment) — method_exists()
            // proves the call is safe but its return stays mixed to PHPStan.
            return (bool) $image->destroy();
        }
        return true;
    }
}
