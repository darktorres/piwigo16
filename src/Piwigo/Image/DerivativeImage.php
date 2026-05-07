<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Url\UrlService;

/**
 * Holds information (path, url, dimensions) about a derivative image.
 * A derivative image is constructed from a source image (SrcImage class)
 * and derivative parameters (DerivativeParams class).
 */
final class DerivativeImage
{
    private ?DerivativeParams $params = null;
    private string $rel_path = '';
    private string $rel_url = '';
    private ?bool $is_cached = true;

    /**
     * @param string|DerivativeParams $type standard derivative param type (e.g. IMG_*)
     *    or a DerivativeParams object
     * @param SrcImage $src_image the source image of this derivative
     */
    public function __construct(string|DerivativeParams $type, public readonly SrcImage $src_image)
    {
        if (is_string($type)) {
            $this->params = ImageStdParams::getByType($type);
        } else {
            $this->params = $type;
        }

        self::build($this->src_image, $this->params, $this->rel_path, $this->rel_url, $this->is_cached);
    }

    /**
     * Generates the url of a thumbnail.
     *
     * @param array|SrcImage $infos array of info from db or SrcImage
     * @return string
     */
    /**
     * @param array<mixed>|SrcImage $infos
     * @return string|array<mixed>
     */
    public static function thumbUrl(array|SrcImage $infos): string|array
    {
        return self::url(DerivativeSize::Thumb->value, $infos);
    }

    /**
     * Generates the url for a particular photo size.
     *
     * @param string|DerivativeParams $type standard derivative param type (e.g. IMG_*)
     *    or a DerivativeParams object
     * @param array|SrcImage $infos array of info from db or SrcImage
     * @return string
     */
    /**
     * @param array<mixed>|SrcImage $infos
     * @return string|array<mixed>
     */
    public static function url(string|DerivativeParams $type, array|SrcImage $infos): string|array
    {
        $src_image = is_object($infos) ? $infos : new SrcImage($infos);
        $params = is_string($type) ? ImageStdParams::getByType($type) : $type;
        $rel_path = '';
        $rel_url = '';
        self::build($src_image, $params, $rel_path, $rel_url);
        if ($params == null) {
            return $src_image->getUrl();
        }
        $urlArg = EventDispatcher::dispatch('get_derivative_url', UrlService::getRootUrl().$rel_url, $params, $src_image, $rel_url);
        return UrlService::embellishUrl($urlArg);
    }

    /**
     * Return associative an array of all DerivativeImage for a specific image.
     * Disabled derivative types can be still found in the return, mapped to an
     * enabled derivative (e.g. the values are not unique in the return array).
     * This is useful for any plugin/theme to just use $deriv[DerivativeSize::XLarge->value] even if
     * the XLARGE is disabled.
     *
     * @param array|SrcImage $src_image array of info from db or SrcImage
     * @return DerivativeImage[]
     */
    /**
     * @param array<mixed>|SrcImage $src_image
     * @return array<mixed>
     */
    public static function getAll(array|SrcImage $src_image): array
    {
        if (!is_object($src_image)) {
            $src_image = new SrcImage($src_image);
        }

        $ret = [];
        // build enabled types
        foreach (ImageStdParams::getDefinedTypeMap() as $type => $params) {
            $derivative = new DerivativeImage($params, $src_image);
            $ret[$type] = $derivative;
        }
        // disabled types, fallback to enabled types
        foreach (ImageStdParams::getUndefinedTypeMap() as $type => $type2) {
            $ret[$type] = $ret[$type2];
        }

        return $ret;
    }

    /**
     * Returns an instance of DerivativeImage for a specific image and size.
     * Disabled derivatives fallback to an enabled derivative.
     *
     * @param string $type standard derivative param type (e.g. IMG_*)
     * @param array|SrcImage $src_image array of info from db or SrcImage
     * @return DerivativeImage|null null if $type not found
     */
    /** @param array<mixed>|SrcImage $src_image */
    public static function getOne(string $type, array|SrcImage $src_image): ?DerivativeImage
    {
        if (!is_object($src_image)) {
            $src_image = new SrcImage($src_image);
        }

        $defined = ImageStdParams::getDefinedTypeMap();
        if (isset($defined[$type])) {
            return new DerivativeImage($defined[$type], $src_image);
        }

        $undefined = ImageStdParams::getUndefinedTypeMap();
        if (isset($undefined[$type])) {
            return new DerivativeImage($defined[ $undefined[$type] ], $src_image);
        }

        return null;
    }

