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
    public function get_width();

    public function get_height();

    public function set_compression_quality($quality);

    public function crop($width, $height, $x, $y);

    public function strip();

    public function rotate($rotation);

    public function resize($width, $height);

    public function sharpen($amount);

    public function compose($overlay, $x, $y, $opacity);

    public function write($destination_filepath);
}

// +-----------------------------------------------------------------------+
// |                          Main Image Class                             |
// +-----------------------------------------------------------------------+

/**
 * Unknown methods are forwarded to $this->image (an imageInterface
 * implementor) via __call(). These @method tags mirror that interface.
 *
 * @method int get_width()
 * @method int get_height()
 * @method bool set_compression_quality($quality)
 * @method bool crop($width, $height, $x, $y)
 * @method bool strip()
 * @method bool rotate($rotation)
 * @method bool resize($width, $height)
 * @method bool sharpen($amount)
 * @method bool compose($overlay, $x, $y, $opacity)
 * @method void write($destination_filepath)
 */
class pwg_image
{
    /**
     * @var object
     */
    public $image;

    public $library = '';

    public static $ext_imagick_version = '';

    public function __construct(
        public $source_filepath,
        $library = null
    ) {
        global $conf;

        trigger_notify('load_image_library', [&$this]);

        if (is_object($this->image)) {
            return; // A plugin may have load its own library
        }

        $extension = strtolower(get_extension($this->source_filepath));

        if (! in_array($extension, $conf['picture_ext'])) {
            die('[Image] unsupported file extension');
        }

        if (! ($this->library = self::get_library($library, $extension))) {
            die('No image library available on your server.');
        }

        $class = 'image_' . $this->library;
        $this->image = new $class($this->source_filepath);
    }

    // Unknow methods will be redirected to image object
    public function __call(string $method, array $arguments)
    {
        return call_user_func_array([$this->image, $method], $arguments);
    }

    // Piwigo resize function
    /**
     * @return mixed[]
     */
    public function pwg_resize($destination_filepath, $max_width, $max_height, $quality, $automatic_rotation = true, $strip_metadata = false, $crop = false, $follow_orientation = true): array
    {
        $starttime = get_moment();

        // width/height
        $source_width = $this->image->get_width();
        $source_height = $this->image->get_height();

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

        $this->image->set_compression_quality($quality);

        if ($strip_metadata) {
            // we save a few kilobytes. For example a thumbnail with metadata weights 25KB, without metadata 7KB.
            $this->image->strip();
        }

        if (isset($resize_dimensions['crop'])) {
            $this->image->crop($resize_dimensions['crop']['width'], $resize_dimensions['crop']['height'], $resize_dimensions['crop']['x'], $resize_dimensions['crop']['y']);
        }

        $this->image->resize($resize_dimensions['width'], $resize_dimensions['height']);

        if (! empty($rotation)) {
            $this->image->rotate($rotation);
        }

        $this->image->write($destination_filepath);

        // everything should be OK if we are here!
        return $this->get_resize_result($destination_filepath, $resize_dimensions['width'], $resize_dimensions['height'], $starttime);
    }

    public static function get_resize_dimensions($width, $height, $max_width, $max_height, $rotation = null, $crop = false, $follow_orientation = true): array
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

