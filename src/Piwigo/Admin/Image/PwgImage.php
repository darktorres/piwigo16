<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Image;

use Exception;
use Imagick;
use LogicException;
use Piwigo\Admin\Image\Projection\ResizeCrop;
use Piwigo\Admin\Image\Projection\ResizeDimensions;
use Piwigo\Admin\Image\Projection\ResizeResult;
use Piwigo\Admin\Image\Projection\WebpInfo;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\StringHelper;
use Piwigo\Core\TimingHelper;
use Piwigo\Event\Lifecycle\LoadImageLibrary;
use Piwigo\PluginConfig\EventDispatcher;

final class PwgImage
{
    /**
     * @var ImageInterface|null null until either a 'load_image_library'
     *   event listener sets it (see the trigger_notify() call in
     *   __construct()) or __construct() itself instantiates the chosen
     *   library class
     */
    public ?ImageInterface $image = null;

    /**
     * @var string|false false is only ever transient: get_library() can
     *   return false, but __construct() throws ImageProcessingException
     *   immediately when that happens, so a successfully constructed
     *   instance always holds a non-empty string here
     */
    public string|false $library = '';

    public static string $ext_imagick_version = '';

    public function __construct(
        public string $source_filepath,
        private readonly CurrentLogger $currentLogger,
        EventDispatcher $eventDispatcher,
        private readonly CurrentConfig $currentConfig,
        ?string $library = null
    ) {

        $eventDispatcher->dispatchNotify(new LoadImageLibrary($this));

        if (is_object($this->image)) {
            return; // A plugin may have load its own library
        }

        $extension = strtolower(StringHelper::getExtension($this->source_filepath));

        $picture_ext = $this->currentConfig->pictureExtensions();
        if (! in_array($extension, $picture_ext, true)) {
            throw new ImageProcessingException('[Image] unsupported file extension');
        }

        if (! (bool) ($this->library = self::get_library($library, $extension))) {
            throw new ImageProcessingException('No image library available on your server.');
        }

        $this->image = match ($this->library) {
            'ext_imagick' => new ImageExtImagick($this->source_filepath, $this->currentLogger, $this->currentConfig),
            'imagick' => new ImageImagick($this->source_filepath),
            'gd' => new ImageGd($this->source_filepath),
            default => throw new Exception("PwgImage: unknown image library '{$this->library}'"),
        };
    }

    // Delegate methods for ImageInterface -- previously forwarded
    // dynamically via __call(), which PHPStan can't type-check (an
    // arbitrary $method string against an arbitrary backend). Explicit
    // one-liners here give real signature checking for the exact same
    // public surface real callers already use (e.g. tests/Unit/Admin/
    // Image/PwgImageTest.php's `$img->get_width()`).
    public function get_width(): int|float
    {
        return $this->getImage()
            ->get_width();
    }

    public function get_height(): int|float
    {
        return $this->getImage()
            ->get_height();
    }

