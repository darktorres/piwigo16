<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           Image Interface                             |
// +-----------------------------------------------------------------------+

// Define all needed methods for image class
interface imageInterface
{
    public function get_width(): int|float;

    public function get_height(): int|float;

    public function set_compression_quality(int $quality): bool;

    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool;

    public function strip(): bool;

    public function rotate(int|float $rotation): bool;

    public function resize(int|float $width, int|float $height): bool;

    public function sharpen(int|float $amount): bool;

    public function compose(pwg_image $overlay, int|float $x, int|float $y, int|float $opacity): bool;

    public function write(string $destination_filepath): bool;
}

// +-----------------------------------------------------------------------+
// |                          Main Image Class                             |
// +-----------------------------------------------------------------------+

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
        /** @var array<string, mixed> $conf */
        global $conf;

        trigger_notify('load_image_library', [&$this]);

        if (is_object($this->image)) {
            return; // A plugin may have load its own library
        }

        $extension = strtolower(get_extension($this->source_filepath));

        $picture_ext = is_array($conf['picture_ext']) ? $conf['picture_ext'] : [];
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
            default => throw new Exception("pwg_image: unknown image library '{$this->library}'"),
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
        if (! $this->image instanceof \imageInterface) {
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
        $starttime = get_moment();
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
            case substr($buf, 8, 4) != 'WEBP':
            case substr($buf, 12, 3) != 'VP8':
                throw new Exception('webp_info(): not a valid webp image');
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
                throw new Exception('webp_info(): could not detect webp type');
        }
    }

    public static function get_rotation_angle(string $source_filepath): ?int
    {
        $size = getimagesize($source_filepath);
        if ($size === false) {
            throw new Exception("get_rotation_angle(): getimagesize({$source_filepath}): Failed");
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
            default => throw new Exception("get_rotation_code_from_angle(): unexpected rotation angle {$rotation_angle}"),
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
        return [
            'source' => $this->source_filepath,
            'destination' => $destination_filepath,
            'width' => $width,
            'height' => $height,
            'size' => floor(filesize($destination_filepath) / 1024) . ' KB',
            'time' => ((bool) $time) ? number_format((get_moment() - $time) * 1000, 2, '.', ' ') . ' ms' : null,
            'library' => $this->library,
        ];
    }

    public static function is_imagick(): bool
    {
        return extension_loaded('imagick') and class_exists('Imagick');
    }

    public static function get_ext_imagick_command(): string
    {
        /**
         * @var array<string, mixed> $page
         * @var array<string, mixed> $conf
         */
        global $page, $conf;

        $command = $page['ext_imagick_command'] ?? null;
        if (! is_string($command)) {
            $retval = null;
            $cmd_out = null;
            $imagick_dir = is_string($conf['ext_imagick_dir']) ? $conf['ext_imagick_dir'] : '';
            // check if magick is in path
            exec('command -v ' . $imagick_dir . 'magick', $cmd_out, $retval);
            $command = ($retval == 0) ? 'magick' : 'convert';
            $page['ext_imagick_command'] = $command;
        }

        return $command;
    }

    public static function is_ext_imagick(): bool
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (! function_exists('exec')) {
            return false;
        }

        $imagick_dir = is_string($conf['ext_imagick_dir']) ? $conf['ext_imagick_dir'] : '';
        @exec($imagick_dir . self::get_ext_imagick_command() . ' -version', $returnarray);
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
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($library === null) {
            $conf_library = $conf['graphics_library'];
            $library = is_string($conf_library) ? $conf_library : 'auto';
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

// +-----------------------------------------------------------------------+
// |                   Class for Imagick extension                         |
// +-----------------------------------------------------------------------+

class image_imagick implements imageInterface
{
    /**
     * @var \Imagick
     */
    public $image;

    public function __construct(
        string $source_filepath
    ) {
        // A bug cause that Imagick class can not be extended
        $this->image = new Imagick($source_filepath);
    }

    public function get_width(): int
    {
        return $this->image->getImageWidth();
    }

    public function get_height(): int
    {
        return $this->image->getImageHeight();
    }

    public function set_compression_quality(int $quality): bool
    {
        return $this->image->setImageCompressionQuality($quality);
    }

    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        // Imagick::cropImage() requires int arguments — see image_gd's
        // crop() for why real callers pass floats here.
        return $this->image->cropImage((int) $width, (int) $height, (int) $x, (int) $y);
    }

    public function strip(): bool
    {
        return $this->image->stripImage();
    }

    public function rotate(int|float $rotation): bool
    {
        $this->image->rotateImage(new ImagickPixel(), -$rotation);
        $this->image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        return true;
    }

    public function resize(int|float $width, int|float $height): bool
    {
        $this->image->setInterlaceScheme(Imagick::INTERLACE_LINE);

        // TODO need to explain this condition
        if ($this->get_width() % 2 == 0
            && $this->get_height() % 2 == 0
            && $this->get_width() > 3 * $width) {
            $this->image->scaleImage($this->get_width() / 2, $this->get_height() / 2);
        }

        // Imagick::resizeImage() requires int columns/rows — see
        // image_gd's crop() for why real callers pass floats here.
        return $this->image->resizeImage((int) $width, (int) $height, Imagick::FILTER_LANCZOS, 0.9);
    }

    public function sharpen(int|float $amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);
        return $this->image->convolveImage($m);
    }

    public function compose(pwg_image $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        // compose() reaches into the overlay's own backend object to get
        // its raw Imagick instance — only valid when both images use the
        // same backend (always true in practice: i.php constructs both
        // via `new pwg_image(...)`, which resolves the backend from the
        // single $conf['graphics_library'] setting).
        $overlay_backend = $overlay->image;
        if (! $overlay_backend instanceof self) {
            throw new \LogicException('pwg_image::compose(): overlay must use the same image backend');
        }
        $ioverlay = $overlay_backend->image;
        /*if ($ioverlay->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_OPAQUE)
        {
          // Force the image to have an alpha channel
          $ioverlay->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
        }*/

        global $dirty_trick_xrepeat;
        if (! isset($dirty_trick_xrepeat) && $opacity < 100) {// NOTE: Using setImageOpacity will destroy current alpha channels!
            $ioverlay->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity / 100, Imagick::CHANNEL_ALPHA);
            $dirty_trick_xrepeat = true;
        }

        // Imagick::compositeImage() requires int x/y — see image_gd's
        // crop() for why real callers pass floats here.
        return $this->image->compositeImage($ioverlay, Imagick::COMPOSITE_DISSOLVE, (int) $x, (int) $y);
    }

    public function write(string $destination_filepath): bool
    {
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        $this->image->setSamplingFactors([2, 1]);
        return $this->image->writeImage($destination_filepath);
    }
}

