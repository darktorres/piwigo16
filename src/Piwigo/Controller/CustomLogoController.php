<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Storage\StorageRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backs public/logo.php -- serves the standard_pages theme's custom logo
 * (Admin\ThemesStandardPagesPageRenderer's own upload feature) to anonymous
 * visitors, since it's shown pre-login on the identification/register/
 * password/profile pages.
 *
 * Deliberately takes no request parameters: it always serves *the* single,
 * currently-configured logo (Config's own 'standard_pages_selected_logo_path',
 * a path relative to the StorageRegistry 'local' disk), resolved entirely
 * server-side -- there's nothing for a client-supplied filename to do here,
 * which also closes off any path-traversal surface by construction.
 *
 * 'local/' itself is deliberately unreachable from public/ (Legacy Coupling
 * Retirement's web-root isolation work, closing SEC-33/35/38/47) -- this
 * controller is the one, intentionally-public exception, matching that same
 * workstream's own established pattern (ActionController/
 * ImageDerivativeController) of serving otherwise-unreachable filesystem
 * content through a real, permission-considered controller instead of a
 * static bridge.
 *
 * Same Last-Modified/304 shape as ActionController, but built on
 * StorageRegistry's Flysystem FilesystemOperator (fileExists()/mimeType()/
 * lastModified()/read()) instead of raw is_readable()/mime_content_type()/
 * filemtime() calls -- the 'local' disk this reads from is already a
 * Flysystem disk (Admin\ThemesStandardPagesPageRenderer already writes
 * through it), so there's no raw-filesystem-path concern to hand-roll here.
 */
final readonly class CustomLogoController implements ControllerInterface
{
    public function __construct(
        private AccessControl $accessControl,
        private StorageRegistry $storageRegistry,
        private CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $path = $this->currentConfig->standardPagesSelectedLogoPath;
        if (! is_string($path) || $path === '') {
            return ResponseFactory::text('Not found', 404);
        }

        $disk = $this->storageRegistry->get('local');
        if (! $disk->fileExists($path)) {
            return ResponseFactory::text('Not found', 404);
        }

        $gmt_mtime = gmdate('D, d M Y H:i:s', $disk->lastModified($path)) . ' GMT';

        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            return ResponseFactory::raw('', [
                'Last-Modified' => $gmt_mtime,
            ], 304);
        }

        return ResponseFactory::raw($disk->read($path), [
            'Content-Type' => $disk->mimeType($path),
            'Last-Modified' => $gmt_mtime,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }
}
