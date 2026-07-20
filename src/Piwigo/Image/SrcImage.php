<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\ThemeConfProviderInterface;
use Piwigo\Core\UrlServiceInterface;

/**
 * A source image is used to get a derivative image. It is either
 * the original file for a jpg/png/... or a 'representative' image
 * of a  non image file or a standard icon for the non-image file.
 */
final class SrcImage
{
    private static ?HtmlRenderingInterface $htmlRenderer = null;

    /**
     * Set once by include/common.inc.php (legacy, not subject to deptrac) --
     * same static-setter shape as Piwigo\Core\Lang::setDefaultLanguageProvider().
     * P23 batch 8f-3: get_size()'s fatal_error() call sits behind a rare
     * edge case (dimensions genuinely never provided); this class has ~20
     * real construction sites and get_size() itself is called transitively
     * through DerivativeImage's own many callers, so constructor/
     * per-method injection would ripple unreasonably for a rarely-hit path
     * -- same reasoning as Validation\InputValidator.
     */
    public static function setHtmlRenderer(HtmlRenderingInterface $renderer): void
    {
        self::$htmlRenderer = $renderer;
    }

    private static function fatalError(string $msg): never
    {
        if (self::$htmlRenderer instanceof \Piwigo\Core\HtmlRenderingInterface) {
            self::$htmlRenderer->fatalError($msg);
        }
        throw new \RuntimeException($msg);
    }

    private static ?ThemeConfProviderInterface $themeConfProvider = null;

    /**
     * P23 batch 8f-4: set once by include/common.inc.php right after the
     * request's $template instance is constructed (Template implements
     * ThemeConfProviderInterface) -- same static-setter shape as
     * setHtmlRenderer() above, and for the same reason: this
     * L2aCoreDomain class may not depend on L3Presentation's Template
     * directly (deptrac), and its ~20 real construction sites make
     * constructor injection an unreasonable ripple. Replaces the deleted
     * get_themeconf() free function's own `$GLOBALS['template']` read,
     * with the same availability window (the provider exists as soon as
     * the template does; no SrcImage is ever constructed earlier in a
     * real request).
     */
    public static function setThemeConfProvider(ThemeConfProviderInterface $provider): void
    {
        self::$themeConfProvider = $provider;
    }

    private static function themeConf(string $key): string
    {
        if (! self::$themeConfProvider instanceof \Piwigo\Core\ThemeConfProviderInterface) {
            throw new \RuntimeException('SrcImage: no theme-conf provider set (Template not constructed yet?)');
        }

        return self::$themeConfProvider->themeConf($key);
    }

    private static ?ImageRepository $imageRepository = null;

    /**
     * Set once by include/common.inc.php (legacy, not subject to deptrac) --
     * same static-setter shape as setHtmlRenderer()/setThemeConfProvider()
     * above, for the same reason: get_size()'s width/height write-back
     * (Legacy Coupling Retirement: DI+DBAL migration, Phase 1b) sits behind
     * a rare edge case (dimensions not yet metadata-synced), and this
     * class's ~20 real construction sites make constructor injection an
     * unreasonable ripple.
     */
    public static function setImageRepository(ImageRepository $repo): void
    {
        self::$imageRepository = $repo;
    }

    private static ?UrlServiceInterface $urlService = null;

    /**
     * Set once by Bootstrap\RequestBootstrap (Legacy Coupling Retirement
     * Phase 4c) -- same static-setter shape as setHtmlRenderer()/
     * setThemeConfProvider()/setImageRepository() above, for the same
     * reason: this L2aCoreDomain class may not depend on
     * Piwigo\Url\UrlService (L2bExtendedDomain) directly (deptrac), and
     * its ~20 real construction sites make constructor injection an
     * unreasonable ripple.
     */
    public static function setUrlService(UrlServiceInterface $urlService): void
    {
        self::$urlService = $urlService;
    }

    private static function urlService(): UrlServiceInterface
    {
        if (! self::$urlService instanceof UrlServiceInterface) {
            throw new \RuntimeException('SrcImage: no URL service set (RequestBootstrap not run yet?)');
        }

        return self::$urlService;
    }

    public const int IS_ORIGINAL = 0x01;

    public const int IS_MIMETYPE = 0x02;

    public const int DIM_NOT_GIVEN = 0x04;

    /**
     * @var int
     */
    public $id;

    /**
     * @var string
     */
    public $rel_path;

    /**
     * @var int
     */
    public $rotation = 0;

    /**
     * @var int[]
     */
    private ?array $size = null;

    private int $flags = 0;