    public static function webp_info($source_filepath): array
    {
        // function based on https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php
        //
        // https://github.com/webmproject/libwebp/blob/master/src/dec/webp_dec.c
        // https://developers.google.com/speed/webp/docs/riff_container
        // https://developers.google.com/speed/webp/docs/webp_lossless_bitstream_specification
        // https://stackoverflow.com/questions/61221874/detect-if-a-webp-image-is-transparent-in-php

        $fp = fopen($source_filepath, 'rb');
        if (! $fp) {
            throw new Exception("webp_info(): fopen({$f}): Failed");
        }
        $buf = fread($fp, 25);
        fclose($fp);

        switch (true) {
            case ! is_string($buf):
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
                    'has-transparent' => (bool) ((bool) (ord($buf[24]) & 0x00000010)),
                ];

            case $buf[15] == 'X':
                // Extended File Format
                return [
                    'type' => 'VP8X',
                    'has-animation' => (bool) ((bool) (ord($buf[20]) & 0x00000002)),
                    'has-transparent' => (bool) ((bool) (ord($buf[20]) & 0x00000010)),
                ];

            default:
                throw new Exception('webp_info(): could not detect webp type');
        }
    }

    public static function get_rotation_angle($source_filepath): ?int
    {
        [$width, $height, $type] = getimagesize($source_filepath);
        if ($type != IMAGETYPE_JPEG) {
            return null;
        }

        if (! function_exists('exif_read_data')) {
            return null;
        }

        $rotation = 0;

        $exif = @exif_read_data($source_filepath);

        if (isset($exif['Orientation']) and preg_match('/^\s*(\d)/', $exif['Orientation'], $matches)) {
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

    public static function get_rotation_code_from_angle($rotation_angle)
    {
        switch ($rotation_angle) {
            case 0:   return 0;
            case 90:  return 1;
            case 180: return 2;
            case 270: return 3;
        }
    }

    public static function get_rotation_angle_from_code($rotation_code)
    {
        switch ($rotation_code % 4) {
            case 0: return 0;
            case 1: return 90;
            case 2: return 180;
            case 3: return 270;
        }
    }

    /**
     * Returns a normalized convolution kernel for sharpening
     */
    public static function get_sharpen_matrix($amount): array
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

    protected function get_resize_result($destination_filepath, $width, $height, $time = null): array
    {
        return [
            'source' => $this->source_filepath,
            'destination' => $destination_filepath,
            'width' => $width,
            'height' => $height,
            'size' => floor(filesize($destination_filepath) / 1024) . ' KB',
            'time' => $time ? number_format((get_moment() - $time) * 1000, 2, '.', ' ') . ' ms' : null,
            'library' => $this->library,
        ];
    }

    public static function is_imagick(): bool
    {
        return extension_loaded('imagick') and class_exists('Imagick');
    }

    public static function get_ext_imagick_command()
    {
        global $page, $conf;

        if (! isset($page['ext_imagick_command'])) {
            $retval = null;
            $cmd_out = null;
            // check if magick is in path
            exec('command -v ' . $conf['ext_imagick_dir'] . 'magick', $cmd_out, $retval);
            if ($retval == 0) {
                $page['ext_imagick_command'] = 'magick';
            } else {
                $page['ext_imagick_command'] = 'convert';
            }
        }

        return $page['ext_imagick_command'];
    }

    public static function is_ext_imagick(): bool
    {
        global $conf;

        if (! function_exists('exec')) {
            return false;
        }

        @exec($conf['ext_imagick_dir'] . self::get_ext_imagick_command() . ' -version', $returnarray);
        if (is_array($returnarray) and ! empty($returnarray[0]) and preg_match('/ImageMagick/i', $returnarray[0])) {
            if (preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
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

    public static function get_library($library = null, $extension = null)
    {
        global $conf;

        if ($library === null) {
            $library = $conf['graphics_library'];
        }

        // Choose image library
        switch (strtolower((string) $library)) {
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

    public function destroy()
    {
        if (method_exists($this->image, 'destroy')) {
            return $this->image->destroy();
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
        $source_filepath
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

    public function set_compression_quality($quality): bool
    {
        return $this->image->setImageCompressionQuality($quality);
    }

    public function crop($width, $height, $x, $y): bool
    {
        return $this->image->cropImage($width, $height, $x, $y);
    }

    public function strip(): bool
    {
        return $this->image->stripImage();
    }

    public function rotate($rotation): bool
    {
        $this->image->rotateImage(new ImagickPixel(), -$rotation);
        $this->image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        return true;
    }

    public function resize($width, $height): bool
    {
        $this->image->setInterlaceScheme(Imagick::INTERLACE_LINE);

        // TODO need to explain this condition
        if ($this->get_width() % 2 == 0
            && $this->get_height() % 2 == 0
            && $this->get_width() > 3 * $width) {
            $this->image->scaleImage($this->get_width() / 2, $this->get_height() / 2);
        }

        return $this->image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 0.9);
    }

    public function sharpen($amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);
        return $this->image->convolveImage($m);
    }

    public function compose($overlay, $x, $y, $opacity): bool
    {
        $ioverlay = $overlay->image->image;
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

        return $this->image->compositeImage($ioverlay, Imagick::COMPOSITE_DISSOLVE, $x, $y);
    }

    public function write($destination_filepath): bool
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
    public $imagickdir = '';

    /**
     * @var int|string
     */
    public $width = '';

    /**
     * @var int|string
     */
    public $height = '';

    /**
     * @var bool
     */
    public $is_animated_webp = false;

    public $commands = [];

    public function __construct(
        public $source_filepath
    ) {
        global $conf;
        $this->imagickdir = $conf['ext_imagick_dir'];

        if (str_starts_with((string) @$_SERVER['SCRIPT_FILENAME'], '/kunden/')) {  // 1and1
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
                [$this->width, $this->height] = getimagesize($this->source_filepath);
                return;
            }
        }

        $command = $this->imagickdir . 'identify -format "%wx%h" "' . realpath($this->source_filepath) . '"';
        @exec($command, $returnarray);
        if (! is_array($returnarray) or empty($returnarray[0]) or ! preg_match('/^(\d+)x(\d+)$/', $returnarray[0], $match)) {
            die("[External ImageMagick] Corrupt image\n" . var_export($returnarray, true));
        }

        $this->width = $match[1];
        $this->height = $match[2];
    }

    public function add_command($command, $params = null): void
    {
        $this->commands[$command] = $params;
    }

    public function get_width()
    {
        return $this->width;
    }

    public function get_height()
    {
        return $this->height;
    }

    public function crop($width, $height, $x, $y): bool
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

    public function rotate($rotation): bool
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

    public function set_compression_quality($quality): bool
    {
        global $conf;

        if ($this->is_animated_webp) {
            // in cas of animated WebP, we need to maximize quality to 70 to avoid
            // heavy thumbnails (or square or whatever is displayed on the thumbnails
            // page)
            $quality = min($quality, $conf['animated_webp_compression_quality']);
        }

        $this->add_command('quality', $quality);
        return true;
    }

    public function resize($width, $height): bool
    {
        $this->width = $width;
        $this->height = $height;

        $this->add_command('filter', 'Lanczos');
        $this->add_command('resize', $width . 'x' . $height . '!');
        return true;
    }

    public function sharpen($amount): bool
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

    public function compose($overlay, $x, $y, $opacity): bool
    {
        $param = 'compose dissolve -define compose:args=' . $opacity;
        $param .= ' ' . escapeshellarg(realpath($overlay->image->source_filepath));
        $param .= ' -gravity NorthWest -geometry +' . $x . '+' . $y;
        $param .= ' -composite';
        $this->add_command($param);
        return true;
    }

    public function write($destination_filepath): bool
    {
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
        $dest = pathinfo((string) $destination_filepath);
        $exec .= ' "' . realpath($dest['dirname']) . '/' . $dest['basename'] . '" 2>&1';
        $logger->debug($exec, 'i.php');
        @exec($exec, $returnarray);

        if (is_array($returnarray) && (count($returnarray) > 0)) {
            $logger->error('', 'i.php', $returnarray);
            foreach ($returnarray as $line) {
                trigger_error($line, E_USER_WARNING);
            }
        }
        return is_array($returnarray);
    }
}

// +-----------------------------------------------------------------------+
// |                       Class for GD library                            |
// +-----------------------------------------------------------------------+

class image_gd implements imageInterface
{
    public $image;

    public $quality = 95;

    public function __construct(
        $source_filepath
    ) {
        $gd_info = gd_info();
        $extension = strtolower(get_extension($source_filepath));

        if (in_array($extension, ['jpg', 'jpeg'])) {
            $this->image = imagecreatefromjpeg($source_filepath);
        } elseif ($extension == 'png') {
            $this->image = imagecreatefrompng($source_filepath);
        } elseif ($extension == 'gif' and $gd_info['GIF Read Support'] and $gd_info['GIF Create Support']) {
            $this->image = imagecreatefromgif($source_filepath);
        } else {
            die('[Image GD] unsupported file extension');
        }
    }

    public function get_width(): int
    {
        return imagesx($this->image);
    }

    public function get_height(): int
    {
        return imagesy($this->image);
    }

    public function crop($width, $height, $x, $y)
    {
        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopymerge($dest, $this->image, 0, 0, $x, $y, $width, $height, 100);

        if ($result !== false) {
            $this->image = $dest;
        } else {
        }
        return $result;
    }

    public function strip(): bool
    {
        return true;
    }

    public function rotate($rotation): bool
    {
        $dest = imagerotate($this->image, $rotation, 0);
        $this->image = $dest;
        return true;
    }

    public function set_compression_quality($quality): bool
    {
        $this->quality = $quality;
        return true;
    }

    public function resize($width, $height)
    {
        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $width, $height, $this->get_width(), $this->get_height());

        if ($result !== false) {
            $this->image = $dest;
        } else {
        }
        return $result;
    }

    public function sharpen($amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);
        return imageconvolution($this->image, $m, 1, 0);
    }

    public function compose($overlay, $x, $y, $opacity): bool
    {
        $ioverlay = $overlay->image->image;
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
        imagecopymerge($this->image, $cut, $x, $y, 0, 0, $ow, $oh, $opacity);
        return true;
    }

    public function write($destination_filepath): void
    {
        $extension = strtolower(get_extension($destination_filepath));

        if ($extension == 'png') {
            imagepng($this->image, $destination_filepath);
        } elseif ($extension == 'gif') {
            imagegif($this->image, $destination_filepath);
        } else {
            imagejpeg($this->image, $destination_filepath, $this->quality);
        }
    }

    public function destroy() {}
}