// +-----------------------------------------------------------------------+
// |            Class for ImageMagick external installation                |
// +-----------------------------------------------------------------------+

class image_ext_imagick implements imageInterface
{
    public string $imagickdir = '';

    /**
     * @var int|float
     */
    public $width = 0;

    /**
     * @var int|float
     */
    public $height = 0;

    /**
     * @var bool
     */
    public $is_animated_webp = false;

    /**
     * @var array<string, int|float|string|null>
     */
    public $commands = [];

    public function __construct(
        public string $source_filepath
    ) {
        /** @var array<string, mixed> $conf */
        global $conf;
        $imagick_dir = $conf['ext_imagick_dir'];
        $this->imagickdir = is_string($imagick_dir) ? $imagick_dir : '';

        $script_filename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (is_string($script_filename) && str_starts_with($script_filename, '/kunden/')) {  // 1and1
            @putenv('MAGICK_THREAD_LIMIT=1');
        }

        if (strtolower(get_extension($this->source_filepath)) == 'webp') {
            $webp_info = pwg_image::webp_info($this->source_filepath);

            if ($webp_info['has-animation']) {
                $this->is_animated_webp = true;

                // ImageMagick "identify" returns the list of width x height for each
                // frame, such as "400x300400x300400x300" (3 frames of 400x300), as a big
                // string, impossible to parse :-/ So let's use the PHP embedded function
                // getimagesize here.
                $size = getimagesize($this->source_filepath);
                if ($size === false) {
                    throw new Exception("image_ext_imagick(): getimagesize({$this->source_filepath}): Failed");
                }
                [$this->width, $this->height] = $size;
                return;
            }
        }

        $command = $this->imagickdir . 'identify -format "%wx%h" "' . realpath($this->source_filepath) . '"';
        @exec($command, $returnarray);
        if (empty($returnarray[0]) or ! (bool) preg_match('/^(\d+)x(\d+)$/', $returnarray[0], $match)) {
            die("[External ImageMagick] Corrupt image\n" . var_export($returnarray, true));
        }

        $this->width = (int) $match[1];
        $this->height = (int) $match[2];
    }

    public function add_command(string $command, int|float|string|null $params = null): void
    {
        $this->commands[$command] = $params;
    }

    public function get_width(): int|float
    {
        return $this->width;
    }

    public function get_height(): int|float
    {
        return $this->height;
    }

    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        $this->width = $width;
        $this->height = $height;

