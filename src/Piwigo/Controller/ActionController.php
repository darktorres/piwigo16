<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Request\ActionRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Paths;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Lifecycle\LocActionBeforeHttpHeaders;
use Piwigo\History\HistoryService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The permission-checked original/representative/format-file download
 * handler. [SEC-33] The forbidden_categories/forbidden_images query below
 * is what closes the "anonymous reads a private album's original" attack
 * surface for this direct-download entry point (i.php's own
 * derivative-serving surface is separately, and only partially, closed --
 * see docs/PLAN.md's SEC master checklist, SEC-33).
 *
 * doError() and the 304 early-return both just return a ResponseInterface
 * (never exit()); every call site returns it in turn. There's no
 * Smarty rendering in this controller, so the whole method is flat,
 * always ending in a single ResponseFactory call on every path.
 *
 * The response body is read fully into a string via file_get_contents()
 * rather than streamed: ResponseEmitter::emit() calls
 * `echo $response->getBody()`, which fully materializes a
 * StreamInterface body into a string via __toString() anyway, so a
 * stream-backed body would buy nothing here.
 *
 * session_cache_limiter('public') deliberately stays in action.php's own
 * root file, not here: include/common.inc.php calls session_start()
 * directly in the root file's own top-level scope, before
 * RequestPipeline::handle() is even reached, so
 * calling session_cache_limiter() from inside this controller (invoked
 * much later, once the pipeline dispatches) would run after
 * session_start() already fired and have no effect.
 */
final readonly class ActionController implements ControllerInterface
{
    public function __construct(
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private CurrentUser $currentUser,
        private HistoryService $historyService,
        private PermissionService $permissionService,
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private Paths $paths,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $conn = DbConnection::build();

        $actionRequest = ActionRequest::fromGlobals($this->currentConfig->isFormatsEnabled, $this->inputValidator);

        $format = null;
        if ($actionRequest->formatRequested) {
            if ($actionRequest->formatId === null) {
                return $this->doError(400, 'Invalid request - format');
            }

            $format = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)
                ->findFormatById($actionRequest->formatId);

            if ($format === null) {
                return $this->doError(400, 'Invalid request - format');
            }

            $image_id = $format->imageId;
            $get_part = 'f'; // "f" for "format"
        } else {
            if ($actionRequest->id === null or $actionRequest->part === null) {
                return $this->doError(400, 'Invalid request - id/part');
            }

            $image_id = $actionRequest->id;
            $get_part = $actionRequest->part;
        }

        $elementImage = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)
            ->findById($image_id);
        if ($elementImage === null) {
            return $this->doError(404, 'Requested id not found');
        }
        // Mutated below (the 'f' case rewrites ['file']) -- unboxed here,
        // not kept as the typed object, same "unbox where genuinely
        // needed" shape as PluginLoader's own by-reference mutation.
        $element_info = $elementImage->toArray();

        // special download action for admins
        $is_admin_download = false;
        if ($this->accessControl->isAdmin() and $actionRequest->pwgToken === new CsrfService($this->currentConfig)->getToken()) {
            $is_admin_download = true;
            $this->currentUser->set($this->currentUser->get()->withEnabledHigh(true));
        }

        $src_image = new SrcImage($element_info);

        // $filter['visible_categories'] and $filter['visible_images']
        // are not used because it's not necessary (filter <> restriction)
        $permissionCriteria = $this->permissionService->getPermissionCriteria();
        if (
            ! $is_admin_download
            and ! $this->imageService
                ->isImageAccessibleViaCategoryWithCondition($image_id, $permissionCriteria)
        ) {
            return $this->doError(401, 'Access denied');
        }

        // $format is only set on the format-resolved path above; part=f is
        // reachable directly by request even when that path never ran.
        $format_row = $format;

        $file = '';
        switch ($get_part) {
            case 'e':
                if ($src_image->isOriginal() and ! $this->currentUser->get()->enabledHigh) {// we have a photo and the user has no access to HD
                    $deriv = new DerivativeImage(ImageStdParams::XXLARGE, $src_image, $this->currentConfig);
                    if (! $deriv->sameAsSource()) {
                        return $this->doError(401, 'Access denied e');
                    }
                }
                $file = ImagePathHelper::getElementPath($element_info, $this->urlService, $this->paths);
                break;
            case 'r':
                $representative_ext = $element_info['representative_ext'];
                // images.representative_ext is nullable in the schema
                // (only set when a custom representative image exists) --
                // a genuine missing value means there is no representative
                // file to serve.
                if (! is_string($representative_ext) || $representative_ext === '' || $representative_ext === '0') {
                    return $this->doError(404, 'Requested file not found');
                }
                $file = ImagePathHelper::originalToRepresentative(ImagePathHelper::getElementPath($element_info, $this->urlService, $this->paths), $representative_ext);
                break;
            case 'f':
                if ($format_row === null) {
                    return $this->doError(400, 'Invalid request - format');
                }
                $format_ext = $format_row->ext;
                $file = ImagePathHelper::originalToFormat(ImagePathHelper::getElementPath($element_info, $this->urlService, $this->paths), $format_ext);
                $original_file = $element_info['file'];
                $element_info['file'] = StringHelper::getFilenameWoExtension($original_file) . '.' . $format_ext;
                break;
        }

        if ($file === '') {
            return $this->doError(404, 'Requested file not found');
        }

        $image_id_val = $image_id->value;
        if ($get_part === 'e') {
            $this->historyService
                ->logVisit($image_id_val, 'high');
        } elseif ($get_part === 'r') {
            $this->historyService
                ->logVisit($image_id_val, 'other');
        } elseif ($get_part === 'f') {
            if ($format_row === null) {
                return $this->doError(400, 'Invalid request - format');
            }
            $this->historyService
                ->logVisit($image_id_val, 'high', $format_row->formatId);
        }

        $this->eventDispatcher->dispatchNotify(new LocActionBeforeHttpHeaders());

        $http_headers = [];

        $ctype = null;
        if (! $this->urlService->urlIsRemote($file)) {
            if (! @is_readable($file)) {
                return $this->doError(404, "Requested file not found - {$file}");
            }
            $http_headers['Content-Length'] = (string) @filesize($file);
            if (function_exists('mime_content_type')) {
                // Real bug fix: legacy assigned mime_content_type()'s
                // string|false result to $ctype directly, then checked
                // isset($ctype) below -- isset(false) is true, so a
                // failed lookup produced an empty "Content-Type: "
                // header instead of falling back to guessMimeType().
                // Narrowing false to null here restores that fallback.
                $mime = mime_content_type($file);
                $ctype = $mime !== false ? $mime : null;
            }

            $file_mtime = filemtime($file);
            // is_readable() was just checked above, so the file exists
            assert($file_mtime !== false);
            $gmt_mtime = gmdate('D, d M Y H:i:s', $file_mtime) . ' GMT';
            $http_headers['Last-Modified'] = $gmt_mtime;

            // following lines would indicate how the client should handle the cache
            /* $max_age=300;
            $http_headers['Expires'] = gmdate('D, d M Y H:i:s', time()+$max_age).' GMT';
            // HTTP/1.1 only
            $http_headers['Cache-Control'] = 'private, must-revalidate, max-age='.$max_age;*/

            if ($get_part !== 'f' and isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
                return ResponseFactory::raw('', $http_headers, 304);
            }
        }

        if ($ctype === null) { // give it a guess
            $ctype = $this->guessMimeType(StringHelper::getExtension($file));
        }

        $http_headers['Content-Type'] = $ctype;

        if ($actionRequest->downloadPresent) {
            $http_headers['Content-Disposition'] = 'attachment; filename="' . htmlspecialchars_decode($element_info['file']) . '";';
            $http_headers['Content-Transfer-Encoding'] = 'binary';
        } else {
            $http_headers['Content-Disposition'] = 'inline; filename="'
                      . basename($file) . '";';
        }

        // Looking at the safe_mode configuration for execution time
        if ((int) ini_get('safe_mode') === 0) {
            @set_time_limit(0);
        }

        $body = (string) @file_get_contents($file);

        return ResponseFactory::raw($body, $http_headers);
    }

    private function guessMimeType(string $ext): string
    {
        return match (strtolower($ext)) {
            'jpe', 'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tiff', 'tif' => 'image/tiff',
            'txt' => 'text/plain',
            'html', 'htm' => 'text/html',
            'xml' => 'text/xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'ogg' => 'application/ogg',
            default => 'application/octet-stream',
        };
    }

    private function doError(int $code, string $str): ResponseInterface
    {
        return ResponseFactory::text($str, $code);
    }
}
