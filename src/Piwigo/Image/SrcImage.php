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
use Piwigo\Core\CurrentThemeConfProvider;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Picture\GetMimetypeLocation;
use Piwigo\Image\Event\GetSrcImageUrl;
use Piwigo\PluginConfig\EventDispatcher;
use RuntimeException;

/**
 * A source image is used to get a derivative image. It is either
 * the original file for a jpg/png/... or a 'representative' image
 * of a  non image file or a standard icon for the non-image file.
 *
 * This class can't take its collaborators (htmlRenderer, themeConfProvider,
 * imageRepository, urlService) via constructor injection (~20 real
 * construction sites, all raw `new SrcImage($infos)` from DB rows, no DI
 * involved) or depend on `Piwigo\Url\UrlService`/`Piwigo\Template\Template`
 * directly (deptrac: this is L2aCoreDomain), so each collaborator method
 * resolves fresh from the container on every call rather than caching in
 * a static property -- avoiding the state-leak risk (SEC-60) a static
 * would carry across requests in worker mode.
 * `HtmlRenderingInterface`/`UrlServiceInterface`/`ImageRepository`
 * are all bound/autowirable in `config/container.php`, so this is
 * a live, always-current read, not a cache. `themeConf()` resolves
 * `Piwigo\Core\CurrentThemeConfProvider` (a new, dedicated container-
 * shared wrapper, not `Piwigo\Template\CurrentTemplate`) -- see that
 * wrapper's own docblock for why delegating straight to `CurrentTemplate`
 * would silently reintroduce the exact upward L3Presentation coupling
 * `ThemeConfProviderInterface` exists to prevent.
 */
final class SrcImage
{
    private static function fatalError(string $msg): never
    {
        $htmlRenderer = Kernel::isBooted() ? Kernel::container()->get(HtmlRenderingInterface::class) : null;
        if ($htmlRenderer instanceof HtmlRenderingInterface) {
            $htmlRenderer->fatalError($msg);
        }
        throw new RuntimeException($msg);
    }

    private static function themeConf(string $key): string
    {
        return CurrentThemeConfProvider::current()->get()->themeConf($key);
    }