        // the final "!" is added to crop the canva too, for animated picture (with WebP in mind)
        $this->add_command('crop', $width . 'x' . $height . '+' . $x . '+' . $y . '!');
        return true;
    }

    public function strip(): bool
    {
        $this->add_command('strip');
        return true;
    }

    public function rotate(int|float $rotation): bool
    {
        if (empty($rotation)) {
            return true;
        }

        if ($rotation == 90 || $rotation == 270) {
            $tmp = $this->width;
            $this->width = $this->height;
            $this->height = $tmp;
        }
        $this->add_command('rotate', -$rotation);
        $this->add_command('orient', 'top-left');
        return true;
    }

    public function set_compression_quality(int $quality): bool
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($this->is_animated_webp) {
            // in cas of animated WebP, we need to maximize quality to 70 to avoid
            // heavy thumbnails (or square or whatever is displayed on the thumbnails
            // page)
            $max_quality = $conf['animated_webp_compression_quality'];
            $max_quality = is_numeric($max_quality) ? (int) $max_quality : $quality;
            $quality = min($quality, $max_quality);
        }

        $this->add_command('quality', $quality);
        return true;
    }

    public function resize(int|float $width, int|float $height): bool
    {
        $this->width = $width;
        $this->height = $height;

        $this->add_command('filter', 'Lanczos');
        $this->add_command('resize', $width . 'x' . $height . '!');
        return true;
    }

    public function sharpen(int|float $amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);

        $param = 'convolve "' . count($m) . ':';
        foreach ($m as $line) {
            $param .= ' ';
            $param .= implode(',', $line);
        }
        $param .= '"';
        $this->add_command('morphology', $param);
        return true;
    }

    public function compose(pwg_image $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        // See image_imagick::compose()'s comment: only valid when both
        // images use the same backend, always true in practice.
        $overlay_backend = $overlay->image;
        if (! $overlay_backend instanceof self) {
            throw new \LogicException('pwg_image::compose(): overlay must use the same image backend');
        }
        $overlay_realpath = realpath($overlay_backend->source_filepath);
        if ($overlay_realpath === false) {
            throw new Exception("compose(): unable to resolve overlay path {$overlay_backend->source_filepath}");
        }

        $param = 'compose dissolve -define compose:args=' . $opacity;
        $param .= ' ' . escapeshellarg($overlay_realpath);
        $param .= ' -gravity NorthWest -geometry +' . $x . '+' . $y;
        $param .= ' -composite';
        $this->add_command($param);
        return true;
    }

    public function write(string $destination_filepath): bool
    {
        // Set unconditionally by i.php / include/common.inc.php before any
        // code in this file runs (both entry points construct it during
        // bootstrap) — not a lazy-init global.
        /** @var \Logger $logger */
        global $logger;

        $this->add_command('interlace', 'line'); // progressive rendering
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        //
        // option deactivated for Piwigo 2.4.1, it doesn't work fo old versions
        // of ImageMagick, see bug:2672. To reactivate once we have a better way
        // to detect IM version and when we know which version supports this
        // option
        //
        if (version_compare(pwg_image::$ext_imagick_version, '6.6') > 0) {
            $this->add_command('sampling-factor', '4:2:2');
        }

        $exec = $this->imagickdir . pwg_image::get_ext_imagick_command();
        $exec .= ' "' . realpath($this->source_filepath) . '"';

        // If the image is animated webp add a filter to avoid breaking the animation
        if ($this->is_animated_webp) {
            $exec .= ' -layers coalesce ';
        }

        foreach ($this->commands as $command => $params) {
            $exec .= ' -' . $command;
            if (! empty($params)) {
                $exec .= ' ' . $params;
            }
        }
        $dest = pathinfo($destination_filepath);
        if (! isset($dest['dirname'])) {
            throw new Exception("write(): unable to determine directory for {$destination_filepath}");
        }
        $exec .= ' "' . realpath($dest['dirname']) . '/' . $dest['basename'] . '" 2>&1';
        $logger->debug($exec, 'i.php');
        @exec($exec, $returnarray);

        if (count($returnarray) > 0) {
            $logger->error('', 'i.php', $returnarray);
            foreach ($returnarray as $line) {
                trigger_error($line, E_USER_WARNING);
            }
        }
        return true;
    }
}

// +-----------------------------------------------------------------------+
// |                       Class for GD library                            |
// +-----------------------------------------------------------------------+

class image_gd implements imageInterface
{
    public GdImage $image;

    public int $quality = 95;

