<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Lifecycle\LocActionBeforeHttpHeaders;
use Piwigo\History\HistoryService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces action.php -- the permission-checked original/representative/
 * format-file download handler. [SEC-33] served-path check: the
 * forbidden_categories/forbidden_images query below (unchanged from
 * legacy) is what actually closes the "anonymous reads a private album's
 * original" attack surface for this direct-download entry point (i.php's
 * own derivative-serving surface is separately, and only partially,
 * closed -- see docs/PLAN.md's SEC master checklist, SEC-33).
 *
 * Workstream C3b: doError()/the 304 early-return used to call exit()
 * directly (never return) -- simpler than the ResponseReadyException
 * mechanism the rest of C3 needed, since doError() is only ever called
 * from __invoke() itself (never a shared class like RedirectService/
 * HtmlService reached from many dispatch contexts): it just returns a
 * real ResponseInterface, and every call site returns it in turn. There's
 * no Template/Smarty rendering at all in this controller, so there's no
 * LegacyRenderCapture closure either: the whole method is flat, always
 * ending in a single ResponseFactory call on every path.
 *
 * The response body is read fully into a string via file_get_contents()
 * rather than streamed (legacy readfile()/ob_flush()/flush()) --
 * ResponseEmitter::emit() calls `echo $response->getBody()`, which fully
 * materializes a StreamInterface body into a string via __toString()
 * anyway, so a stream-backed body would buy nothing here; genuine
 * chunked streaming would need ResponseEmitter itself to change, out of
 * this phase's scope.
 *
 * session_cache_limiter('public') deliberately stays in action.php's own
 * root file, not here: include/common.inc.php calls session_start()
 * directly in the root file's own top-level scope, before
 * RequestPipeline::handle() is even reached, so
 * calling session_cache_limiter() from inside this controller (invoked
 * much later, once the pipeline dispatches) would run after
 * session_start() already fired and have no effect.
 */
final class ActionController implements ControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly HistoryService $historyService,
        private readonly \Piwigo\Permission\PermissionService $permissionService,
        private readonly \Piwigo\Image\ImageService $imageService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $conn = DbConnection::build();

        $actionRequest = Request\ActionRequest::fromGlobals(\Piwigo\Config\CurrentConfig::isFormatsEnabled());

        $format = null;
        if ($actionRequest->formatRequested) {
            if ($actionRequest->formatId === null) {
                return $this->doError(400, 'Invalid request - format');
            }

            $format = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
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

        $elementImage = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
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
        if (\Piwigo\Auth\AccessControl::isAdmin() and $actionRequest->pwgToken === new \Piwigo\Csrf\CsrfService()->getToken()) {
            $is_admin_download = true;
            $this->currentUser->set($this->currentUser->get()->withEnabledHigh(true));
        }

        $src_image = new SrcImage($element_info);

        // $filter['visible_categories'] and $filter['visible_images']
        // are not used because it's not necessary (filter <> restriction)
        $permissionCondition = $this->permissionService->getSqlConditionFandFAsCondition([
            'forbidden_categories' => 'category_id',
            'forbidden_images' => 'image_id',
        ]);
        if (
            ! $is_admin_download
            and ! $this->imageService
                ->isImageAccessibleViaCategoryWithCondition($image_id, $permissionCondition)
        ) {
            return $this->doError(401, 'Access denied');
        }

        // $format is only set on the format-resolved path above; part=f is
        // reachable directly by request even when that path never ran.
        $format_row = $format;

        $file = '';
        switch ($get_part) {
            case 'e':
                if ($src_image->is_original() and ! $this->currentUser->get()->enabledHigh) {// we have a photo and the user has no access to HD
                    $deriv = new DerivativeImage(ImageStdParams::XXLARGE, $src_image);
                    if (! $deriv->same_as_source()) {
                        return $this->doError(401, 'Access denied e');
                    }
                }
                $file = \Piwigo\Image\ImagePathHelper::getElementPath($element_info, $this->urlService);
                break;
            case 'r':
                $representative_ext = $element_info['representative_ext'] ?? null;
                // images.representative_ext is nullable in the schema
                // (only set when a custom representative image exists) --
                // a genuine missing value means there is no representative
                // file to serve.
                if (! is_string($representative_ext) || $representative_ext === '' || $representative_ext === '0') {
                    return $this->doError(404, 'Requested file not found');
                }
                $file = \Piwigo\Image\ImagePathHelper::originalToRepresentative(\Piwigo\Image\ImagePathHelper::getElementPath($element_info, $this->urlService), $representative_ext);
                break;
            case 'f':
                if ($format_row === null) {
                    return $this->doError(400, 'Invalid request - format');
                }
                $format_ext = $format_row->ext;
                $file = \Piwigo\Image\ImagePathHelper::originalToFormat(\Piwigo\Image\ImagePathHelper::getElementPath($element_info, $this->urlService), $format_ext);
                $original_file = $element_info['file'];
                $element_info['file'] = \Piwigo\Core\StringHelper::getFilenameWoExtension($original_file) . '.' . $format_ext;
                break;
        }

        if ($file === '') {
            return $this->doError(404, 'Requested file not found');
        }

        $image_id_val = $image_id;
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
            $ctype = $this->guessMimeType(\Piwigo\Core\StringHelper::getExtension($file));
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