    public function set_compression_quality(int $quality): bool
    {
        return $this->getImage()
            ->set_compression_quality($quality);
    }

    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        return $this->getImage()
            ->crop($width, $height, $x, $y);
    }

    public function strip(): bool
    {
        return $this->getImage()
            ->strip();
    }

    public function rotate(int|float $rotation): bool
    {
        return $this->getImage()
            ->rotate($rotation);
    }

    public function resize(int|float $width, int|float $height): bool
    {
        return $this->getImage()
            ->resize($width, $height);
    }

    public function sharpen(int|float $amount): bool
    {
        return $this->getImage()
            ->sharpen($amount);
    }

    public function compose(self $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        return $this->getImage()
            ->compose($overlay, $x, $y, $opacity);
    }

    public function write(string $destination_filepath): bool
    {
        return $this->getImage()
            ->write($destination_filepath);
    }

    /**
     * Narrows $image from ImageInterface|null to ImageInterface. By the
     * time any method other than __construct() runs, $image is always
     * set — either by a 'load_image_library' listener or by
     * __construct() itself (which throws ImageProcessingException if no
     * library is available).
     */
    private function getImage(): ImageInterface
    {
        if (! $this->image instanceof ImageInterface) {
            throw new LogicException('PwgImage: no image library instantiated');
        }
        return $this->image;
    }

    // Piwigo resize function
    public function pwg_resize(string $destination_filepath, int $max_width, int $max_height, int $quality, bool $automatic_rotation = true, bool $strip_metadata = false, bool $crop = false, bool $follow_orientation = true): ResizeResult
    {
        $starttime = TimingHelper::getMoment();
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
        if ((float) $resize_dimensions->width === (float) $source_width and (float) $resize_dimensions->height === (float) $source_height) {
            // the image doesn't need any resize! We just copy it to the destination
            copy($this->source_filepath, $destination_filepath);
            return $this->get_resize_result($destination_filepath, $resize_dimensions->width, $resize_dimensions->height, $starttime);
        }

        $image->set_compression_quality($quality);

        if ($strip_metadata) {
            // we save a few kilobytes. For example a thumbnail with metadata weights 25KB, without metadata 7KB.
            $image->strip();
        }

        if ($resize_dimensions->crop !== null) {
            $image->crop($resize_dimensions->crop->width, $resize_dimensions->crop->height, $resize_dimensions->crop->x, $resize_dimensions->crop->y);
        }

        $image->resize($resize_dimensions->width, $resize_dimensions->height);

        if ($rotation !== null && $rotation !== 0) {
            $image->rotate($rotation);
        }

        $image->write($destination_filepath);

        // everything should be OK if we are here!
        return $this->get_resize_result($destination_filepath, $resize_dimensions->width, $resize_dimensions->height, $starttime);
    }

    public static function get_resize_dimensions(int|float $width, int|float $height, int $max_width, int $max_height, ?int $rotation = null, bool $crop = false, bool $follow_orientation = true): ResizeDimensions
    {
        $rotate_for_dimensions = false;
        if (isset($rotation) and in_array(abs($rotation), [90, 270], true)) {
            $rotate_for_dimensions = true;
        }

        if ($rotate_for_dimensions) {
            [$width, $height] = [$height, $width];
        }

        // Only ever read below when $crop is true (where they're also
        // assigned) -- declared here so Psalm can see they're always
        // defined by the time they're used, ~50 lines further down.
        $x = 0;
        $y = 0;

        if ($crop) {
            if ($width < $height and $follow_orientation) {
                [$max_width, $max_height] = [$max_height, $max_width];
            }

            $img_ratio = (float) $width / (float) $height;
            $dest_ratio = (float) $max_width / (float) $max_height;

            if ($dest_ratio > $img_ratio) {
                $destHeight = round((float) $width * (float) $max_height / (float) $max_width);
                $y = round(((float) $height - $destHeight) / 2.0);
                $height = $destHeight;
            } elseif ($dest_ratio < $img_ratio) {
                $destWidth = round((float) $height * (float) $max_width / (float) $max_height);
                $x = round(((float) $width - $destWidth) / 2.0);
                $width = $destWidth;
            }
        }

        $ratio_width = (float) $width / (float) $max_width;
        $ratio_height = (float) $height / (float) $max_height;
        $destination_width = $width;
        $destination_height = $height;

        // maximal size exceeded ?
        if ($ratio_width > 1 or $ratio_height > 1) {
            if ($ratio_width < $ratio_height) {
                $destination_width = round((float) $width / $ratio_height);
                $destination_height = $max_height;
            } else {
                $destination_width = $max_width;
                $destination_height = round((float) $height / $ratio_width);
            }
        }

        if ($rotate_for_dimensions) {
            [$destination_width, $destination_height] = [$destination_height, $destination_width];
        }

        $cropResult = null;
        if ($crop and ((bool) $x or (bool) $y)) {
            $cropResult = new ResizeCrop($width, $height, $x, $y);
        }

        return new ResizeDimensions($destination_width, $destination_height, $cropResult);
    }

    public static function webp_info(string $source_filepath): WebpInfo
    {
        // function based on https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php
        //
        // https://github.com/webmproject/libwebp/blob/master/src/dec/webp_dec.c
        // https://developers.google.com/speed/webp/docs/riff_container
        // https://developers.google.com/speed/webp/docs/webp_lossless_bitstream_specification
        // https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php

        $fp = fopen($source_filepath, 'rb');
        if (! (bool) $fp) {
            throw new Exception("webp_info(): fopen({$source_filepath}): Failed");
        }
        $buf = fread($fp, 25);
        fclose($fp);

        if ($buf === false) {
            throw new Exception("webp_info(): fread({$source_filepath}): Failed");
        }

        switch (true) {
            case strlen($buf) < 25:
            case ! str_starts_with($buf, 'RIFF'):
            case substr($buf, 8, 4) !== 'WEBP':
            case substr($buf, 12, 3) !== 'VP8':
                throw new Exception('webp_info(): not a valid webp image');
            case $buf[15] === ' ':
                // Simple File Format (Lossy)
                return new WebpInfo('VP8', false, false);

            case $buf[15] === 'L':
                // Simple File Format (Lossless)
                return new WebpInfo('VP8L', false, (bool) (ord($buf[24]) & 0x00000010));

            case $buf[15] === 'X':
                // Extended File Format
                return new WebpInfo('VP8X', (bool) (ord($buf[20]) & 0x00000002), (bool) (ord($buf[20]) & 0x00000010));

            default:
                throw new Exception('webp_info(): could not detect webp type');
        }
    }

    public static function get_rotation_angle(string $source_filepath): ?int
    {
        $size = getimagesize($source_filepath);
        if ($size === false) {
            // Not decodable as an image at all (e.g. a non-picture file
            // uploaded via CurrentConfig::uploadFormAllTypes()) -- no
            // rotation is knowable, same as the "not a JPEG" case just
            // below, which this is a strict superset of. No caller of this
            // method catches an exception from it (confirmed via a full
            // grep of every real call site), so throwing here was a latent
            // crash, not a deliberate validation gate.
            return null;
        }
        [$width, $height, $type] = $size;
        if ($type !== IMAGETYPE_JPEG) {
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
            if (in_array($orientation, ['3', '4'], true)) {
                $rotation = 180;
            } elseif (in_array($orientation, ['5', '6'], true)) {
                $rotation = 270;
            } elseif (in_array($orientation, ['7', '8'], true)) {
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
            default => throw new Exception("get_rotation_code_from_angle(): unexpected rotation angle {$rotation_angle}"),
        };
    }

    /**
     * @param int|numeric-string $rotation_code ImageDerivativeController's
     *   only caller passes $row->rotation, a native ?int hydrated by
     *   Doctrine ORM from ImageEntity's smallint `rotation` column --
     *   not a mysqli-fetched numeric string
     */
    public static function get_rotation_angle_from_code(int|string $rotation_code): int
    {
        return match ((int) $rotation_code % 4) {
            0 => 0,
            1 => 90,
            2 => 180,
            3 => 270,
            default => throw new Exception("get_rotation_angle_from_code(): unexpected rotation code {$rotation_code}"),
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
        $amount = round(abs(-48.0 + ((float) $amount * 0.38)), 2);

        $matrix = [
            [-1.0,   -1.0,    -1.0],
            [-1.0, $amount, -1.0],
            [-1.0,   -1.0,    -1.0],
        ];

        $norm = array_sum(array_map(array_sum(...), $matrix));

        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $matrix[$i][$j] /= $norm;
            }
        }

        return $matrix;
    }

    private function get_resize_result(string $destination_filepath, int|float $width, int|float $height, ?float $time = null): ResizeResult
    {
        // this is purely diagnostic/log output -- fall back to 0 KB rather
        // than throwing if the destination somehow isn't readable.
        $destination_filesize = filesize($destination_filepath);
        $destination_filesize = $destination_filesize !== false ? $destination_filesize : 0;
        return new ResizeResult(
            source: $this->source_filepath,
            destination: $destination_filepath,
            width: $width,
            height: $height,
            size: (string) floor($destination_filesize / 1024) . ' KB',
            time: ((bool) $time) ? number_format((TimingHelper::getMoment() - $time) * 1000.0, 2, '.', ' ') . ' ms' : null,
            library: $this->library,
        );
    }

    public static function is_imagick(): bool
    {
        return extension_loaded('imagick') and class_exists('Imagick');
    }

    /**
     * get_ext_imagick_command()/is_ext_imagick()/get_library()/
     * get_graphics_library() below are all `public static`, with no
     * instance available for real constructor injection -- resolves the
     * container-shared instance directly instead, same "container
     * resolve, not a constructor property" shape as
     * Upload\UploadService::currentConfig(). Falls back to a cheap,
     * fresh-per-call `new CurrentConfig()` pre-boot -- no DB read at all.
     */
    private static function currentConfig(): CurrentConfig
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(CurrentConfig::class);
            if (! $instance instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }

            return $instance;
        }

        return new CurrentConfig();
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
            $imagick_dir = self::currentConfig()->extImagickDir();
            // check if magick is in path
            // [SEC-16] escapeshellarg() quotes the dir prefix; the adjacent
            // quoted+unquoted shell tokens still concatenate into one word
            // (e.g. '/usr/bin/'magick), so this stays functionally identical.
            exec('command -v ' . escapeshellarg($imagick_dir) . 'magick', $cmd_out, $retval);
            $command = ($retval === 0) ? 'magick' : 'convert';
        }

        return $command;
    }

    public static function is_ext_imagick(): bool
    {

        if (! function_exists('exec')) {
            return false;
        }

        $imagick_dir = self::currentConfig()->extImagickDir();
        // [SEC-16] see the escapeshellarg() note above. "< /dev/null" --
        // same stdin-hang guard as ImageExtImagick's own identify/convert
        // calls.
        $returnarray = [];
        @exec(escapeshellarg($imagick_dir) . self::get_ext_imagick_command() . ' -version < /dev/null', $returnarray);
        if (isset($returnarray[0]) && $returnarray[0] !== '' && $returnarray[0] !== '0' and (bool) preg_match('/ImageMagick/i', $returnarray[0])) {
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
            $conf_library = self::currentConfig()->graphicsLibrary();
            $library = $conf_library;
        }

        // Choose image library
        switch (strtolower($library)) {
            case 'auto':
            case 'ext_imagick':
                if ($extension !== 'gif' and self::is_ext_imagick()) {
                    return 'ext_imagick';
                }
                // no break
            case 'imagick':
                if ($extension !== 'gif' and self::is_imagick()) {
                    return 'imagick';
                }
                // no break
            case 'gd':
                if (self::is_gd()) {
                    return 'gd';
                }
                // no break
            default:
                if ($library !== 'auto') {
                    // Requested library not available. Try another library
                    return self::get_library('auto', $extension);
                }
        }
        return false;
    }

    /**
     * Extends this class's own library-detection concern
     * (is_imagick()/is_ext_imagick()/is_gd()/get_library() above),
     * appending a version-string suffix on top of get_library()'s bare
     * identifier.
     */
    public static function get_graphics_library(): string|false
    {

        $library = self::get_library();

        switch ($library) {
            case 'ext_imagick':
                $ext_imagick_dir = self::currentConfig()->extImagickDir();
                $returnarray = [];
                exec($ext_imagick_dir . self::get_ext_imagick_command() . ' -version', $returnarray);
                if ((bool) preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0] ?? '', $match)) {
                    $library .= '/' . $match[1];
                }
                break;

            case 'imagick':
                $version = Imagick::getVersion();
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
            // $image's static type is ImageInterface, which doesn't declare
            // destroy() (only ImageGd implements it; a plugin-provided
            // backend loaded via the 'load_image_library' event may also
            // implement it, per __construct()'s comment) — method_exists()
            // proves the call is safe but its return stays mixed to PHPStan.
            return (bool) $image->destroy();
        }
        return true;
    }
}
