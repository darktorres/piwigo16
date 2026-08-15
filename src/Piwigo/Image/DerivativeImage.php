<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

use Exception;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\Event\GetDerivativeUrl;
use Piwigo\PluginConfig\EventDispatcher;
use RuntimeException;

/**
 * Holds information (path, url, dimensions) about a derivative image.
 * A derivative image is constructed from a source image (SrcImage class)
 * and derivative parameters (DerivativeParams class).
 *
 * urlService() resolves the container-shared UrlServiceInterface fresh on
 * every call rather than reading a bare-static value (see Image\SrcImage's
 * own docblock for why RootPathOverride's cross-instance sharing
 * requirement rules out a throwaway-construction alternative).
 */
final class DerivativeImage
{
    private ?DerivativeParams $params = null;

    private static function urlService(): UrlServiceInterface
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('DerivativeImage: no URL service set (RequestBootstrap not run yet?)');
        }
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new RuntimeException('DerivativeImage: no URL service set (RequestBootstrap not run yet?)');
        }

        return $urlService;
    }

    /**
     * Same "no DI, genuinely static factory API" shape as urlService()
     * above -- the class's own public API (thumbUrl()/url()/getAll()/
     * getOne()/build()) is almost entirely static factory methods with no
     * `$this` to inject through, so this stays a container resolve rather
     * than a constructor property, used consistently by both the static
     * factories and the constructor's own instance-context read below.
     */
    private static function imageStdParams(): ImageStdParams
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('DerivativeImage: no ImageStdParams set (RequestBootstrap not run yet?)');
        }
        $imageStdParams = Kernel::container()->get(ImageStdParams::class);
        if (! $imageStdParams instanceof ImageStdParams) {
            throw new RuntimeException('DerivativeImage: no ImageStdParams set (RequestBootstrap not run yet?)');
        }

        return $imageStdParams;
    }

    private static function eventDispatcher(): EventDispatcher
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('DerivativeImage: no EventDispatcher set (RequestBootstrap not run yet?)');
        }
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new RuntimeException('DerivativeImage: no EventDispatcher set (RequestBootstrap not run yet?)');
        }

        return $eventDispatcher;
    }

    /**
     * Same reasoning as urlService()/imageStdParams() above -- this
     * class's own constructor already takes a real CurrentConfig
     * ($this->currentConfig, for instance-context reads), but every
     * static factory method below has no `$this` to read it through, so
     * they resolve fresh from the container instead.
     */
    private static function currentConfig(): CurrentConfig
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('DerivativeImage: no CurrentConfig set (RequestBootstrap not run yet?)');
        }
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new RuntimeException('DerivativeImage: no CurrentConfig set (RequestBootstrap not run yet?)');
        }

        return $currentConfig;
    }

    /**
     * Same reasoning as currentConfig() above.
     */
    private static function paths(): Paths
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('DerivativeImage: no Paths set (RequestBootstrap not run yet?)');
        }
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new RuntimeException('DerivativeImage: no Paths set (RequestBootstrap not run yet?)');
        }

        return $paths;
    }

    private string $rel_path = '';

    private string $rel_url = '';

    private bool $is_cached = true;

    /**
     * @param string|DerivativeParams $type standard derivative param type (an
     *    ImageStdParams size-type constant) or a DerivativeParams object
     * @param SrcImage $src_image the source image of this derivative
     */
    public function __construct(
        string|DerivativeParams $type,
        public SrcImage $src_image,
        private readonly CurrentConfig $currentConfig,
        ?DerivativeUrlStyleOverride $urlStyleOverride = null,
    ) {
        if (is_string($type)) {
            $this->params = self::imageStdParams()->getByType($type);
        } else {
            $this->params = $type;
        }

        self::build($this->src_image, $this->currentConfig, $this->params, $this->rel_path, $this->rel_url, $this->is_cached, $urlStyleOverride);
    }

    /**
     * Generates the url of a thumbnail.
     *
     * @param array<string, mixed>|SrcImage $infos array of info from db or SrcImage -- see SrcImage::__construct()'s own docblock for why the array form stays generic
     */
    public static function thumbUrl(array|SrcImage $infos): string
    {
        return self::url(ImageStdParams::THUMB, $infos);
    }

    /**
     * Generates the url for a particular photo size.
     *
     * @param string|DerivativeParams $type standard derivative param type (an
     *    ImageStdParams size-type constant) or a DerivativeParams object
     * @param array<string, mixed>|SrcImage $infos array of info from db or SrcImage
     */
    public static function url(string|DerivativeParams $type, array|SrcImage $infos, ?DerivativeUrlStyleOverride $urlStyleOverride = null): string
    {
        $src_image = is_object($infos) ? $infos : new SrcImage($infos);
        $params = is_string($type) ? self::imageStdParams()->getByType($type) : $type;
        $rel_path = '';
        $rel_url = '';
        self::build($src_image, self::currentConfig(), $params, $rel_path, $rel_url, urlStyleOverride: $urlStyleOverride);
        if (! $params instanceof DerivativeParams) {
            return $src_image->getUrl();
        }
        $default_url = self::urlService()->getRootUrl() . $rel_url;
        $filtered_url = self::eventDispatcher()->dispatchChange(new GetDerivativeUrl(
            $default_url,
            $params,
            $src_image,
            $rel_url
        ))->url;

        return self::urlService()->embellishUrl($filtered_url);
    }

    /**
     * Return associative an array of all DerivativeImage for a specific image.
     * Disabled derivative types can be still found in the return, mapped to an
     * enabled derivative (e.g. the values are not unique in the return array).
     * This is useful for any plugin/theme to just use $deriv[ImageStdParams::XLARGE] even if
     * the XLARGE is disabled.
     *
     * @param array<string, mixed>|SrcImage $src_image array of info from db or SrcImage
     * @return DerivativeImage[]
     */
    public static function getAll(array|SrcImage $src_image): array
    {
        if (! is_object($src_image)) {
            $src_image = new SrcImage($src_image);
        }

        $ret = [];
        // build enabled types
        foreach (self::imageStdParams()->getDefinedTypeMap() as $type => $params) {
            $derivative = new self($params, $src_image, self::currentConfig());
            $ret[$type] = $derivative;
        }
        // disabled types, fallback to enabled types
        foreach (self::imageStdParams()->getUndefinedTypeMap() as $type => $type2) {
            $ret[$type] = $ret[$type2];
        }

        return $ret;
    }

    /**
     * Returns an instance of DerivativeImage for a specific image and size.
     * Disabled derivatives fallback to an enabled derivative.
     *
     * @param string $type standard derivative param type (an ImageStdParams
     *    size-type constant)
     * @param array<string, mixed>|SrcImage $src_image array of info from db or SrcImage
     * @return DerivativeImage|null null if $type not found
     */
    public static function getOne(string $type, array|SrcImage $src_image): ?self
    {
        if (! is_object($src_image)) {
            $src_image = new SrcImage($src_image);
        }

        $defined = self::imageStdParams()->getDefinedTypeMap();
        if (isset($defined[$type])) {
            return new self($defined[$type], $src_image, self::currentConfig());
        }

        $undefined = self::imageStdParams()->getUndefinedTypeMap();
        if (isset($undefined[$type])) {
            return new self($defined[$undefined[$type]], $src_image, self::currentConfig());
        }

        return null;
    }

    /**
     * @todo : documentation of DerivativeImage::build
     * @param ?DerivativeParams $params by-ref: may be reassigned to null (source used as-is) or a smaller defined type
     * @param string $rel_path by-ref out-param
     * @param string $rel_url by-ref out-param
     * @param bool $is_cached by-ref out-param; not bound to a real variable when omitted (uses its default)
     */
    private static function build(SrcImage $src, CurrentConfig $currentConfig, ?DerivativeParams &$params, string &$rel_path, string &$rel_url, bool &$is_cached = false, ?DerivativeUrlStyleOverride $urlStyleOverride = null): void
    {
        // every real call site (the constructor, url(), and this method's
        // own recursive call below) passes a freshly-resolved, non-null
        // DerivativeParams; it's only ever reassigned to null as an
        // out-param, below.
        assert($params instanceof DerivativeParams);

        if ($src->hasSize()) {
            $src_size = $src->getSize();
            // hasSize() checks the same underlying state getSize() would
            // otherwise recompute, so a true hasSize() guarantees getSize()
            // returns non-null here.
            assert($src_size !== null);
            if ($params->isIdentity($src_size)) {// the source image is smaller than what we should do - we do not upsample
                if (! $params->willWatermark($src_size, self::imageStdParams()) && ! (bool) $src->rotation) {// no watermark, no rotation required -> we will use the source image
                    $params = null;
                    $rel_path = $rel_url = $src->rel_path;
                    return;
                }
                $defined_types = array_keys(self::imageStdParams()->getDefinedTypeMap());
                for ($i = 0; $i < count($defined_types); $i++) {
                    if ($defined_types[$i] === $params->type) {
                        for ($i--; $i >= 0; $i--) {
                            $smaller = self::imageStdParams()->getByType($defined_types[$i]);
                            if ($smaller->sizing->max_crop === $params->sizing->max_crop && $smaller->isIdentity($src_size)) {
                                $params = $smaller;
                                self::build($src, $currentConfig, $params, $rel_path, $rel_url, $is_cached, $urlStyleOverride);
                                return;
                            }
                        }
                        break;
                    }
                }
            }
        }

        $tokens = [];
        $tokens[] = substr($params->type, 0, 2);

        if ($params->type === ImageStdParams::CUSTOM) {
            $params->addUrlTokens($tokens);
        }

        $loc = $src->rel_path;
        if (substr_compare($loc, './', 0, 2) === 0) {
            $loc = substr($loc, 2);
        } elseif (substr_compare($loc, '../', 0, 3) === 0) {
            $loc = substr($loc, 3);
        }
        $dot = strrpos($loc, '.');
        if ($dot === false) {
            throw new Exception("DerivativeImage::build(): path '{$loc}' has no extension");
        }
        $loc = substr_replace($loc, '-' . implode('_', $tokens), $dot, 0);

        $rel_path = $currentConfig->derivativeDir . $loc;

        $url_style = $urlStyleOverride->derivativeUrlStyle ?? $currentConfig->derivativeUrlStyle;
        if (! (bool) $url_style) {
            $abs_path = self::paths()->root . $rel_path;
            $mtime = file_exists($abs_path) ? filemtime($abs_path) : false;
            if ($mtime === false or $mtime < $params->last_mod_time) {
                $is_cached = false;
                $url_style = 2;
            } else {
                $url_style = 1;
            }
        }

        if ($url_style === 2) {
            $rel_url = 'i';
            if ($urlStyleOverride->phpExtensionInUrls ?? $currentConfig->phpExtensionInUrls) {
                $rel_url .= '.php';
            }
            if ($urlStyleOverride->questionMarkInUrls ?? $currentConfig->questionMarkInUrls) {
                $rel_url .= '?';
            }
            $rel_url .= '/' . $loc;
        } else {
            $rel_url = $rel_path;
        }
    }

    public function getPath(): string
    {
        return self::paths()->root . $this->rel_path;
    }

    public function getUrl(): string
    {
        if (! $this->params instanceof DerivativeParams) {
            return $this->src_image->getUrl();
        }
        $default_url = self::urlService()->getRootUrl() . $this->rel_url;
        $filtered_url = self::eventDispatcher()->dispatchChange(new GetDerivativeUrl(
            $default_url,
            $this->params,
            $this->src_image,
            $this->rel_url
        ))->url;

        return self::urlService()->embellishUrl($filtered_url);
    }

    public function sameAsSource(): bool
    {
        return ! $this->params instanceof DerivativeParams;
    }

    /**
     * @return string one of the ImageStdParams size-type constants or 'Original'
     */
    public function getType(): string
    {
        if (! $this->params instanceof DerivativeParams) {
            return 'Original';
        }
        return $this->params->type;
    }

    /**
     * @return int[]|null null if the source image's own size failed to compute
     */
    public function getSize(): ?array
    {
        $src_size = $this->src_image->getSize();
        if (! $this->params instanceof DerivativeParams || $src_size === null) {
            return $src_size;
        }
        return $this->params->computeFinalSize($src_size);
    }

    /**
     * Returns the size as CSS rule.
     */
    public function getSizeCss(): string
    {
        $size = $this->getSize();
        if ((bool) $size) {
            return 'width:' . $size[0] . 'px; height:' . $size[1] . 'px';
        }

        return '';
    }

    /**
     * Returns the size as HTML attributes.
     */
    public function getSizeHtm(): string
    {
        $size = $this->getSize();
        if ((bool) $size) {
            return 'width="' . $size[0] . '" height="' . $size[1] . '"';
        }

        return '';
    }

    /**
     * Returns literal size: $widthx$height.
     */
    public function getSizeHr(): string
    {
        $size = $this->getSize();
        if ((bool) $size) {
            return $size[0] . ' x ' . $size[1];
        }

        return '';
    }

    /**
     * @return int[]|null
     */
    public function getScaledSize(int $maxw, int $maxh): ?array
    {
        $size = $this->getSize();
        if ((bool) $size) {
            $ratio_w = (float) $size[0] / (float) $maxw;
            $ratio_h = (float) $size[1] / (float) $maxh;
            if ($ratio_w > 1 || $ratio_h > 1) {
                if ($ratio_w > $ratio_h) {
                    $size[0] = $maxw;
                    $size[1] = (int) floor((float) $size[1] / $ratio_w);
                } else {
                    $size[0] = (int) floor((float) $size[0] / $ratio_h);
                    $size[1] = $maxh;
                }
            }
        }
        return $size;
    }

    /**
     * Returns the scaled size as HTML attributes.
     */
    public function getScaledSizeHtm(int $maxw = 9999, int $maxh = 9999): string
    {
        $size = $this->getScaledSize($maxw, $maxh);
        if ((bool) $size) {
            return 'width="' . $size[0] . '" height="' . $size[1] . '"';
        }

        return '';
    }

    public function isCached(): bool
    {
        return $this->is_cached;
    }
}