    public function __construct(
        string $source_filepath
    ) {
        $gd_info = gd_info();
        $extension = strtolower(get_extension($source_filepath));

        if (in_array($extension, ['jpg', 'jpeg'])) {
            $image = imagecreatefromjpeg($source_filepath);
        } elseif ($extension == 'png') {
            $image = imagecreatefrompng($source_filepath);
        } elseif ($extension == 'gif' and $gd_info['GIF Read Support'] and $gd_info['GIF Create Support']) {
            $image = imagecreatefromgif($source_filepath);
        } else {
            die('[Image GD] unsupported file extension');
        }

        if ($image === false) {
            die('[Image GD] unable to decode image');
        }

        $this->image = $image;
    }

    public function get_width(): int
    {
        return imagesx($this->image);
    }

    public function get_height(): int
    {
        return imagesy($this->image);
    }

    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        // GD's native imagecreatetruecolor()/imagecopymerge() require int
        // arguments (unlike Imagick/ext_imagick, which tolerate float pixel
        // coordinates) — real callers do pass floats here (e.g. i.php's
        // $crop_rect->width()/->l are int|float, since ImageRect::crop_h()/
        // crop_v() accumulate floor()'s float return type), which threw a
        // TypeError under this backend before this cast was added (verified
        // directly: imagecreatetruecolor(900.0, ...) throws "must be of type
        // int, float given" under strict_types).
        $width = (int) $width;
        $height = (int) $height;
        $x = (int) $x;
        $y = (int) $y;

        // Image dimensions are always >= 1px for any real, successfully
        // decoded image (see the domain this class operates on — a 0 or
        // negative crop size is not a real scenario, not something to
        // silently clamp).
        assert($width > 0 && $height > 0);

        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopymerge($dest, $this->image, 0, 0, $x, $y, $width, $height, 100);

        $this->image = $dest;
        return $result;
    }

    public function strip(): bool
    {
        return true;
    }

    public function rotate(int|float $rotation): bool
    {
        $dest = imagerotate($this->image, $rotation, 0);
        if ($dest === false) {
            return false;
        }
        $this->image = $dest;
        return true;
    }

    public function set_compression_quality(int $quality): bool
    {
        $this->quality = $quality;
        return true;
    }

    public function resize(int|float $width, int|float $height): bool
    {
        // see crop()'s comment: GD's native functions require int arguments
        $width = (int) $width;
        $height = (int) $height;

        // see crop()'s comment: dimensions are always >= 1px in this domain
        assert($width > 0 && $height > 0);

        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $width, $height, $this->get_width(), $this->get_height());

        $this->image = $dest;
        return $result;
    }

    public function sharpen(int|float $amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);
        return imageconvolution($this->image, $m, 1, 0);
    }

    public function compose(pwg_image $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        // see crop()'s comment: GD's native imagecopy()/imagecopymerge()
        // require int arguments — real callers pass floats here too (i.php's
        // watermark positioning uses round(), which always returns float),
        // which threw a TypeError under this backend before this cast was
        // added.
        $x = (int) $x;
        $y = (int) $y;

        // See image_imagick::compose()'s comment: only valid when both
        // images use the same backend, always true in practice.
        $overlay_backend = $overlay->image;
        if (! $overlay_backend instanceof self) {
            throw new \LogicException('pwg_image::compose(): overlay must use the same image backend');
        }
        $ioverlay = $overlay_backend->image;
        /* A replacement for php's imagecopymerge() function that supports the alpha channel
        See php bug #23815:  http://bugs.php.net/bug.php?id=23815 */

        $ow = imagesx($ioverlay);
        $oh = imagesy($ioverlay);

        // Create a new blank image the site of our source image
        $cut = imagecreatetruecolor($ow, $oh);

        // Copy the blank image into the destination image where the source goes
        imagecopy($cut, $this->image, 0, 0, $x, $y, $ow, $oh);

        // Place the source image in the destination image
        imagecopy($cut, $ioverlay, 0, 0, 0, 0, $ow, $oh);
        // imagecopymerge()'s $pct is int; see crop()'s comment on float callers.
        imagecopymerge($this->image, $cut, $x, $y, 0, 0, $ow, $oh, (int) $opacity);
        return true;
    }

    public function write(string $destination_filepath): bool
    {
        $extension = strtolower(get_extension($destination_filepath));

        if ($extension == 'png') {
            return imagepng($this->image, $destination_filepath);
        }
        if ($extension == 'gif') {
            return imagegif($this->image, $destination_filepath);
        }
        return imagejpeg($this->image, $destination_filepath, $this->quality);
    }

    public function destroy(): bool
    {
        return true;
    }
}