    /**
     * @todo : documentation of DerivativeImage::build
     */
    private static function build(SrcImage $src, ?DerivativeParams &$params, string &$rel_path, string &$rel_url, ?bool &$is_cached = null): void
    {
        if (!($params instanceof DerivativeParams)) {
            $rel_path = $rel_url = $src->rel_path;
            return;
        }
        $srcSize = $src->getSize();
        if ($src->hasSize() && $srcSize !== null && $params->isIdentity($srcSize)) {// the source image is smaller than what we should do - we do not upsample
            if (!$params->willWatermark($srcSize) && !$src->rotation) {// no watermark, no rotation required -> we will use the source image
                $params = null;
                $rel_path = $rel_url = $src->rel_path;
                return;
            }
            $defined_types = array_keys(ImageStdParams::getDefinedTypeMap());
            for ($i = 0; $i < count($defined_types); $i++) {
                if ($defined_types[$i] == $params->type) {
                    for ($i--; $i >= 0; $i--) {
                        $smaller = ImageStdParams::getByType($defined_types[$i]);
                        if ($smaller->sizing->max_crop == $params->sizing->max_crop && $smaller->isIdentity($srcSize)) {
                            $params = $smaller;
                            self::build($src, $params, $rel_path, $rel_url, $is_cached);
                            return;
                        }
                    }
                    break;
                }
            }
        }

        $tokens = [];
        $tokens[] = substr((string) $params->type, 0, 2);

        if ($params->type == DerivativeSize::Custom->value) {
            $params->addUrlTokens($tokens);
        }

        $loc = $src->rel_path;
        if (substr_compare((string) $loc, './', 0, 2) == 0) {
            $loc = substr((string) $loc, 2);
        } elseif (substr_compare((string) $loc, '../', 0, 3) == 0) {
            $loc = substr((string) $loc, 3);
        }
        $dot_pos = strrpos((string) $loc, '.');
        if ($dot_pos !== false) {
            $loc = substr_replace($loc, '-'.implode('_', $tokens), $dot_pos, 0);
        }

        $rel_path = Config::derivativeDir().$loc;

        $url_style = Config::derivativeUrlStyle();
        if (!$url_style) {
            $mtime = Filesystem::tryFileMtime(PHPWG_ROOT_PATH.$rel_path);
            if ($mtime === false or $mtime < $params->last_mod_time) {
                $is_cached = false;
                $url_style = 2;
            } else {
                $url_style = 1;
            }
        }

        if ($url_style == 2) {
            $rel_url = 'index.php?/i/' . $loc;
        } else {
            $rel_url = $rel_path;
        }
    }

    public function getPath(): string
    {
        return PHPWG_ROOT_PATH.$this->rel_path;
    }

    /**
     * @return string
     */
    /** @return string|array<mixed> */
    public function getUrl(): string|array
    {
        if ($this->params == null) {
            return $this->src_image->getUrl();
        }
        $urlArg2 = EventDispatcher::dispatch('get_derivative_url', UrlService::getRootUrl().$this->rel_url, $this->params, $this->src_image, $this->rel_url);
        return UrlService::embellishUrl($urlArg2);
    }

    public function sameAsSource(): bool
    {
        return $this->params == null;
    }

    /**
     * @return string one if IMG_* or 'Original'
     */
    public function getType(): string
    {
        if ($this->params == null) {
            return 'Original';
        }
        return $this->params->type;
    }

    /**
     * @return int[]
     */
    /** @return array<int>|null */
    public function getSize(): ?array
    {
        if ($this->params == null) {
            return $this->src_image->getSize();
        }
        $srcSize = $this->src_image->getSize();
        if ($srcSize === null) {
            return null;
        }
        $floatSize = array_map(static fn (int $v): float => (float) $v, $srcSize);
        $result = $this->params->computeFinalSize($floatSize);
        return array_map(intval(...), $result);
    }

    /**
     * Returns the size as CSS rule.
     */
    public function getSizeCss(): string
    {
        $size = $this->getSize();
        if ($size) {
            return 'width:'.$size[0].'px; height:'.$size[1].'px';
        }
        return '';
    }

    /**
     * Returns the size as HTML attributes.
     */
    public function getSizeHtm(): string
    {
        $size = $this->getSize();
        if ($size) {
            return 'width="'.$size[0].'" height="'.$size[1].'"';
        }
        return '';
    }

    /**
     * Returns literal size: $widthx$height.
     */
    public function getSizeHr(): string
    {
        $size = $this->getSize();
        if ($size) {
            return $size[0].' x '.$size[1];
        }
        return '';
    }

    /**
     * @param int $maxw
     * @return int[]
     */
    /** @return array<int> */
    public function getScaledSize(int $maxw, int $maxh): array
    {
        $size = $this->getSize();
        if ($size) {
            $ratio_w = $size[0] / $maxw;
            $ratio_h = $size[1] / $maxh;
            if ($ratio_w > 1 || $ratio_h > 1) {
                if ($ratio_w > $ratio_h) {
                    $size[0] = $maxw;
                    $size[1] = (int) floor($size[1] / $ratio_w);
                } else {
                    $size[0] = (int) floor($size[0] / $ratio_h);
                    $size[1] = $maxh;
                }
            }
        }
        return $size ?? [];
    }

    /**
     * Returns the scaled size as HTML attributes.
     */
    public function getScaledSizeHtm(int $maxw = 9999, int $maxh = 9999): string
    {
        $size = $this->getScaledSize($maxw, $maxh);
        if ($size) {
            return 'width="'.$size[0].'" height="'.$size[1].'"';
        }
        return '';
    }

    public function isCached(): bool
    {
        return $this->is_cached ?? true;
    }
}