    /**
     * @param array<string, mixed> $infos assoc array of data from images table
     */
    public function __construct(
        array $infos
    ) {

        // images.id/.path/.file are all NOT NULL DB columns, but every
        // element read back from a DB row is string|null per this driver
        // (no MYSQLI_OPT_INT_AND_FLOAT_NATIVE); narrow explicitly rather
        // than trusting the schema at the type-check level.
        $this->id = is_numeric($infos['id']) ? (int) $infos['id'] : 0;
        $path = is_string($infos['path']) ? $infos['path'] : '';
        $file = is_string($infos['file']) ? $infos['file'] : null;
        $ext = strtolower(\Piwigo\Core\StringHelper::getExtension($path));
        $infos['file_ext'] = @strtolower(\Piwigo\Core\StringHelper::getExtension($file));
        $infos['path_ext'] = $ext;
        // representative_ext is a nullable DB column; empty()'s silent
        // handling of a missing/non-string key is preserved via `?? null`.
        $representative_ext_raw = $infos['representative_ext'] ?? null;
        $representative_ext = is_string($representative_ext_raw) ? $representative_ext_raw : '';
        // \Piwigo\Config\Config::pictureExtensions() is always a string[] set by config_default.inc.php.
        $picture_ext = is_array(\Piwigo\Config\Config::pictureExtensions()) ? \Piwigo\Config\Config::pictureExtensions() : [];
        if (in_array($ext, $picture_ext)) {
            $this->rel_path = $path;
            $this->flags |= self::IS_ORIGINAL;
        } elseif (! empty($representative_ext)) {
            $this->rel_path = \Piwigo\Image\ImagePathHelper::originalToRepresentative($path, $representative_ext);
        } else {
            $default_mimetype_location = self::themeConf('mime_icon_dir') . $ext . '.png';
            $mimetype_location = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_mimetype_location', $default_mimetype_location, $ext);
            // trigger_change() hands the value through arbitrary registered
            // event handlers (mixed return); fall back to the pre-filter
            // location if a misbehaving handler returns a non-string.
            $this->rel_path = is_string($mimetype_location) ? $mimetype_location : $default_mimetype_location;
            $this->flags |= self::IS_MIMETYPE;
            if (($size = @getimagesize(PHPWG_ROOT_PATH . $this->rel_path)) === false) {
                if ($ext == 'svg') {
                    $this->rel_path = $path;
                } else {
                    $this->rel_path = 'themes/default/icon/mimetypes/unknown.png';
                }
                $size = getimagesize(PHPWG_ROOT_PATH . $this->rel_path);
                if ($size === false) {
                    throw new \Exception('SrcImage: unable to read size of fallback icon ' . $this->rel_path);
                }
            }
            $this->size = [$size[0], $size[1]];
        }

        if (! (bool) $this->size) {
            if (isset($infos['width']) && isset($infos['height'])) {
                $width = is_numeric($infos['width']) ? (int) $infos['width'] : 0;
                $height = is_numeric($infos['height']) ? (int) $infos['height'] : 0;

                $rotation_raw = $infos['rotation'] ?? null;
                $this->rotation = is_numeric($rotation_raw) ? intval($rotation_raw) % 4 : 0;
                // 1 or 5 =>  90 clockwise
                // 3 or 7 => 270 clockwise
                if ((bool) ($this->rotation % 2)) {
                    [$width, $height] = [$height, $width];
                }

                $this->size = [$width, $height];
            } elseif (! array_key_exists('width', $infos)) {
                $this->flags |= self::DIM_NOT_GIVEN;
            }
        }
    }

    public function is_original(): bool
    {
        return (bool) ($this->flags & self::IS_ORIGINAL);
    }

    public function is_mimetype(): bool
    {
        return (bool) ($this->flags & self::IS_MIMETYPE);
    }

    public function get_path(): string
    {
        return PHPWG_ROOT_PATH . $this->rel_path;
    }

    public function get_url(): string
    {
        $url = self::urlService()->getRootUrl() . $this->rel_path;
        if (! (bool) ($this->flags & self::IS_MIMETYPE)) {
            $filtered_url = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_src_image_url', $url, $this);
            // trigger_change() hands the value through arbitrary registered
            // event handlers (mixed return); fall back to the pre-filter
            // url if a misbehaving handler returns a non-string.
            $url = is_string($filtered_url) ? $filtered_url : $url;
        }
        return self::urlService()->embellishUrl($url);
    }

    public function has_size(): bool
    {
        return $this->size != null;
    }

    /**
     * @return int[]|null 0=width, 1=height or null if fail to compute size
     */
    public function get_size(): ?array
    {
        if ($this->size == null) {
            if ((bool) ($this->flags & self::DIM_NOT_GIVEN)) {
                self::fatalError('SrcImage dimensions required but not provided');
            }
            // probably not metadata synced
            if (($size = getimagesize($this->get_path())) !== false) {
                $this->size = [$size[0], $size[1]];
                self::$imageRepository?->updateDimensions($this->id, $size[0], $size[1]);
            }
        }
        return $this->size;
    }
}