    private static function urlService(): UrlServiceInterface
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('SrcImage: no URL service set (RequestBootstrap not run yet?)');
        }
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new RuntimeException('SrcImage: no URL service set (RequestBootstrap not run yet?)');
        }

        return $urlService;
    }

    /**
     * Same "~20 real raw `new SrcImage($infos)` construction sites, no DI
     * involved" constraint as the other collaborator methods above rules
     * out constructor injection for CurrentConfig too -- resolves fresh
     * from the container on every call, same as urlService()/
     * imageRepository().
     */
    private static function currentConfig(): CurrentConfig
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('SrcImage: no CurrentConfig set (RequestBootstrap not run yet?)');
        }
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new RuntimeException('SrcImage: no CurrentConfig set (RequestBootstrap not run yet?)');
        }

        return $currentConfig;
    }

    /**
     * Same "~20 real raw `new SrcImage($infos)` construction sites, no DI
     * involved" constraint as the other collaborator methods here --
     * resolves fresh from the container on every call.
     */
    private static function paths(): Paths
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('SrcImage: no Paths set (RequestBootstrap not run yet?)');
        }
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new RuntimeException('SrcImage: no Paths set (RequestBootstrap not run yet?)');
        }

        return $paths;
    }

    /**
     * Same "~20 real raw `new SrcImage($infos)` construction sites, no DI
     * involved" constraint as the other collaborator methods above --
     * resolves fresh from the container on every call.
     */
    private static function eventDispatcher(): EventDispatcher
    {
        if (! Kernel::isBooted()) {
            throw new RuntimeException('SrcImage: no EventDispatcher set (RequestBootstrap not run yet?)');
        }
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        if (! $eventDispatcher instanceof EventDispatcher) {
            throw new RuntimeException('SrcImage: no EventDispatcher set (RequestBootstrap not run yet?)');
        }

        return $eventDispatcher;
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
     * @var int[]|null
     */
    private ?array $size = null;

    private int $flags = 0;

    /**
     * $infos is genuinely cross-domain by design, confirmed by tracing its
     * ~17 real construction sites: full `images` table rows (Ws\WsHelper,
     * Controller\ActionController), but also category-listing rows
     * (Category\CategoryCatsRenderer's own tree-cache-row shape) and
     * upload-pipeline rows (Admin\Upload\UploadService) that merely
     * happen to carry a subset of the same key names. Every key read
     * below is already isset()/is_*()-guarded with a safe fallback, so a
     * caller's row missing any of them degrades gracefully rather than
     * erroring -- the same cross-domain-generic-row-reader shape as
     * Category\CategoryService::compareByGlobalRank().
     *
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
        $ext = strtolower(StringHelper::getExtension($path));
        $infos['file_ext'] = @strtolower(StringHelper::getExtension($file));
        $infos['path_ext'] = $ext;
        // representative_ext is a nullable DB column; empty()'s silent
        // handling of a missing/non-string key is preserved via `?? null`.
        $representative_ext_raw = $infos['representative_ext'] ?? null;
        $representative_ext = is_string($representative_ext_raw) ? $representative_ext_raw : '';
        // \Piwigo\Config\CurrentConfig::pictureExtensions() is always a string[] set by config_default.inc.php.
        $picture_ext = self::currentConfig()->pictureExtensions();
        if (in_array($ext, $picture_ext, true)) {
            $this->rel_path = $path;
            $this->flags |= self::IS_ORIGINAL;
        } elseif ($representative_ext !== '') {
            $this->rel_path = ImagePathHelper::originalToRepresentative($path, $representative_ext);
        } else {
            $default_mimetype_location = self::themeConf('mime_icon_dir') . $ext . '.png';
            $this->rel_path = self::eventDispatcher()->dispatchChange(new GetMimetypeLocation($default_mimetype_location, $ext))->location;
            $this->flags |= self::IS_MIMETYPE;
            $mimetype_abs_path = self::paths()->root . $this->rel_path;
            $size = file_exists($mimetype_abs_path) ? getimagesize($mimetype_abs_path) : false;
            if ($size === false) {
                if ($ext === 'svg') {
                    $this->rel_path = $path;
                } else {
                    $this->rel_path = 'themes/default/icon/mimetypes/unknown.png';
                }
                $fallback_abs_path = self::paths()->root . $this->rel_path;
                $size = file_exists($fallback_abs_path) ? getimagesize($fallback_abs_path) : false;
                if ($size === false) {
                    throw new Exception('SrcImage: unable to read size of fallback icon ' . $this->rel_path);
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
        return self::paths()->root . $this->rel_path;
    }

    public function get_url(): string
    {
        if ($this->is_mimetype()) {
            // A static theme asset (e.g. themes/default/icon/mimetypes/
            // pdf.png), not user content -- themes/ is a real, deliberately
            // reachable symlink into public/ (Part II), so a direct static
            // link is correct and safe here, unlike the two branches below.
            $url = self::urlService()->getRootUrl() . $this->rel_path;
        } else {
            // Part II (web-root isolation): a direct static link into
            // upload/ (the original) was SEC-33/35/38/47's own root cause
            // for originals, not just derivatives -- upload/ is
            // deliberately unreachable now, and a raw link here also
            // bypassed ActionController's own HD-access check (part=e)
            // entirely, a real, separate bug found live (a real picture
            // page's <img> was broken -- decoded+diffed the Visual
            // Regression failure rather than dismissing it as a flake).
            // ActionController (routed action.php, config/routes.php)
            // already re-checks both permission and HD-access on every
            // request; UrlService::getActionUrl() is the same helper
            // Admin\PictureModifyPageRenderer/BatchManagerUnitPageRenderer
            // already use for their own action.php download links.
            $part = $this->is_original() ? 'e' : 'r';
            $url = self::urlService()->getActionUrl($this->id, $part, false);

            $url = self::eventDispatcher()->dispatchChange(new GetSrcImageUrl($url, $this))->url;
        }

        return self::urlService()->embellishUrl($url);
    }

    public function has_size(): bool
    {
        return $this->size !== null;
    }

    /**
     * @return int[]|null 0=width, 1=height or null if fail to compute size
     */
    public function get_size(): ?array
    {
        if ($this->size === null) {
            if ((bool) ($this->flags & self::DIM_NOT_GIVEN)) {
                self::fatalError('SrcImage dimensions required but not provided');
            }
            // probably not metadata synced
            if (($size = getimagesize($this->get_path())) !== false) {
                $this->size = [$size[0], $size[1]];
                if (Kernel::isBooted()) {
                    $imageRepository = Kernel::container()->get(ImageRepository::class);
                    if ($imageRepository instanceof ImageRepository) {
                        $imageRepository->updateDimensions($this->id, $size[0], $size[1]);
                    }
                }
            }
        }
        return $this->size;
    }
}
